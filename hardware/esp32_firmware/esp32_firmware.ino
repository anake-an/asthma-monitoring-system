/*
 * RespiroSync - ESP32 gateway firmware
 *
 * - Reads DHT22, Sharp GP2Y1010AU0F / GP2Y1014AU0F (dust) and MQ-135 (estimated ppm) every 3 s
 * - Receives cough detections from the Raspberry Pi Pico over UART2
 * - Publishes to   respirosync/devices/<token>/telemetry and /events
 * - Subscribes to  respirosync/devices/<token>/config   (retained thresholds)
 *                  respirosync/devices/<token>/commands (factory_reset, buzzer_on, buzzer_off, set_time, ota)
 * - Sounds the local alarm when a reading crosses its threshold, even while offline
 * - 16x2 LCD, every line centred: start-up steps (Wi-Fi, clock, cloud), pages every 5 s with the
 *   title on top and values below (Air quality, Room climate, clock, "Daily dose?" while due), alerts
 *   that blink the backlight, a cough animation, and night mode (backlight off 21:00-07:00
 *   unless there is an alert or the BOOT button is pressed)
 * - Gas sensor warm-up: not read for the first 3 minutes (sent as null)
 * - Clock from NTP, or asked from the server over MQTT where NTP is blocked
 * - Cloud firmware updates: the owner presses "Update" in the dashboard and the device downloads
 *   the new firmware from the server over HTTPS, checks it, installs it, and goes back to the
 *   previous one if the new one does not reach the cloud (FIRMWARE_VERSION below)
 *
 * Setup: copy secrets.example.h to secrets.h and fill it in. Pairing token is
 * entered on the "RespiroSync-Setup" Wi-Fi portal.
 * Tested to compile on arduino-esp32 core 2.0.x (ESP-IDF 4.4); IDF 5 (core 3.x) branch included.
 * Board: "ESP32 Dev Module", Partition Scheme "Minimal SPIFFS (1.9MB APP with OTA/190KB SPIFFS)":
 * the default scheme (1.2 MB per app) is too small for this sketch with updates over Wi-Fi.
 */
#include <WiFi.h>
#include <WiFiManager.h>  // https://github.com/tzapu/WiFiManager
#include <EEPROM.h>
#include <ArduinoJson.h>
#include "mqtt_client.h"  // ESP-IDF MQTT client (WebSockets + TLS)
#include "esp_crt_bundle.h"
#include <sys/time.h>
#include <DHT.h>
#include <Wire.h>
#include <LiquidCrystal_I2C.h>  // https://github.com/johnrickman/LiquidCrystal_I2C
#include "esp_https_ota.h"      // cloud firmware updates
#include "esp_ota_ops.h"
#include "secrets.h"            // MQTT_URI, MQTT_USERNAME, MQTT_PASSWORD

// Raise this for every build you publish (php artisan firmware:publish reads it from the file).
// Firmware "3.1.0 Build 261011": the version is set here (major.feature.fix, history in
// hardware/FIRMWARE_HISTORY.md); the build stamp (build date YYMMDD, ".2" for a second build that
// day) is written by CI into build_info.h. A build made in the Arduino IDE says "dev".
#define FIRMWARE_VERSION "3.1.0"
#if __has_include("build_info.h")
#include "build_info.h"
#endif
#ifndef FIRMWARE_BUILD
#define FIRMWARE_BUILD "dev"
#endif
// Read by the server from the .bin when it is published: keep this format.
const char FIRMWARE_TAG[] = "RespiroSync-firmware:" FIRMWARE_VERSION " build " FIRMWARE_BUILD;

// Works with ArduinoJson 6.x and 7.x
#if ARDUINOJSON_VERSION_MAJOR >= 7
typedef JsonDocument Doc;
#else
typedef StaticJsonDocument<512> Doc;  // the "ota" command carries a link and a checksum
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
#define WAKE_BUTTON_PIN 0  // the board's BOOT button: lights the screen at night, next page

// The Sharp sensor runs on 5 V, so its output goes through a 10k/20k divider
// (see WIRING_GUIDE.md). Voltage at the sensor = voltage at the pin * 1.5.
const float DUST_DIVIDER_RATIO = 1.5f;

// Passive buzzer: it needs a square wave; a steady HIGH only clicks once.
// Wired through a 220 ohm resistor so a magnetic buzzer stays within the pin's current limit.
const unsigned int BUZZER_HZ = 2700;  // near the resonance of common 12 mm buzzers
bool buzzerOn = false;

void setBuzzer(bool on) {
  if (on == buzzerOn) return;
  buzzerOn = on;
  if (on) {
    tone(BUZZER_PIN, BUZZER_HZ);
  } else {
    noTone(BUZZER_PIN);
    pinMode(BUZZER_PIN, OUTPUT);
    digitalWrite(BUZZER_PIN, LOW);  // idle low: no DC through the coil
  }
}

DHT dht(DHTPIN, DHTTYPE);
LiquidCrystal_I2C lcd(0x27, 16, 2);  // try 0x3F if the screen stays blank

// --- Identity / MQTT ---
char device_token[7] = "";
char topicTelemetry[48], topicEvents[48], topicConfig[48], topicCommands[48];
esp_mqtt_client_handle_t mqtt_client;
volatile bool mqtt_connected = false;
bool shouldSaveConfig = false;

