#include <WiFi.h>
#include <WiFiManager.h> // https://github.com/tzapu/WiFiManager
#include <EEPROM.h>
#include <ArduinoJson.h>
#include "mqtt_client.h" // Native ESP-IDF MQTT Client (Supports WebSockets & SSL)
#include <esp_crt_bundle.h> // Include root certificates for Cloudflare SSL
#include <DHT.h>

// --- Sensors (Adjust pins for your setup) ---
#define DHTPIN 4
#define DHTTYPE DHT22
DHT dht(DHTPIN, DHTTYPE);

#define MQ135PIN 34
#define DUST_LED_PIN 5
#define DUST_OUT_PIN 35
#define BUZZER_PIN 18

// --- MQTT Settings ---
const char* mqtt_uri = "wss://YOUR_SERVER_URL.com:443"; // WSS = Secure WebSockets
esp_mqtt_client_handle_t mqtt_client;
bool mqtt_connected = false;

// --- EEPROM Setup for Token ---
char device_token[10] = "";
bool shouldSaveConfig = false;

// Callback notifying us of the need to save config
void saveConfigCallback () {
  Serial.println("Should save config");
  shouldSaveConfig = true;
}

// MQTT Event Handler
static void mqtt_event_handler(void *handler_args, esp_event_base_t base, int32_t event_id, void *event_data) {
    esp_mqtt_event_handle_t event = (esp_mqtt_event_handle_t)event_data;
    switch ((esp_mqtt_event_id_t)event_id) {
        case MQTT_EVENT_CONNECTED:
            Serial.println("MQTT_EVENT_CONNECTED: Successfully connected to Cloudflare WebSockets!");
            mqtt_connected = true;
            {
                char cmdTopic[50];
                sprintf(cmdTopic, "respirosync/commands/%s", device_token);
                esp_mqtt_client_subscribe(mqtt_client, cmdTopic, 0);
                Serial.print("Subscribed to ");
                Serial.println(cmdTopic);
            }
            break;
        case MQTT_EVENT_DISCONNECTED:
            Serial.println("MQTT_EVENT_DISCONNECTED");
            mqtt_connected = false;
            break;
        case MQTT_EVENT_DATA:
            {
                Serial.printf("TOPIC=%.*s\r\n", event->topic_len, event->topic);
                Serial.printf("DATA=%.*s\r\n", event->data_len, event->data);
                
                // Parse the JSON payload
                StaticJsonDocument<200> doc;
                DeserializationError error = deserializeJson(doc, event->data, event->data_len);
                if (!error) {
                    if (doc["command"] == "factory_reset") {
                        Serial.println("Received Factory Reset command from server! Wiping memory...");
                        EEPROM.write(0, '\0');
                        EEPROM.commit();
                        WiFiManager wm;
                        wm.resetSettings();
                        Serial.println("Memory wiped. Restarting into Setup Mode...");
                        delay(1000);
                        ESP.restart();
                    } else if (doc["command"] == "buzzer_on") {
                        digitalWrite(BUZZER_PIN, HIGH);
                    } else if (doc["command"] == "buzzer_off") {
                        digitalWrite(BUZZER_PIN, LOW);
                    }
                }
            }
            break;
        case MQTT_EVENT_ERROR:
            Serial.println("MQTT_EVENT_ERROR");
            break;
        default:
            break;
    }
}

// --- Read Sharp Dust Sensor ---
float readDustSensor() {
  digitalWrite(DUST_LED_PIN, LOW); // Power on the LED
  delayMicroseconds(280);
  int voMeasured = analogRead(DUST_OUT_PIN); // Read the dust value
  delayMicroseconds(40);
  digitalWrite(DUST_LED_PIN, HIGH); // Turn the LED off
  delayMicroseconds(9680);

  // Convert analog reading (0-4095) to Voltage (ESP32 is 3.3V logic)
  float calcVoltage = voMeasured * (3.3 / 4095.0);
  
  // Linear Equation taken from Chris Nafis (c) 2012 for GP2Y1014AU0F
  float dustDensity = 170 * calcVoltage - 0.1;

  if (dustDensity < 0) {
    dustDensity = 0.00;
  }
  return dustDensity;
}


