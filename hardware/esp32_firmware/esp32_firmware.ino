/*
 * RespiroSync - ESP32 gateway firmware
 *
 * - Reads DHT22, Sharp GP2Y1014AU0F (dust) and MQ-135 every 5 s
 * - Receives cough detections from the Raspberry Pi Pico over UART2
 * - Publishes to   respirosync/devices/<token>/telemetry and /events
 * - Subscribes to  respirosync/devices/<token>/config   (retained thresholds)
 *                  respirosync/devices/<token>/commands (factory_reset, buzzer_on, buzzer_off)
 * - Sounds the local alarm when a reading crosses its threshold, even while offline
 *
 * Setup: copy secrets.example.h to secrets.h and fill it in. Pairing token is
 * entered on the "RespiroSync-Setup" Wi-Fi portal.
 * Tested to compile on arduino-esp32 core 2.0.x (ESP-IDF 4.4); IDF 5 (core 3.x) branch included.
 */
#include <WiFi.h>
#include <WiFiManager.h>  // https://github.com/tzapu/WiFiManager
#include <EEPROM.h>
#include <ArduinoJson.h>
#include "mqtt_client.h"  // ESP-IDF MQTT client (WebSockets + TLS)
#include "esp_crt_bundle.h"
#include <DHT.h>
#include <Wire.h>
#include <LiquidCrystal_I2C.h>  // https://github.com/johnrickman/LiquidCrystal_I2C
#include "secrets.h"            // MQTT_URI, MQTT_USERNAME, MQTT_PASSWORD

// Works with ArduinoJson 6.x and 7.x
#if ARDUINOJSON_VERSION_MAJOR >= 7
typedef JsonDocument Doc;
#else
typedef StaticJsonDocument<256> Doc;
#endif

// --- Pins ---
#define DHTPIN 4
#define DHTTYPE DHT22
#define MQ135PIN 34
#define DUST_LED_PIN 5
#define DUST_OUT_PIN 35
#define BUZZER_PIN 18
#define GREEN_LED_PIN 19
#define RED_LED_PIN 23
#define PICO_RX_PIN 16
#define PICO_TX_PIN 17

// The Sharp sensor runs on 5 V, so its output goes through a 10k/20k divider
// (see WIRING_GUIDE.md). Voltage at the sensor = voltage at the pin * 1.5.
const float DUST_DIVIDER_RATIO = 1.5f;

DHT dht(DHTPIN, DHTTYPE);
LiquidCrystal_I2C lcd(0x27, 16, 2);  // try 0x3F if the screen stays blank

// --- Identity / MQTT ---
char device_token[7] = "";
char topicTelemetry[48], topicEvents[48], topicConfig[48], topicCommands[48];
esp_mqtt_client_handle_t mqtt_client;
volatile bool mqtt_connected = false;
bool shouldSaveConfig = false;

// --- Thresholds (defaults until the retained config arrives) ---
struct Thresholds {
  float pm25 = 35.0f;
  float temperature = 35.0f;
  float humidity = 60.0f;
  float mq135 = 300.0f;
  bool muted = false;
};
Thresholds thresholds;
portMUX_TYPE thresholdsMux = portMUX_INITIALIZER_UNLOCKED;

// Commands are queued by the MQTT task and executed in loop()
enum PendingCommand { CMD_NONE, CMD_FACTORY_RESET, CMD_BUZZER_ON, CMD_BUZZER_OFF };
volatile PendingCommand pendingCommand = CMD_NONE;
bool cloudAlarm = false;

void saveConfigCallback() { shouldSaveConfig = true; }

bool isValidToken(const char *t) {
  if (strlen(t) != 6) return false;
  for (int i = 0; i < 6; i++) {
    if (!isalnum((unsigned char)t[i])) return false;
  }
  return true;
}

void buildTopics() {
  snprintf(topicTelemetry, sizeof(topicTelemetry), "respirosync/devices/%s/telemetry", device_token);
  snprintf(topicEvents, sizeof(topicEvents), "respirosync/devices/%s/events", device_token);
  snprintf(topicConfig, sizeof(topicConfig), "respirosync/devices/%s/config", device_token);
  snprintf(topicCommands, sizeof(topicCommands), "respirosync/devices/%s/commands", device_token);
}

