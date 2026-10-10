/*
 * RespiroSync - ESP32 gateway firmware
 *
 * - Reads DHT22, Sharp GP2Y1010AU0F / GP2Y1014AU0F (dust) and MQ-135 (estimated ppm) every 3 s
 * - Receives cough detections from the Raspberry Pi Pico over UART2
 * - Publishes to   respirosync/devices/<token>/telemetry and /events
 * - Subscribes to  respirosync/devices/<token>/config   (retained thresholds)
 *                  respirosync/devices/<token>/commands (factory_reset, buzzer_on, buzzer_off)
 * - Sounds the local alarm when a reading crosses its threshold, even while offline
 * - 16x2 LCD: icons, pages that change every 4 s (air, PM2.5 bar, gas and status, clock),
 *   a flashing alert screen, a cough animation, a daily-dose reminder, and night mode (backlight
 *   off 21:00-07:00 unless there is an alert or the BOOT button is pressed)
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

// ===================== LCD =====================
// Custom icons in the LCD's 8 user characters. Codes 8-15 show the same characters as 0-7, so
// slot 0 is written as 8 (a 0 byte would end the C string).
const char ICON_ONLINE = 8, ICON_OFFLINE = 1, ICON_THERMO = 2, ICON_DROP = 3, ICON_DUST = 4,
           ICON_BELL = 5, ICON_HEART = 6, ICON_HALF = 7;
const char ICON_FULL = (char)0xFF;  // built-in full block
byte glyphOnline[8]  = {0b00000, 0b01110, 0b10001, 0b00100, 0b01010, 0b00000, 0b00100, 0b00000};
byte glyphOffline[8] = {0b00000, 0b10001, 0b01010, 0b00100, 0b01010, 0b10001, 0b00000, 0b00000};
byte glyphThermo[8]  = {0b00100, 0b01010, 0b01010, 0b01110, 0b01110, 0b11111, 0b11111, 0b01110};
byte glyphDrop[8]    = {0b00100, 0b00100, 0b01010, 0b01010, 0b10001, 0b10001, 0b10001, 0b01110};
byte glyphDust[8]    = {0b00000, 0b10100, 0b00001, 0b01000, 0b00010, 0b10000, 0b00101, 0b00000};
byte glyphBell[8]    = {0b00100, 0b01110, 0b01110, 0b01110, 0b11111, 0b00000, 0b00100, 0b00000};
byte glyphHeart[8]   = {0b00000, 0b01010, 0b11111, 0b11111, 0b11111, 0b01110, 0b00100, 0b00000};
byte glyphHalf[8]    = {0b11100, 0b11100, 0b11100, 0b11100, 0b11100, 0b11100, 0b11100, 0b11100};

void createGlyphs() {
  lcd.createChar(0, glyphOnline);
  lcd.createChar(1, glyphOffline);
  lcd.createChar(2, glyphThermo);
  lcd.createChar(3, glyphDrop);
  lcd.createChar(4, glyphDust);
  lcd.createChar(5, glyphBell);
  lcd.createChar(6, glyphHeart);
  lcd.createChar(7, glyphHalf);
}

// What is on the screen now. A line is only rewritten where it differs, so nothing flickers
// (lcd.clear() blanks the whole screen for a moment every time).
char shown[2][17] = {"                ", "                "};

void showLine(int row, const char *text) {
  char want[17];
  int n = strlen(text);
  for (int i = 0; i < 16; i++) want[i] = i < n ? text[i] : ' ';
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

/** Text centred in 16 characters. */
void centred(char *out, const char *text) {
  int n = strlen(text);
  if (n > 16) n = 16;
  int pad = (16 - n) / 2;
  memset(out, ' ', 16);
  memcpy(out + pad, text, n);
  out[16] = '\0';
}

// Latest readings for the screen (set every 3 s by readAndPublishTelemetry).
struct Reading {
  float pm25 = NAN, temp = NAN, hum = NAN, gas = NAN;
} reading;