// --- Thresholds (defaults until the retained config arrives) ---
// Same as the server's defaults (HardwareConfig::DEFAULTS), so a device that boots without
// internet alarms on the same limits as a new room on the dashboard.
struct Thresholds {
  float pm25 = 35.0f;
  float temperature = 35.0f;
  float humidity = 75.0f;  // 60 % alarmed all the time in Malaysian indoor air (often 65-70 %)
  float mq135 = 1000.0f;  // estimated ppm (CO2-equivalent)
  bool muted = false;
  bool doseDue = false;   // the child's daily dose is overdue: the LCD shows a reminder
};
Thresholds thresholds;
portMUX_TYPE thresholdsMux = portMUX_INITIALIZER_UNLOCKED;

// Commands are queued by the MQTT task and executed in loop()
enum PendingCommand { CMD_NONE, CMD_FACTORY_RESET, CMD_BUZZER_ON, CMD_BUZZER_OFF };
volatile PendingCommand pendingCommand = CMD_NONE;
bool cloudAlarm = false;
volatile uint32_t serverEpoch = 0;  // time from the server ("set_time"), applied in loop()

// A cloud firmware update the server asked for ("ota" command), run by loop() (runOta).
struct OtaJob {
  char url[256];
  char version[33];
  char sha256[65];
  uint32_t size;
};
OtaJob otaJob;
volatile bool otaPending = false;

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
        t.doseDue = doc["dose_due"] | false;
        portENTER_CRITICAL(&thresholdsMux);
        thresholds = t;
        portEXIT_CRITICAL(&thresholdsMux);
        Serial.println("Thresholds updated from cloud");
      } else {
        const char *cmd = doc["command"] | "";
        if (strcmp(cmd, "factory_reset") == 0) pendingCommand = CMD_FACTORY_RESET;
        else if (strcmp(cmd, "buzzer_on") == 0) pendingCommand = CMD_BUZZER_ON;
        else if (strcmp(cmd, "buzzer_off") == 0) pendingCommand = CMD_BUZZER_OFF;
        else if (strcmp(cmd, "set_time") == 0) serverEpoch = doc["epoch"] | 0UL;
        else if (strcmp(cmd, "ota") == 0 && !otaPending) {
          const char *url = doc["url"] | "";
          const char *version = doc["version"] | "";
          const char *sha = doc["sha256"] | "";
          if (strncmp(url, "https://", 8) == 0 && strlen(url) < sizeof(otaJob.url) && strlen(sha) == 64
              && *version && strlen(version) < sizeof(otaJob.version)) {
            strcpy(otaJob.url, url);
            strcpy(otaJob.version, version);
            strcpy(otaJob.sha256, sha);
            otaJob.size = doc["size"] | 0UL;
            otaPending = true;  // last: loop() starts the update
          }
        }
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

// --- Sharp GP2Y1010AU0F / GP2Y1014AU0F, returns an estimated dust density in ug/m3 ---
// Each unit has its own clean-air output (Voc; datasheet range 0-1.5 V), so a fixed
// formula reads 0 on some sensors. The baseline is learned as the lowest averaged
// reading since boot. Limitation: a unit that boots in dusty air under-reports until
// it has seen cleaner air. Not size-selective, so this approximates PM2.5.
const int DUST_SAMPLES = 25;                     // 25 LED pulses x 10 ms = 0.25 s per reading
const float DUST_SENSITIVITY_V_PER_UG = 0.005f;  // datasheet typical: 0.5 V per 0.1 mg/m3
uint32_t lastDustPinMv = 0;                      // averaged reading at GPIO 35 (Serial log)
float dustBaselineV = -1.0f;                     // learned clean-air voltage at the sensor

float readDustSensor() {
  uint32_t sumMv = 0;
  for (int i = 0; i < DUST_SAMPLES; i++) {
    digitalWrite(DUST_LED_PIN, LOW);  // LED on (active low)
    delayMicroseconds(280);           // sample 0.28 ms into the 0.32 ms pulse (datasheet timing)
    sumMv += analogReadMilliVolts(DUST_OUT_PIN);
    delayMicroseconds(40);
    digitalWrite(DUST_LED_PIN, HIGH);
    delayMicroseconds(9680);          // 10 ms cycle
  }
  lastDustPinMv = sumMv / DUST_SAMPLES;

  float sensorVolts = (lastDustPinMv / 1000.0f) * DUST_DIVIDER_RATIO;
  if (dustBaselineV < 0 || sensorVolts < dustBaselineV) dustBaselineV = sensorVolts;
  return (sensorVolts - dustBaselineV) / DUST_SENSITIVITY_V_PER_UG;
}

// --- MQ-135, returns an estimated CO2-equivalent ppm (NAN if there is no signal) ---
// Datasheet-curve method used by the common MQ135 Arduino library: ppm = a * (Rs/R0)^b with
// b = -2.769. The module's load resistor cancels out of Rs/R0, so it does not need to be known.
// R0 is learned like the dust baseline: the cleanest air seen since boot (highest Rs) is taken
// as fresh air at MQ135_CLEAN_AIR_PPM. Indoor air is usually above that, the sensor reacts to
// many gases, and it drifts with temperature and humidity: an estimate, not a CO2 measurement.
const float MQ135_SUPPLY_V = 5.0f;          // MB-102 5 V rail
const float MQ135_DIVIDER_RATIO = 1.5f;     // same 10k/20k divider as the dust sensor
const float MQ135_CLEAN_AIR_PPM = 420.0f;   // outdoor CO2, the calibration point
const float MQ135_CURVE_EXP = -2.769034857f;
const int MQ135_SAMPLES = 10;
float mq135CleanRsRatio = -1.0f;  // Rs/RL in the cleanest air seen since boot
int lastMq135Raw = 0;             // averaged ADC value (Serial log)

float readMq135Ppm() {
  uint32_t sumMv = 0, sumRaw = 0;
  for (int i = 0; i < MQ135_SAMPLES; i++) {
    sumMv += analogReadMilliVolts(MQ135PIN);
    sumRaw += analogRead(MQ135PIN);
    delay(2);
  }
  lastMq135Raw = sumRaw / MQ135_SAMPLES;
  float v = (sumMv / (float)MQ135_SAMPLES / 1000.0f) * MQ135_DIVIDER_RATIO;  // voltage at AO
  if (v < 0.05f) return NAN;                                  // no signal (unpowered / unwired)
  if (v > MQ135_SUPPLY_V - 0.01f) v = MQ135_SUPPLY_V - 0.01f;
  float rsRatio = (MQ135_SUPPLY_V - v) / v;                   // Rs / RL
  if (mq135CleanRsRatio < 0 || rsRatio > mq135CleanRsRatio) mq135CleanRsRatio = rsRatio;
  return MQ135_CLEAN_AIR_PPM * powf(rsRatio / mq135CleanRsRatio, MQ135_CURVE_EXP);
}

// Rolling average of the last few readings. Both the published value and the local alarm use it,
// so a single noisy reading neither beeps nor disagrees with the dashboard.
const int SMOOTH_N = 4;  // 4 readings x 3 s = ~12 s
struct Smoother {
  float values[SMOOTH_N];
  int count = 0, next = 0;
  float add(float v) {
    values[next] = v;
    next = (next + 1) % SMOOTH_N;
    if (count < SMOOTH_N) count++;
    float sum = 0;
    for (int i = 0; i < count; i++) sum += values[i];
    return sum / count;
  }
};
Smoother pm25Smoother, gasSmoother;


// The gas sensor heats up for its first minutes, and its clean-air reference is learned from the
// readings: until then it is not read, and gas is sent as null (shown as "warm-up" on the LCD).
const unsigned long GAS_WARMUP_MS = 180000;  // 3 minutes
bool gasWarmingUp() { return millis() < GAS_WARMUP_MS; }

// ===================== LCD =====================
// Every line is centred. Custom icons use the LCD's 8 user characters; codes 8-15 show the same
// characters as 0-7, so slot 0 is written as 8 (a 0 byte would end the C string).
const char ICON_ONLINE = 8, ICON_OFFLINE = 1, ICON_THERMO = 2, ICON_DROP = 3, ICON_DUST = 4,
           ICON_BELL = 5, ICON_HEART = 6, ICON_GAS = 7;
byte glyphOnline[8]  = {0b00000, 0b01110, 0b10001, 0b00100, 0b01010, 0b00000, 0b00100, 0b00000};
byte glyphOffline[8] = {0b00000, 0b10001, 0b01010, 0b00100, 0b01010, 0b10001, 0b00000, 0b00000};
byte glyphThermo[8]  = {0b00100, 0b01010, 0b01010, 0b01110, 0b01110, 0b11111, 0b11111, 0b01110};
byte glyphDrop[8]    = {0b00100, 0b00100, 0b01010, 0b01010, 0b10001, 0b10001, 0b10001, 0b01110};
byte glyphDust[8]    = {0b00000, 0b10100, 0b00001, 0b01000, 0b00010, 0b10000, 0b00101, 0b00000};
byte glyphBell[8]    = {0b00100, 0b01110, 0b01110, 0b01110, 0b11111, 0b00000, 0b00100, 0b00000};
byte glyphHeart[8]   = {0b00000, 0b01010, 0b11111, 0b11111, 0b11111, 0b01110, 0b00100, 0b00000};
byte glyphGas[8]     = {0b00000, 0b00000, 0b01100, 0b10010, 0b10001, 0b11111, 0b00000, 0b00000};

void createGlyphs() {
  lcd.createChar(0, glyphOnline);
  lcd.createChar(1, glyphOffline);
  lcd.createChar(2, glyphThermo);
  lcd.createChar(3, glyphDrop);
  lcd.createChar(4, glyphDust);
  lcd.createChar(5, glyphBell);
  lcd.createChar(6, glyphHeart);
  lcd.createChar(7, glyphGas);
}

// What is on the screen now. Only characters that differ are rewritten, so nothing flickers
// (lcd.clear() blanks the whole screen for a moment every time).
char shown[2][17] = {"                ", "                "};

/** Show text centred on a row (cut to 16 characters). */
void showLine(int row, const char *text) {
  char want[17];
  int n = strlen(text);
  if (n > 16) n = 16;
  int pad = (16 - n) / 2;
  memset(want, ' ', 16);
  memcpy(want + pad, text, n);
  want[16] = '\0';
  int cursor = -1;
  for (int i = 0; i < 16; i++) {
    if (want[i] == shown[row][i]) continue;
    if (cursor != i) lcd.setCursor(i, row);
    lcd.write((uint8_t)want[i]);
    shown[row][i] = want[i];
    cursor = i + 1;
  }
}

void showScreen(const char *line0, const char *line1) {
  showLine(0, line0);
  showLine(1, line1);
}

/** A spinner character that turns while something is loading. */
char spinner() {
  static const char frames[] = ".oOo";
  return frames[(millis() / 250) % 4];
}

/** "Title" over a spinner, e.g. "Connecting cloud" / "o". */
void showLoading(const char *title) {
  char l1[2] = {spinner(), '\0'};
  showScreen(title, l1);
}

// Latest readings for the screen (set every 3 s by readAndPublishTelemetry).
struct Reading {
  float pm25 = NAN, temp = NAN, hum = NAN, gas = NAN;
} reading;

// Readings over their limit (bits), set by readAndPublishTelemetry.
const uint8_t ALARM_PM25 = 1, ALARM_GAS = 2, ALARM_TEMP = 4, ALARM_HUM = 8;
uint8_t alarmMask = 0;

const unsigned long PAGE_MS = 5000;            // each page stays 5 s
const unsigned long WAKE_MS = 30000;           // screen stays lit 30 s at night after a wake-up
const unsigned long COUGH_SCREEN_MS = 2000;
const unsigned long ALARM_TURN_MS = 2000;      // several alarms take turns
unsigned long pageStartedAt = 0;
int page = 0;
unsigned long wakeUntil = 0;                   // night mode: lit until then
unsigned long alarmFlashUntil = 0;             // backlight blinks 3 times when an alarm starts
uint8_t lastAlarmMask = 0;
unsigned long coughShownAt = 0;
unsigned long cloudStartedAt = 0;              // when the MQTT client was started
bool everConnected = false;
bool wasConnected = false;
unsigned long readyUntil = 0;                  // "Ready" after the first cloud connection
unsigned long offlineUntil = 0;                // "Offline" note after the connection drops
bool backlightOn = true;

void setBacklight(bool on) {
  if (on == backlightOn) return;
  backlightOn = on;
  if (on) lcd.backlight();
  else lcd.noBacklight();
}

// ===================== Clock =====================
// Malaysia time (UTC+8, no daylight saving) from internet time servers (NTP, UDP port 123). Some
// networks block that port, so until the clock is set the device also asks the server over MQTT
// ("time_request" event, answered with a "set_time" command), which works wherever it connects.

/** Local time, or false before it has been set. */
bool localTime(struct tm *t) {
  time_t now;
  time(&now);  // getLocalTime() waits 10 ms each time until the clock is set; this never waits
  localtime_r(&now, t);
  return t->tm_year + 1900 >= 2024;
}

bool clockSet() {
  struct tm t;
  return localTime(&t);
}

bool isNight() {
  struct tm t;
  return localTime(&t) && (t.tm_hour >= 21 || t.tm_hour < 7);
}

/** Start-up: start NTP and wait up to 6 s for it (the server is asked once connected). */
void setClock() {
  configTzTime("MYT-8", "pool.ntp.org", "time.cloudflare.com", "time.google.com");
  unsigned long start = millis();
  while (!clockSet() && millis() - start < 6000) {
    showLoading("Setting clock");
    delay(100);
  }
  Serial.println(clockSet() ? "Clock set (NTP)" : "No NTP answer yet: will ask the server");
}

/** Until the clock is set: apply the server's answer, and ask again every 30 s. */
void retryClock() {
  static unsigned long lastAsk = 0;
  uint32_t epoch = serverEpoch;
  if (epoch) {
    serverEpoch = 0;
    struct timeval tv;
    tv.tv_sec = (time_t)epoch;
    tv.tv_usec = 0;
    settimeofday(&tv, nullptr);
    Serial.println("Clock set from the server");
  }
  if (clockSet() || !mqtt_connected) return;
  if (lastAsk && millis() - lastAsk < 30000) return;
  lastAsk = millis();
  Doc doc;
  doc["event"] = "time_request";
  publishJson(topicEvents, doc);
}


// ===================== Screens =====================
void fmtValue(char *out, size_t size, float v, int decimals) {
  if (isnan(v)) snprintf(out, size, "--");
  else snprintf(out, size, "%.*f", decimals, v);
}

// The limits the screens compare with, copied by updateDisplay (a global: the Arduino builder
// declares functions above the Thresholds type, so they cannot take it as a parameter).
Thresholds screenLimits;

enum Page { PAGE_AIR, PAGE_CLIMATE, PAGE_CLOCK, PAGE_DOSE };

/** Two values side by side: two spaces between them when that fits in 16, else one. */
void sideBySide(char *out, const char *left, const char *right) {
  const char *gap = strlen(left) + strlen(right) + 2 <= 16 ? "  " : " ";
  snprintf(out, 17, "%s%s%s", left, gap, right);
}

/** Air quality: the title stays on top; PM2.5 and gas side by side below. */
void drawAir() {
  char pm[12], gas[12], l1[17], v[8];
  fmtValue(v, sizeof(v), reading.pm25, reading.pm25 < 100 ? 1 : 0);
  snprintf(pm, sizeof(pm), "%c%sug", ICON_DUST, v);
  if (gasWarmingUp()) {
    unsigned long left = (GAS_WARMUP_MS - millis()) / 1000 + 1;
    snprintf(gas, sizeof(gas), "Gas %lu:%02lu", left / 60, left % 60);  // warm-up countdown
  } else if (isnan(reading.gas)) {
    snprintf(gas, sizeof(gas), "Gas --");
  } else {
    fmtValue(v, sizeof(v), reading.gas, 0);
    snprintf(gas, sizeof(gas), "%c%sppm", ICON_GAS, v);
  }
  sideBySide(l1, pm, gas);
  showScreen("Air quality", l1);
}

/** Room climate: the title stays on top; temperature and humidity side by side below. */
void drawClimate() {
  if (isnan(reading.temp)) {
    showScreen("Room climate", "Check sensor");
    return;
  }
  char temp[10], hum[10], l1[17], v[8];
  fmtValue(v, sizeof(v), reading.temp, 1);
  snprintf(temp, sizeof(temp), "%c%sC", ICON_THERMO, v);
  fmtValue(v, sizeof(v), reading.hum, 0);
  snprintf(hum, sizeof(hum), "%c%s%%", ICON_DROP, v);
  sideBySide(l1, temp, hum);
  showScreen("Room climate", l1);
}

/** Clock, with the connection icon after the time. */
void drawClock() {
  char l0[17], l1[17];
  char icon = mqtt_connected ? ICON_ONLINE : ICON_OFFLINE;
  struct tm t;
  if (localTime(&t)) {
    strftime(l0, sizeof(l0), "%H:%M", &t);
    size_t n = strlen(l0);
    l0[n] = ' ';
    l0[n + 1] = icon;
    l0[n + 2] = '\0';
    strftime(l1, sizeof(l1), "%a %d %b", &t);
  } else {
    snprintf(l0, sizeof(l0), "Clock %c", icon);
    snprintf(l1, sizeof(l1), "not set yet");
  }
  showScreen(l0, l1);
}

void drawDose() {
  char l0[17];
  snprintf(l0, sizeof(l0), "%c Daily dose?", ICON_BELL);
  showScreen(l0, "Log it in app");
}

/** The pages in turn: Air quality, Room climate, Clock, and "Daily dose?" while one is due. */
int nextPage(int current, bool doseDue) {
  int last = doseDue ? PAGE_DOSE : PAGE_CLOCK;
  return current >= last ? PAGE_AIR : current + 1;
}

/** Alarm, e.g. "!! DUST HIGH !!" over "38 > limit 35" (or "38 > 35" when that does not fit). */
void drawAlarm(const char *title, float value, float limit, int decimals, const char *unit) {
  char l0[17], l1[24], v[8], lim[8];
  snprintf(l0, sizeof(l0), "!! %s !!", title);
  fmtValue(v, sizeof(v), value, decimals);
  fmtValue(lim, sizeof(lim), limit, decimals);
  snprintf(l1, sizeof(l1), "%s%s > limit %s", v, unit, lim);
  if (strlen(l1) > 16) snprintf(l1, sizeof(l1), "%s%s > %s", v, unit, lim);
  showScreen(l0, l1);
}

void drawAlarms(uint8_t mask) {
  const Thresholds &t = screenLimits;
  uint8_t active[4];
  int n = 0;
  for (uint8_t bit = 1; bit <= ALARM_HUM; bit <<= 1) {
    if (mask & bit) active[n++] = bit;
  }
  switch (active[(millis() / ALARM_TURN_MS) % n]) {
    case ALARM_PM25: drawAlarm("DUST HIGH", reading.pm25, t.pm25, 0, ""); break;
    case ALARM_GAS: drawAlarm("GAS HIGH", reading.gas, t.mq135, 0, ""); break;
    case ALARM_TEMP: drawAlarm("TOO HOT", reading.temp, t.temperature, 1, "C"); break;
    default: drawAlarm("TOO HUMID", reading.hum, t.humidity, 0, "%"); break;
  }
}

void drawCough(unsigned long since) {
  // A heartbeat-like pulse moving across the bottom line.
  static const char pulse[] = "__-^v-__";
  char l0[17], l1[17];
  snprintf(l0, sizeof(l0), "%c Cough heard", ICON_BELL);
  int offset = (since / 120) % 24;
  for (int i = 0; i < 16; i++) {
    int p = i - offset + 8;
    l1[i] = (p >= 0 && p < 8) ? pulse[p] : '_';
  }
  l1[16] = '\0';
  showScreen(l0, l1);
}

/** Draw whatever matters most right now. Called every loop; only changes reach the LCD. */
void updateDisplay() {
  portENTER_CRITICAL(&thresholdsMux);
  screenLimits = thresholds;
  portEXIT_CRITICAL(&thresholdsMux);
  unsigned long now = millis();

  // Connection changes: "Ready" the first time, "Offline" when it drops.
  if (mqtt_connected && !everConnected) {
    everConnected = true;
    readyUntil = now + 2500;
  }
  if (wasConnected && !mqtt_connected) offlineUntil = now + 2500;
  wasConnected = mqtt_connected;

  // A reading newly over its limit: blink the backlight 3 times.
  if (alarmMask & ~lastAlarmMask) alarmFlashUntil = now + 1200;
  lastAlarmMask = alarmMask;
  bool coughing = coughShownAt && now - coughShownAt < COUGH_SCREEN_MS;
  if (alarmMask || cloudAlarm || coughing) wakeUntil = now + WAKE_MS;

  // Backlight: blinking for a new alarm, else off at night unless woken.
  if (now < alarmFlashUntil) setBacklight(((alarmFlashUntil - now) / 200) % 2 == 0);
  else setBacklight(!isNight() || now < wakeUntil);

  if (coughing) { drawCough(now - coughShownAt); return; }
  if (alarmMask) { drawAlarms(alarmMask); return; }
  if (cloudAlarm) { showScreen("!! ALERT !!", "From the app"); return; }
  if (now < offlineUntil) { showScreen("Offline", "Alarms still on"); return; }
  if (!everConnected && now - cloudStartedAt < 20000) { showLoading("Connecting cloud"); return; }
  if (now < readyUntil) {
    char l0[17];
    snprintf(l0, sizeof(l0), "Ready %c", ICON_HEART);
    showScreen(l0, "Monitoring room");
    return;
  }

  if (now - pageStartedAt >= PAGE_MS) {
    page = nextPage(page, screenLimits.doseDue);
    pageStartedAt = now;
  }
  if (page == PAGE_DOSE && !screenLimits.doseDue) page = PAGE_AIR;
  switch (page) {
    case PAGE_AIR: drawAir(); break;
    case PAGE_CLIMATE: drawClimate(); break;
    case PAGE_CLOCK: drawClock(); break;
    default: drawDose(); break;
  }
}

/** BOOT button: lights the screen (night mode); when lit, shows the next page. */
void handleButton() {
  static bool wasDown = false;
  static unsigned long changedAt = 0;
  bool down = digitalRead(WAKE_BUTTON_PIN) == LOW;
  if (down == wasDown || millis() - changedAt < 50) return;  // debounce
  wasDown = down;
  changedAt = millis();
  if (!down) return;
  bool wasDark = !backlightOn;
  wakeUntil = millis() + WAKE_MS;
  if (wasDark) return;  // the first press only lights the screen
  bool doseDue;
  portENTER_CRITICAL(&thresholdsMux);
  doseDue = thresholds.doseDue;
  portEXIT_CRITICAL(&thresholdsMux);
  page = nextPage(page, doseDue);
  pageStartedAt = millis();
}

/** Start-up: "RespiroSync" and a heart slide in to the middle. */
void bootAnimation() {
  char text[14];
  snprintf(text, sizeof(text), "RespiroSync %c", ICON_HEART);
  int n = strlen(text);
  int centre = (16 - n) / 2;
  for (int x = 16; x >= centre; x--) {
    char l0[17];
    memset(l0, ' ', 16);
    l0[16] = '\0';
    for (int i = 0; i < n && x + i < 16; i++) l0[x + i] = text[i];
    // Written as is (already 16 wide), not centred again.
    for (int i = 0; i < 16; i++) {
      if (l0[i] != shown[0][i]) {
        lcd.setCursor(i, 0);
        lcd.write((uint8_t)l0[i]);
        shown[0][i] = l0[i];
      }
    }
    delay(90);  // slide speed
  }
  showLine(1, "FW " FIRMWARE_VERSION);
  delay(1500);  // time to read the version
  showLine(1, "Build " FIRMWARE_BUILD);
  delay(1200);
}

/** WiFiManager opened its setup hotspot (no Wi-Fi saved, or the saved one is not found). */
void onSetupPortal(WiFiManager *wm) {
  showScreen("WiFi setup", "Join RespiroSync");  // the "RespiroSync-Setup" network
}

// ===================== Cloud firmware updates (OTA) =====================
// An owner presses "Update" in the dashboard; the server sends an "ota" command with a one-time
// HTTPS link to the firmware on the NAS and the image's SHA-256. The device downloads it into the
// spare app slot (needs the "Minimal SPIFFS (1.9MB APP with OTA)" partition scheme), checks it,
// and restarts into it. The new firmware must reach the cloud within OTA_CONFIRM_MS, else the
// ESP32 goes back to the previous firmware (rollback; see ota_rollback.cpp).

const unsigned long OTA_CONFIRM_MS = 120000;

/** Tell the server how the update went ("failed" with a reason, or "installed"). */
void reportOta(const char *status, const char *error) {
  Doc doc;
  doc["event"] = "ota";
  doc["status"] = status;
  doc["version"] = otaJob.version;
  if (error) doc["error"] = error;
  publishJson(topicEvents, doc);
}

void otaFail(const char *lcdLine, const char *error) {
  Serial.printf("Update failed: %s\n", error);
  showScreen("Update failed", lcdLine);
  reportOta("failed", error);
  delay(3000);  // long enough to read; the pages come back afterwards
}

/** Download, check and install the firmware the server announced, then restart into it. */
void runOta() {
  if (alarmMask || cloudAlarm) {
    reportOta("failed", "An alarm is active on the device. Try again when it is over.");
    otaPending = false;
    return;
  }
  setBuzzer(false);
  setBacklight(true);
  char l0[17];
  snprintf(l0, sizeof(l0), "Updating 0%%");
  showScreen(l0, "Don't unplug");
  Serial.printf("Updating to %s from %s\n", otaJob.version, otaJob.url);

  esp_http_client_config_t http = {};
  http.url = otaJob.url;
  http.crt_bundle_attach = esp_crt_bundle_attach;  // a verified HTTPS connection, like the broker's
  http.timeout_ms = 20000;
  esp_https_ota_config_t cfg = {};
  cfg.http_config = &http;
  esp_https_ota_handle_t ota = nullptr;
  otaPending = false;
  if (esp_https_ota_begin(&cfg, &ota) != ESP_OK) {
    otaFail("Download error", "The download did not start (link expired, or the server is unreachable).");
    return;
  }
  esp_err_t err;
  int shownPct = -1;
  while ((err = esp_https_ota_perform(ota)) == ESP_ERR_HTTPS_OTA_IN_PROGRESS) {
    int read = esp_https_ota_get_image_len_read(ota);
    int pct = otaJob.size ? (int)((int64_t)read * 100 / otaJob.size) : 0;
    if (pct > 100) pct = 100;
    if (pct != shownPct) {
      char l1[17];
      snprintf(l1, sizeof(l1), "Updating %d%%", pct);
      showLine(0, l1);
      shownPct = pct;
    }
  }
  if (err != ESP_OK || !esp_https_ota_is_complete_data_received(ota)) {
    esp_https_ota_abort(ota);
    otaFail("Download error", "The download was interrupted. The old firmware keeps running.");
    return;
  }
  if (esp_https_ota_finish(ota) != ESP_OK) {  // checks the image and makes it the next to start
    otaFail("Bad file", "The downloaded file is not a valid firmware image.");
    return;
  }

  // The installed image must be exactly the published one.
  uint8_t sha[32];
  char hex[65];
  bool same = esp_partition_get_sha256(esp_ota_get_boot_partition(), sha) == ESP_OK;
  for (int i = 0; i < 32; i++) snprintf(hex + i * 2, 3, "%02x", sha[i]);
  if (!same || strcasecmp(hex, otaJob.sha256) != 0) {
    esp_ota_set_boot_partition(esp_ota_get_running_partition());  // keep the current firmware
    otaFail("Bad checksum", "The checksum does not match: the update was not installed.");
    return;
  }

  reportOta("installed", nullptr);
  showScreen("Update done", "Restarting...");
  delay(1500);
  ESP.restart();
}

/** True while a just-installed firmware has not been confirmed yet (it can still go back). */
bool firmwareUnconfirmed() {
  esp_ota_img_states_t state;
  return esp_ota_get_state_partition(esp_ota_get_running_partition(), &state) == ESP_OK
         && state == ESP_OTA_IMG_PENDING_VERIFY;
}

/**
 * After an update: once the new firmware has reached the cloud, keep it. If it has not within
 * OTA_CONFIRM_MS, go back to the previous one. (Any restart before that goes back too.)
 */
void confirmFirmware() {
  static bool settled = false;
  if (settled) return;
  if (!firmwareUnconfirmed()) {
    settled = true;
  } else if (mqtt_connected) {
    esp_ota_mark_app_valid_cancel_rollback();
    Serial.println("New firmware confirmed");
    settled = true;
  } else if (millis() > OTA_CONFIRM_MS) {
    showScreen("Update failed", "Going back...");
    delay(1500);
    esp_ota_mark_app_invalid_rollback_and_reboot();
  }
}

/** "hello" with the firmware version each time it connects (boot=true the first time). */
void sayHello() {
  static bool connectedBefore = false;
  static bool firstSinceBoot = true;
  if (mqtt_connected && !connectedBefore) {
    Doc doc;
    doc["event"] = "hello";
    doc["firmware"] = FIRMWARE_VERSION;
    doc["build"] = FIRMWARE_BUILD;
    if (firstSinceBoot) doc["boot"] = true;
    publishJson(topicEvents, doc);
    firstSinceBoot = false;
  }
  connectedBefore = mqtt_connected;
}

void factoryReset() {
  Serial.println("Factory reset: wiping token and Wi-Fi settings");
  setBacklight(true);
  showScreen("Resetting...", "Pair again");
  for (int i = 0; i < 7; i++) EEPROM.write(i, 0);
  EEPROM.commit();
  WiFiManager wm;
  wm.resetSettings();
  delay(1000);
  ESP.restart();
}

void setup() {
  Serial.begin(115200);
  Serial.println(FIRMWARE_TAG);
  EEPROM.begin(512);

  pinMode(DUST_LED_PIN, OUTPUT);
  pinMode(BUZZER_PIN, OUTPUT);
  pinMode(GREEN_LED_PIN, OUTPUT);
  pinMode(RED_LED_PIN, OUTPUT);
  pinMode(WAKE_BUTTON_PIN, INPUT_PULLUP);
  digitalWrite(DUST_LED_PIN, HIGH);
  digitalWrite(BUZZER_PIN, LOW);
  digitalWrite(GREEN_LED_PIN, LOW);
  digitalWrite(RED_LED_PIN, HIGH);  // red until connected

  Wire.begin(21, 22);
  lcd.init();
  lcd.backlight();
  createGlyphs();
  bootAnimation();
  showScreen("Connecting WiFi", "please wait");

  dht.begin();
  Serial2.begin(9600, SERIAL_8N1, PICO_RX_PIN, PICO_TX_PIN);
  Serial2.setTimeout(50);

  for (int i = 0; i < 6; i++) device_token[i] = (char)EEPROM.read(i);
  device_token[6] = '\0';

  WiFiManager wm;
  WiFiManagerParameter custom_token("token", "Setup Token (from Web Dashboard)", isValidToken(device_token) ? device_token : "", 6);
  wm.addParameter(&custom_token);
  wm.setSaveConfigCallback(saveConfigCallback);
  wm.setAPCallback(onSetupPortal);
  if (firmwareUnconfirmed()) {
    // Just updated: if this firmware cannot get online, restart (which goes back to the previous
    // one) instead of waiting in the setup hotspot forever.
    wm.setConfigPortalTimeout(OTA_CONFIRM_MS / 1000);
  }

  if (!isValidToken(device_token)) {
    Serial.println("No valid token stored: opening setup portal");
    wm.resetSettings();
  }

  if (!wm.autoConnect("RespiroSync-Setup")) {
    Serial.println("Wi-Fi setup timed out, restarting");
    showScreen("WiFi not found", "Restarting...");
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
    showScreen("Invalid token", "Restarting...");
    WiFiManager().resetSettings();
    delay(1000);
    ESP.restart();
  }

  // Wi-Fi joined: show its name for a moment.
  showScreen("WiFi connected", WiFi.SSID().c_str());
  delay(2000);

  setClock();

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
  cloudStartedAt = millis();
  wakeUntil = millis() + WAKE_MS;  // lit for a while after a restart, also at night
}

unsigned long lastTelemetryMillis = 0;
const unsigned long TELEMETRY_INTERVAL_MS = 3000;  // DHT22 needs >= 2 s between reads
unsigned long coughDisplayUntil = 0;

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
  coughShownAt = millis();  // updateDisplay shows the cough animation
  coughDisplayUntil = millis() + 2000;

  setBuzzer(true);  // short acknowledgement chirp
  delay(80);
  setBuzzer(false);
}