// Runs in the MQTT task: parse only, never touch the LCD or GPIO here.
static void mqtt_event_handler(void *handler_args, esp_event_base_t base, int32_t event_id, void *event_data) {
  esp_mqtt_event_handle_t event = (esp_mqtt_event_handle_t)event_data;
  switch ((esp_mqtt_event_id_t)event_id) {
    case MQTT_EVENT_CONNECTED:
      mqtt_connected = true;
      esp_mqtt_client_subscribe(mqtt_client, topicConfig, 1);
      esp_mqtt_client_subscribe(mqtt_client, topicCommands, 1);
      Serial.println("MQTT connected; subscribed to config + commands");
      break;

    case MQTT_EVENT_DISCONNECTED:
      mqtt_connected = false;
      Serial.println("MQTT disconnected (client will retry)");
      break;

    case MQTT_EVENT_DATA: {
      if (event->data_len == 0) break;  // cleared retained message
      Doc doc;
      if (deserializeJson(doc, event->data, event->data_len)) break;

      bool isConfig = event->topic_len == (int)strlen(topicConfig) && strncmp(event->topic, topicConfig, event->topic_len) == 0;
      if (isConfig) {
        Thresholds t;
        portENTER_CRITICAL(&thresholdsMux);
        t = thresholds;
        portEXIT_CRITICAL(&thresholdsMux);
        t.pm25 = doc["pm25_threshold"] | t.pm25;
        t.temperature = doc["temperature_threshold"] | t.temperature;
        t.humidity = doc["humidity_threshold"] | t.humidity;
        t.mq135 = doc["mq135_threshold"] | t.mq135;
        t.muted = doc["is_buzzer_muted"] | t.muted;
        portENTER_CRITICAL(&thresholdsMux);
        thresholds = t;
        portEXIT_CRITICAL(&thresholdsMux);
        Serial.println("Thresholds updated from cloud");
      } else {
        const char *cmd = doc["command"] | "";
        if (strcmp(cmd, "factory_reset") == 0) pendingCommand = CMD_FACTORY_RESET;
        else if (strcmp(cmd, "buzzer_on") == 0) pendingCommand = CMD_BUZZER_ON;
        else if (strcmp(cmd, "buzzer_off") == 0) pendingCommand = CMD_BUZZER_OFF;
      }
      break;
    }

    case MQTT_EVENT_ERROR:
      Serial.println("MQTT error");
      break;

    default:
      break;
  }
}

void publishJson(const char *topic, Doc &doc) {
  if (!mqtt_connected) return;
  char buffer[256];
  size_t n = serializeJson(doc, buffer, sizeof(buffer));
  esp_mqtt_client_publish(mqtt_client, topic, buffer, n, 1, 0);
}

// --- Sharp GP2Y1014AU0F, returns ug/m3 ---
float readDustSensor() {
  digitalWrite(DUST_LED_PIN, LOW);  // LED on (active low)
  delayMicroseconds(280);
  uint32_t mv = analogReadMilliVolts(DUST_OUT_PIN);
  delayMicroseconds(40);
  digitalWrite(DUST_LED_PIN, HIGH);
  delayMicroseconds(9680);

  float sensorVolts = (mv / 1000.0f) * DUST_DIVIDER_RATIO;
  // Chris Nafis (2012): density [mg/m3] = 0.17 * V - 0.1  ->  [ug/m3] = 170 * V - 100
  float density = 170.0f * sensorVolts - 100.0f;
  return density < 0 ? 0 : density;
}

void factoryReset() {
  Serial.println("Factory reset: wiping token and Wi-Fi settings");
  lcd.clear();
  lcd.print("Factory reset...");
  for (int i = 0; i < 7; i++) EEPROM.write(i, 0);
  EEPROM.commit();
  WiFiManager wm;
  wm.resetSettings();
  delay(1000);
  ESP.restart();
}