const unsigned long PAGE_MS = 4000;            // each page stays 4 s
const unsigned long WAKE_MS = 30000;           // screen stays lit 30 s at night after a wake-up
const unsigned long COUGH_SCREEN_MS = 2000;
unsigned long pageStartedAt = 0;
int page = 0;
unsigned long wakeUntil = 0;                   // night mode: lit until then
unsigned long alarmFlashUntil = 0;             // backlight blinks 3 times when an alarm starts
char lastAlarmKey[17] = "";
unsigned long coughShownAt = 0;
bool everConnected = false;
unsigned long readyUntil = 0;                  // "Ready" screen after the first cloud connection
bool backlightOn = true;

void setBacklight(bool on) {
  if (on == backlightOn) return;
  backlightOn = on;
  if (on) lcd.backlight();
  else lcd.noBacklight();
}

/** Local time (Malaysia, from the internet clock), or false before it has been set. */
bool localTime(struct tm *t) {
  time_t now;
  time(&now);  // getLocalTime() waits 10 ms each time until the clock is set; this never waits
  localtime_r(&now, t);
  return t->tm_year + 1900 >= 2024;
}

bool isNight() {
  struct tm t;
  return localTime(&t) && (t.tm_hour >= 21 || t.tm_hour < 7);
}

void fmtValue(char *out, size_t size, float v, int decimals) {
  if (isnan(v)) snprintf(out, size, "--");
  else snprintf(out, size, "%.*f", decimals, v);
}

// --- Pages ---
// The limits the pages compare with, copied by updateDisplay (a global: the Arduino builder
// declares functions above the Thresholds type, so they cannot take it as a parameter).
Thresholds screenLimits;
enum Page { PAGE_AIR, PAGE_BAR, PAGE_GAS, PAGE_CLOCK, PAGE_DOSE };

void drawAir() {
  const Thresholds &t = screenLimits;
  char l0[17], l1[17], pm[8], tc[8], hu[8];
  fmtValue(pm, sizeof(pm), reading.pm25, 1);
  bool high = !isnan(reading.pm25) && reading.pm25 > t.pm25;
  snprintf(l0, sizeof(l0), "%cPM2.5 %-6s%3s", ICON_DUST, pm, high ? "HI!" : "OK");
  if (isnan(reading.temp)) {
    snprintf(l1, sizeof(l1), "%c sensor error", ICON_THERMO);
  } else {
    fmtValue(tc, sizeof(tc), reading.temp, 1);
    fmtValue(hu, sizeof(hu), reading.hum, 0);
    snprintf(l1, sizeof(l1), "%c%sC   %c%s%%", ICON_THERMO, tc, ICON_DROP, hu);
  }
  showScreen(l0, l1);
}

void drawBar() {
  const Thresholds &t = screenLimits;
  char l0[17], l1[17];
  float pm = isnan(reading.pm25) ? 0 : reading.pm25;
  snprintf(l0, sizeof(l0), "PM2.5 %.0f / %.0f", pm, t.pm25);
  // 16 cells of 2 halves: full at the limit.
  int halves = t.pm25 > 0 ? (int)roundf(pm / t.pm25 * 32) : 0;
  if (halves > 32) halves = 32;
  if (halves < 0) halves = 0;
  for (int i = 0; i < 16; i++) {
    int left = halves - i * 2;
    l1[i] = left >= 2 ? ICON_FULL : left == 1 ? ICON_HALF : ' ';
  }
  l1[16] = '\0';
  showScreen(l0, l1);
}

void drawGas() {
  const Thresholds &t = screenLimits;
  char l0[17], l1[17], gas[8];
  fmtValue(gas, sizeof(gas), reading.gas, 0);
  bool high = !isnan(reading.gas) && reading.gas > t.mq135;
  snprintf(l0, sizeof(l0), "Gas %5sppm %3s", gas, isnan(reading.gas) ? "" : high ? "HI!" : "OK");
  // The air in one friendly line.
  bool dusty = !isnan(reading.pm25) && reading.pm25 > t.pm25 * 0.8f;
  bool gassy = !isnan(reading.gas) && reading.gas > t.mq135 * 0.8f;
  if (dusty) snprintf(l1, sizeof(l1), "Dust rising...");
  else if (gassy) snprintf(l1, sizeof(l1), "Open a window?");
  else snprintf(l1, sizeof(l1), "Air is good %c", ICON_HEART);
  showScreen(l0, l1);
}