void readAndPublishTelemetry() {
  float hum = dht.readHumidity();
  float temp = dht.readTemperature();
  float pm25 = pm25Smoother.add(readDustSensor());
  // Not read while warming up, so its clean-air reference is not learned from a cold sensor.
  float gasPpm = gasWarmingUp() ? NAN : readMq135Ppm();
  if (!isnan(gasPpm)) gasPpm = gasSmoother.add(gasPpm);
  // Raw values for wiring checks and calibration.
  Serial.printf("Dust: %u mV at pin (%.2f V at sensor, clean-air baseline %.2f V) -> %.1f ug/m3 | MQ-135 raw %d -> %.0f ppm est.%s\n",
                (unsigned)lastDustPinMv, lastDustPinMv / 1000.0f * DUST_DIVIDER_RATIO, dustBaselineV, pm25, lastMq135Raw, gasPpm,
                gasWarmingUp() ? " (warming up)" : "");

  Doc doc;
  doc["pm25_level"] = pm25;
  if (isnan(gasPpm)) doc["mq135_level"] = (const char *)nullptr;  // JSON null: warming up / no signal
  else doc["mq135_level"] = roundf(gasPpm);
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

  reading.pm25 = pm25;
  reading.gas = gasPpm;
  reading.temp = temp;
  reading.hum = hum;

  uint8_t mask = 0;
  if (pm25 > t.pm25) mask |= ALARM_PM25;
  if (!isnan(gasPpm) && gasPpm > t.mq135) mask |= ALARM_GAS;
  if (!isnan(temp) && temp > t.temperature) mask |= ALARM_TEMP;
  if (!isnan(hum) && hum > t.humidity) mask |= ALARM_HUM;
  alarmMask = mask;
}