void setup() {
  Serial.begin(115200);
  EEPROM.begin(512);

  pinMode(DUST_LED_PIN, OUTPUT);
  pinMode(BUZZER_PIN, OUTPUT);
  pinMode(GREEN_LED_PIN, OUTPUT);
  pinMode(RED_LED_PIN, OUTPUT);
  digitalWrite(DUST_LED_PIN, HIGH);
  digitalWrite(BUZZER_PIN, LOW);
  digitalWrite(GREEN_LED_PIN, LOW);
  digitalWrite(RED_LED_PIN, HIGH);  // red until connected

  Wire.begin(21, 22);
  lcd.init();
  lcd.backlight();
  lcd.print("RespiroSync Boot");

  dht.begin();
  Serial2.begin(9600, SERIAL_8N1, PICO_RX_PIN, PICO_TX_PIN);
  Serial2.setTimeout(50);

  for (int i = 0; i < 6; i++) device_token[i] = (char)EEPROM.read(i);
  device_token[6] = '\0';

  WiFiManager wm;
  WiFiManagerParameter custom_token("token", "Setup Token (from Web Dashboard)", isValidToken(device_token) ? device_token : "", 6);
  wm.addParameter(&custom_token);
  wm.setSaveConfigCallback(saveConfigCallback);

  if (!isValidToken(device_token)) {
    Serial.println("No valid token stored: opening setup portal");
    lcd.setCursor(0, 1);
    lcd.print("WiFi: Setup mode");
    wm.resetSettings();
  }

  if (!wm.autoConnect("RespiroSync-Setup")) {
    Serial.println("Wi-Fi setup timed out, restarting");
    delay(3000);
    ESP.restart();
  }

  if (shouldSaveConfig) {
    String newToken = custom_token.getValue();
    newToken.trim();
    newToken.toUpperCase();
    if (isValidToken(newToken.c_str())) {
      for (int i = 0; i < 6; i++) EEPROM.write(i, newToken[i]);
      EEPROM.write(6, 0);
      EEPROM.commit();
      strncpy(device_token, newToken.c_str(), sizeof(device_token));
    }
  }
  if (!isValidToken(device_token)) {
    Serial.println("Invalid token entered: restarting into setup");
    WiFiManager().resetSettings();
    delay(1000);
    ESP.restart();
  }

  buildTopics();
  Serial.printf("Device token %s\n", device_token);

  esp_mqtt_client_config_t mqtt_cfg = {};
#if ESP_IDF_VERSION >= ESP_IDF_VERSION_VAL(5, 0, 0)
  mqtt_cfg.broker.address.uri = MQTT_URI;
  mqtt_cfg.broker.verification.crt_bundle_attach = esp_crt_bundle_attach;
  mqtt_cfg.credentials.username = MQTT_USERNAME;
  mqtt_cfg.credentials.authentication.password = MQTT_PASSWORD;
  mqtt_cfg.credentials.client_id = device_token;  // the ACL pins this client to its own topics
#else
  mqtt_cfg.uri = MQTT_URI;
  mqtt_cfg.crt_bundle_attach = esp_crt_bundle_attach;  // verify Cloudflare's certificate
  mqtt_cfg.username = MQTT_USERNAME;
  mqtt_cfg.password = MQTT_PASSWORD;
  mqtt_cfg.client_id = device_token;
#endif

  mqtt_client = esp_mqtt_client_init(&mqtt_cfg);
  esp_mqtt_client_register_event(mqtt_client, (esp_mqtt_event_id_t)ESP_EVENT_ANY_ID, mqtt_event_handler, NULL);
  esp_mqtt_client_start(mqtt_client);

  lcd.clear();
  lcd.print("WiFi Connected!");
}

unsigned long lastTelemetryMillis = 0;
unsigned long coughDisplayUntil = 0;
bool envAlarm = false;
char alarmReason[17] = "";