void drawClock() {
  char l0[17], l1[17];
  struct tm t;
  if (localTime(&t)) strftime(l0, sizeof(l0), "%H:%M %a %d %b", &t);
  else snprintf(l0, sizeof(l0), "Clock syncing...");
  if (mqtt_connected) snprintf(l1, sizeof(l1), "%c Cloud online", ICON_ONLINE);
  else snprintf(l1, sizeof(l1), "%c Offline: local", ICON_OFFLINE);
  showScreen(l0, l1);
}

void drawDose() {
  char l0[17];
  snprintf(l0, sizeof(l0), "%c Daily dose?", ICON_BELL);
  showScreen(l0, "Log it in app");
}

/** Next page in the rotation; the dose page only while a dose is due. */
int nextPage(int current, bool doseDue) {
  int last = doseDue ? PAGE_DOSE : PAGE_CLOCK;
  return current >= last ? PAGE_AIR : current + 1;
}

// --- Priority screens ---
/** Alarm screen, e.g. "!! DUST HIGH !!" over "38 > 35" (the reading and its limit). */
void drawAlarm(const char *title, float value, float limit, int decimals, const char *unit) {
  char l0[17], l1[17], text[17], v[8], lim[8];
  snprintf(text, sizeof(text), "!! %s !!", title);
  centred(l0, text);
  fmtValue(v, sizeof(v), value, decimals);
  fmtValue(lim, sizeof(lim), limit, decimals);
  snprintf(text, sizeof(text), "%s%s > %s", v, unit, lim);
  centred(l1, text);
  showScreen(l0, l1);
}

void drawCough(unsigned long since) {
  // A heartbeat-like pulse moving across the bottom line.
  static const char pulse[] = "__-^v-__";
  char l0[17], l1[17];
  snprintf(l0, sizeof(l0), "%c Cough detected", ICON_BELL);
  int offset = (since / 120) % 24;
  for (int i = 0; i < 16; i++) {
    int p = i - offset + 8;
    l1[i] = (p >= 0 && p < 8) ? pulse[p] : '_';
  }
  l1[16] = '\0';
  showScreen(l0, l1);
}

void drawConnecting() {
  static const char spin[] = ".oOo";
  char l0[17], l1[17];
  snprintf(l0, sizeof(l0), "RespiroSync %c", ICON_HEART);
  snprintf(l1, sizeof(l1), "Connecting %c", spin[(millis() / 250) % 4]);
  showScreen(l0, l1);
}

/** Draw whatever matters most right now. Called every loop; only changes reach the LCD. */
void updateDisplay(bool envAlarmNow, const char *alarmKey) {
  portENTER_CRITICAL(&thresholdsMux);
  screenLimits = thresholds;
  portEXIT_CRITICAL(&thresholdsMux);
  const Thresholds &t = screenLimits;
  unsigned long now = millis();

  if (mqtt_connected && !everConnected) {
    everConnected = true;
    readyUntil = now + 1500;
  }

  // A new or different alarm: blink the backlight 3 times, and keep the screen lit a while.
  if (strcmp(alarmKey, lastAlarmKey) != 0) {
    if (alarmKey[0]) alarmFlashUntil = now + 1200;
    strncpy(lastAlarmKey, alarmKey, sizeof(lastAlarmKey) - 1);
  }
  bool coughing = coughShownAt && now - coughShownAt < COUGH_SCREEN_MS;
  bool urgent = envAlarmNow || cloudAlarm || coughing;
  if (urgent) wakeUntil = now + WAKE_MS;

  // Backlight: blinking for a new alarm, else off at night unless woken.
  if (now < alarmFlashUntil) setBacklight(((alarmFlashUntil - now) / 200) % 2 == 0);
  else setBacklight(!isNight() || now < wakeUntil);

  if (coughing) { drawCough(now - coughShownAt); return; }
  if (envAlarmNow) {
    if (!strcmp(alarmKey, "PM25")) drawAlarm("DUST HIGH", reading.pm25, t.pm25, 0, "");
    else if (!strcmp(alarmKey, "GAS")) drawAlarm("GAS HIGH", reading.gas, t.mq135, 0, "");
    else if (!strcmp(alarmKey, "TEMP")) drawAlarm("TOO HOT", reading.temp, t.temperature, 1, "C");
    else drawAlarm("TOO HUMID", reading.hum, t.humidity, 0, "%");
    return;
  }
  if (cloudAlarm) {
    char l0[17];
    centred(l0, "!! ALERT !!");
    showScreen(l0, "From the app");
    return;
  }
  if (!everConnected && now < 20000) { drawConnecting(); return; }
  if (now < readyUntil) {
    char l0[17];
    snprintf(l0, sizeof(l0), "Ready %c", ICON_HEART);
    showScreen(l0, "RespiroSync");
    return;
  }

  if (now - pageStartedAt >= PAGE_MS) {
    page = nextPage(page, t.doseDue);
    pageStartedAt = now;
  }
  if (page == PAGE_DOSE && !t.doseDue) page = PAGE_AIR;
  switch (page) {
    case PAGE_AIR: drawAir(); break;
    case PAGE_BAR: drawBar(); break;
    case PAGE_GAS: drawGas(); break;
    case PAGE_CLOCK: drawClock(); break;
    default: drawDose(); break;
  }
}