void updateOutputs() {
  bool muted;
  portENTER_CRITICAL(&thresholdsMux);
  muted = thresholds.muted;
  portEXIT_CRITICAL(&thresholdsMux);

  bool alarm = alarmMask || cloudAlarm;
  digitalWrite(RED_LED_PIN, alarm || !mqtt_connected);
  digitalWrite(GREEN_LED_PIN, !alarm && mqtt_connected);

  // Beep 150 ms every 2 s while an alarm is active, unless muted from the dashboard.
  bool beep = alarm && !muted && (millis() % 2000) < 150;
  if (millis() > coughDisplayUntil) setBuzzer(beep);
}

void loop() {
  PendingCommand cmd = pendingCommand;
  if (cmd != CMD_NONE) {
    pendingCommand = CMD_NONE;
    if (cmd == CMD_FACTORY_RESET) factoryReset();
    cloudAlarm = (cmd == CMD_BUZZER_ON);
  }

  handlePicoMessage();
  handleButton();

  if (millis() - lastTelemetryMillis >= TELEMETRY_INTERVAL_MS) {
    lastTelemetryMillis = millis();
    readAndPublishTelemetry();
  }

  updateOutputs();
  updateDisplay();
  retryClock();
  sayHello();
  confirmFirmware();
  if (otaPending) runOta();
  delay(10);
}