void handlePicoMessage() {
  if (!Serial2.available()) return;
  String msg = Serial2.readStringUntil('\n');
  msg.trim();
  if (!msg.startsWith("COUGH:")) return;

  // Format: COUGH:<level>,<strength>
  int comma = msg.indexOf(',');
  int level = msg.substring(6, comma > 0 ? comma : msg.length()).toInt();
  float strength = comma > 0 ? msg.substring(comma + 1).toFloat() : -1;

  Doc doc;
  doc["event"] = "cough";
  doc["level"] = level;
  if (strength >= 0) doc["confidence"] = strength;  // heuristic detection strength 0..1
  publishJson(topicEvents, doc);

  Serial.printf("Cough from Pico: level %d, strength %.2f\n", level, strength);
  lcd.clear();
  lcd.print("Cough Detected!");
  coughDisplayUntil = millis() + 2000;

  digitalWrite(BUZZER_PIN, HIGH);  // short acknowledgement chirp
  delay(80);
  digitalWrite(BUZZER_PIN, LOW);
}

void readAndPublishTelemetry() {
  float hum = dht.readHumidity();
  float temp = dht.readTemperature();
  float pm25 = readDustSensor();
  int mq135 = analogRead(MQ135PIN);

  Doc doc;
  doc["pm25_level"] = pm25;
  doc["mq135_level"] = mq135;
  if (isnan(temp) || isnan(hum)) {
    Serial.println("DHT22 read failed: sending null");
    doc["temperature"] = (const char *)nullptr;  // JSON null
    doc["humidity"] = (const char *)nullptr;
  } else {
    doc["temperature"] = temp;
    doc["humidity"] = hum;
  }
  publishJson(topicTelemetry, doc);

  Thresholds t;
  portENTER_CRITICAL(&thresholdsMux);
  t = thresholds;
  portEXIT_CRITICAL(&thresholdsMux);

  alarmReason[0] = '\0';
  if (pm25 > t.pm25) strcpy(alarmReason, "PM2.5 high");
  else if (mq135 > t.mq135) strcpy(alarmReason, "Gas high");
  else if (!isnan(temp) && temp > t.temperature) strcpy(alarmReason, "Too hot");
  else if (!isnan(hum) && hum > t.humidity) strcpy(alarmReason, "Too humid");
  envAlarm = alarmReason[0] != '\0';

  if (millis() < coughDisplayUntil) return;
  lcd.clear();
  lcd.setCursor(0, 0);
  lcd.print("PM2.5:");
  lcd.print(pm25, 1);
  lcd.print(mqtt_connected ? " ON" : " OFF");
  lcd.setCursor(0, 1);
  if (envAlarm) {
    lcd.print("! ");
    lcd.print(alarmReason);
  } else if (isnan(temp)) {
    lcd.print("Temp: sensor err");
  } else {
    lcd.print("Temp:");
    lcd.print(temp, 1);
    lcd.print("C ");
    lcd.print(hum, 0);
    lcd.print("%");
  }
}

void updateOutputs() {
  bool muted;
  portENTER_CRITICAL(&thresholdsMux);
  muted = thresholds.muted;
  portEXIT_CRITICAL(&thresholdsMux);

  bool alarm = envAlarm || cloudAlarm;
  digitalWrite(RED_LED_PIN, alarm || !mqtt_connected);
  digitalWrite(GREEN_LED_PIN, !alarm && mqtt_connected);

  // Beep 150 ms every 2 s while an alarm is active, unless muted from the dashboard.
  bool beep = alarm && !muted && (millis() % 2000) < 150;
  if (millis() > coughDisplayUntil) digitalWrite(BUZZER_PIN, beep);
}

void loop() {
  PendingCommand cmd = pendingCommand;
  if (cmd != CMD_NONE) {
    pendingCommand = CMD_NONE;
    if (cmd == CMD_FACTORY_RESET) factoryReset();
    cloudAlarm = (cmd == CMD_BUZZER_ON);
  }

  handlePicoMessage();

  if (millis() - lastTelemetryMillis >= 5000) {
    lastTelemetryMillis = millis();
    readAndPublishTelemetry();
  }

  updateOutputs();
  delay(10);
}