void setup() {
  Serial.begin(115200);
  EEPROM.begin(512);

  // Read Token from EEPROM
  for (int i = 0; i < 6; ++i) {
    device_token[i] = char(EEPROM.read(i));
  }
  device_token[6] = '\0';
  Serial.print("Saved Token: ");
  Serial.println(device_token);

  // --- WiFiManager ---
  WiFiManager wm;
  
  // Create a custom parameter for the Setup Token
  WiFiManagerParameter custom_token("token", "Setup Token (from Web Dashboard)", device_token, 7);
  wm.addParameter(&custom_token);
  wm.setSaveConfigCallback(saveConfigCallback);

  // Initialize UART2 to listen to Raspberry Pi Pico (RX=GPIO16, TX=GPIO17)
  Serial2.begin(9600, SERIAL_8N1, 16, 17);
  
  // Initialize Pins
  pinMode(DUST_LED_PIN, OUTPUT);
  pinMode(BUZZER_PIN, OUTPUT);
  digitalWrite(DUST_LED_PIN, HIGH); // Dust LED is active LOW, so start HIGH
  digitalWrite(BUZZER_PIN, LOW);
  
  // Initialize DHT Sensor
  dht.begin();

  // If EEPROM is empty (0xFF is the default flash memory state), force the Captive Portal to appear!
  if (device_token[0] == (char)255 || device_token[0] == '\0') {
    Serial.println("No token found in memory! Forcing Captive Portal Setup.");
    wm.resetSettings(); // Erase saved WiFi so it broadcasts the Hotspot
  }

  // Start the Captive Portal Hotspot
  Serial.println("Starting Captive Portal: RespiroSync-Setup");
  if (!wm.autoConnect("RespiroSync-Setup")) {
    Serial.println("Failed to connect and hit timeout");
    delay(3000);
    ESP.restart();
  }

  Serial.println("Connected to Home WiFi!");

  // Save the custom token to EEPROM if updated
  if (shouldSaveConfig) {
    String newToken = custom_token.getValue();
    Serial.print("Saving new token: ");
    Serial.println(newToken);
    for (int i = 0; i < newToken.length(); ++i) {
      EEPROM.write(i, newToken[i]);
    }
    EEPROM.write(newToken.length(), '\0');
    EEPROM.commit();
    strcpy(device_token, newToken.c_str());
  }

  // --- Setup MQTT over WebSockets ---
  esp_mqtt_client_config_t mqtt_cfg = {};
  
#if ESP_IDF_VERSION >= ESP_IDF_VERSION_VAL(5, 0, 0)
  mqtt_cfg.broker.address.uri = mqtt_uri;
  mqtt_cfg.broker.verification.crt_bundle_attach = esp_crt_bundle_attach; // Verify Cloudflare SSL
#else
  mqtt_cfg.uri = mqtt_uri;
  mqtt_cfg.cert_pem = NULL;
  mqtt_cfg.skip_cert_common_name_check = true;
#endif
  
  mqtt_client = esp_mqtt_client_init(&mqtt_cfg);
  esp_mqtt_client_register_event(mqtt_client, (esp_mqtt_event_id_t)ESP_EVENT_ANY_ID, mqtt_event_handler, NULL);
  esp_mqtt_client_start(mqtt_client);
}

unsigned long lastTelemetryMillis = 0;

void loop() {
  if (!mqtt_connected) {
    delay(1000);
    return; // Wait until MQTT is connected
  }

  // --- UART Cough Detection from Pico ---
  if (Serial2.available()) {
    String msg = Serial2.readStringUntil('\n');
    msg.trim();
    if (msg.startsWith("COUGH:")) {
      int severity = msg.substring(6).toInt();
      Serial.print("⚠️ Received Cough Trigger from Pico! Severity: ");
      Serial.println(severity);
      
      // Publish Cough Event JSON
      StaticJsonDocument<200> coughDoc;
      coughDoc["token"] = device_token;
      coughDoc["event"] = "cough";
      coughDoc["severity"] = severity;
      
      char coughBuffer[256];
      serializeJson(coughDoc, coughBuffer);
      esp_mqtt_client_publish(mqtt_client, "respirosync/telemetry", coughBuffer, 0, 0, 0);
      
      // Quick beep to indicate Cough Event received
      digitalWrite(BUZZER_PIN, HIGH);
      delay(100);
      digitalWrite(BUZZER_PIN, LOW);
    }
  }

  // --- Send Telemetry Every 5 Seconds ---
  if (millis() - lastTelemetryMillis > 5000) {
    lastTelemetryMillis = millis();

    // 1. Read DHT22
    float hum = dht.readHumidity();
    float temp = dht.readTemperature();
    
    // Check if DHT read failed
    if (isnan(hum) || isnan(temp)) {
      Serial.println("Warning: Failed to read from DHT sensor!");
      hum = 0; temp = 0;
    }
    
    // 2. Read PM2.5 Dust Sensor
    float pm25 = readDustSensor();
    
    // 3. Read MQ135 Gas Sensor
    int mq135_raw = analogRead(MQ135PIN);

    // --- SEND TELEMETRY JSON ---
    StaticJsonDocument<200> doc;
    doc["token"] = device_token;
    doc["temperature"] = temp;
    doc["humidity"] = hum;
    doc["pm25_level"] = pm25;
    doc["mq135_level"] = mq135_raw;

    char jsonBuffer[256];
    serializeJson(doc, jsonBuffer);

    // Publish to topic (QoS 0)
    esp_mqtt_client_publish(mqtt_client, "respirosync/telemetry", jsonBuffer, 0, 0, 0);
    
    Serial.print("Sent over WSS: ");
    Serial.println(jsonBuffer);
  }
}