/** BOOT button: lights the screen (night mode) and shows the next page. */
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

/** Start-up: "RespiroSync" slides in, with a heart. */
void bootAnimation() {
  const char *name = "RespiroSync";
  for (int x = 16; x >= 2; x--) {
    char l0[17];
    memset(l0, ' ', 16);
    l0[16] = '\0';
    for (int i = 0; name[i] && x + i < 16; i++) l0[x + i] = name[i];
    if (x == 2) l0[14] = ICON_HEART;
    showLine(0, l0);
    delay(60);
  }
}

void factoryReset() {
  Serial.println("Factory reset: wiping token and Wi-Fi settings");
  setBacklight(true);
  showScreen("Factory reset...", "");
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
  showLine(1, "Connecting WiFi");

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
    showScreen("WiFi setup mode", "Join RespiroSync");  // the "RespiroSync-Setup" network
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

  // Internet clock for the clock page and night mode (Malaysia, UTC+8, no daylight saving).
  configTzTime("MYT-8", "pool.ntp.org", "time.google.com");

  char l1[17];
  snprintf(l1, sizeof(l1), "WiFi connected %c", ICON_HEART);
  showLine(1, l1);
  wakeUntil = millis() + WAKE_MS;  // lit for a while after a restart, also at night
}

unsigned long lastTelemetryMillis = 0;
const unsigned long TELEMETRY_INTERVAL_MS = 3000;  // DHT22 needs >= 2 s between reads
unsigned long coughDisplayUntil = 0;
bool envAlarm = false;
char alarmKey[8] = "";  // PM25, GAS, TEMP, HUM or empty

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
  float gasPpm = readMq135Ppm();
  if (!isnan(gasPpm)) gasPpm = gasSmoother.add(gasPpm);
  // Raw values for wiring checks and calibration.
  Serial.printf("Dust: %u mV at pin (%.2f V at sensor, clean-air baseline %.2f V) -> %.1f ug/m3 | MQ-135 raw %d -> %.0f ppm est.\n",
                (unsigned)lastDustPinMv, lastDustPinMv / 1000.0f * DUST_DIVIDER_RATIO, dustBaselineV, pm25, lastMq135Raw, gasPpm);

  Doc doc;
  doc["pm25_level"] = pm25;
  if (isnan(gasPpm)) doc["mq135_level"] = (const char *)nullptr;  // JSON null: no gas signal
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

  // The first reading over its limit, as a short key for the alarm screen (updateDisplay).
  alarmKey[0] = '\0';
  if (pm25 > t.pm25) strcpy(alarmKey, "PM25");
  else if (!isnan(gasPpm) && gasPpm > t.mq135) strcpy(alarmKey, "GAS");
  else if (!isnan(temp) && temp > t.temperature) strcpy(alarmKey, "TEMP");
  else if (!isnan(hum) && hum > t.humidity) strcpy(alarmKey, "HUM");
  envAlarm = alarmKey[0] != '\0';
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
  updateDisplay(envAlarm, alarmKey);
  delay(10);
}
