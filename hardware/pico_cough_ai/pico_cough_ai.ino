/*
 * RespiroSync - Edge AI module (Raspberry Pi Pico)
 * Core:     Earle Philhower "arduino-pico" (Boards Manager: "Raspberry Pi Pico/RP2040")
 * Board:    Raspberry Pi Pico, Tools > Flash Size > "2MB (Sketch: 1MB, FS: 1MB)" (the FS half
 *           receives cloud updates; flash this once over USB with that setting)
 * Hardware: Raspberry Pi Pico (or Pico W) + INMP441 I2S microphone
 * Library:  RespiroSync_Cough_inferencing (the Edge Impulse export, in hardware/libraries/; for the
 *           Arduino IDE copy that folder into Documents/Arduino/libraries)
 *
 * WHAT THIS DOES:
 *   An Edge Impulse model (Audio MFE + small 1D CNN, int8) tells a cough apart from other sounds
 *   (talking, TV, laughing, throat clearing, traffic). Core 1 records the microphone non-stop;
 *   core 0 classifies the last second of sound every 0.25 s (about 140 ms of work). A cough is
 *   reported when at least two of the last three windows reach the room's threshold (0.6 until the
 *   ESP32 sends the room's value, which follows the cough reviews). A score a little under it, in a
 *   window with a clear burst of sound, is reported as a possible cough for review. A window with a
 *   burst is first scaled into the loudness band the model learned (level guard). The first 3 s
 *   after power-on are ignored; a watchdog restarts the Pico if it hangs. Training data and
 *   results: docs/EDGE_IMPULSE.md. The Pico's LED blinks once for every detection sent to the ESP32.
 *
 * Cloud updates: the ESP32 downloads a new build from the server and sends it over the UART
 * link (UPDATE PROTOCOL below); this firmware stores it in the FS area and installs it on
 * restart (arduino-pico Updater). Version: EDGE_AI_VERSION, history in hardware/FIRMWARE_HISTORY.md.
 *
 * Wiring (INMP441 -> Pico). arduino-pico requires LRCLK = BCLK + 1:
 *   VDD -> 3V3(OUT)      GND -> GND      L/R -> GND (left channel)
 *   SCK -> GP14 (BCLK)
 *   WS  -> GP15 (LRCLK)
 *   SD  -> GP13 (DATA)
 *
 * Wiring (Pico -> ESP32):
 *   GP0 (UART0 TX) -> ESP32 GPIO16 (RX2)
 *   GP1 (UART0 RX) -> ESP32 GPIO17 (TX2)
 *   GND            -> ESP32 GND (shared ground is required)
 *
 * UART link (115200 baud), one line per message:
 *   Pico -> ESP32: COUGH:<level 1-4 (loudness)>,<cough score 0.00-1.00>
 *                  MAYBE:<level>,<score>               (a possible cough, for review)
 *                  HELLO:<version> build <build>      (at start-up, and when asked with VER?)
 *   ESP32 -> Pico: VER?
 *                  THR:<score 0.30-0.95>                (the room's cough threshold)
 *
 * UPDATE PROTOCOL (ESP32 -> Pico):
 *   "UPD:<size>"  -> Pico: "READY" (or "ERR:<why>")
 *   frames        0xA5, seq(2), len(2), data[len <= 256], crc16(2)  (CRC-16/CCITT over seq,len,data)
 *                 -> Pico: "K" (written, or a repeat of the last frame) or "N" (send it again)
 *   "END"         -> Pico: "DONE", then restarts into the new build
 *   "ABORT"       -> nothing is installed
 */

#include <I2S.h>
#include <LittleFS.h>
#include <Updater.h>
#include <math.h>
#include <RespiroSync_Cough_inferencing.h>

// Raise for every build you publish; CI adds the build stamp (build_info.h). See FIRMWARE_HISTORY.md.
#define EDGE_AI_VERSION "3.2.0"
#if __has_include("build_info.h")
#include "build_info.h"
#endif
#ifndef EDGE_AI_BUILD
#define EDGE_AI_BUILD "dev"
#endif
// Read by the server from the .bin when it is published: keep this format.
const char EDGE_AI_TAG[] = "RespiroSync-edge-ai:" EDGE_AI_VERSION " build " EDGE_AI_BUILD;

#define I2S_BCLK 14  // LRCLK/WS is automatically BCLK + 1 = GP15
#define I2S_DATA 13
const unsigned long LINK_BAUD = 115200;

const int SAMPLE_RATE = EI_CLASSIFIER_FREQUENCY;     // 16 kHz, as the model was trained
const int WINDOW = EI_CLASSIFIER_RAW_SAMPLE_COUNT;   // 1 s of sound per classification
const int STEP = WINDOW / 4;                         // a new classification every 0.25 s (~140 ms of work each)

// INMP441 samples are 24-bit in a 32-bit slot. Shifting right by 14 gives 16-bit samples with
// 4x gain. Measured on the device: at 8x a cough at arm's length reached ~10,000 (loudest 16 ms)
// and the model scored it 0.00; at 1x it sank to the noise floor; at 4x coughs land near the
// level of the training coughs. Keep it equal when recording training data (record_audio.ps1
// records exactly what the model hears).
const int MIC_SHIFT = 14;
// Cough score (averaged over two windows in a row) needed to report a cough. Starts at the model's
// own 0.6; the ESP32 sends the room's value ("THR:<score>"), which follows the cough reviews.
float coughThreshold = 0.6f;
// Possible coughs: a score within POSSIBLE_MARGIN under the threshold, in a window with a burst
// (loudest 16 ms >= BURST_FACTOR x the typical level), at most one per POSSIBLE_GAP_MS.
const float POSSIBLE_MARGIN = 0.15f;
const float BURST_FACTOR = 4.0f;
const unsigned long POSSIBLE_GAP_MS = 20000;
const unsigned long DEBOUNCE_MS = 1500;    // one cough = one event
const unsigned long SETTLE_MS = 3000;      // ignore sound for this long after power-on
// Loudness level 1-4 of a cough: the loudest 16 ms of the window against this RMS (16-bit,
// about 75 dB SPL at the mic); level 2 from 2x, 3 from 4x, 4 from 6x.
const float LEVEL_RMS = 1500.0f;
// Level guard (levelGuard): the band of loudness the training coughs had, and how far a window
// with a burst may be scaled into it.
const float LEVEL_LOW = 2500.0f, LEVEL_HIGH = 12000.0f;
const float LEVEL_MAX_GAIN = 6.0f, LEVEL_MIN_GAIN = 0.33f;
const uint32_t WATCHDOG_MS = 8000;  // the Pico restarts when its main loop stops for this long

I2S i2s(INPUT);
volatile bool micReady = false;
volatile bool micChecked = false;   // core 1 has tried to start the microphone
unsigned long lastCoughTime = 0;

// Core 1 writes the microphone into this ring; core 0 copies the last second out of it.
// samplesRecorded only ever counts (it wraps after ~74 h: compare it by difference, never with <);
// ringPos is where the next sample goes, so the ring has no jump when the count wraps.
int16_t ring[WINDOW];
volatile uint32_t samplesRecorded = 0;
volatile uint32_t ringPos = 0;
int16_t window[WINDOW];             // the second being classified

void sayHello() {
  Serial1.print("HELLO:");
  Serial1.print(EDGE_AI_VERSION);
  Serial1.print(" build ");
  Serial1.println(EDGE_AI_BUILD);
}

/** Send one detection to the ESP32 and blink the LED. */
void sendCough(int level, float strength) {
  Serial1.print("COUGH:");
  Serial1.print(level);
  Serial1.print(",");
  Serial1.println(strength, 2);

  Serial.print("Burst detected: level ");
  Serial.print(level);
  Serial.print(", strength ");
  Serial.println(strength, 2);

  digitalWrite(LED_BUILTIN, HIGH);
  delay(60);
  digitalWrite(LED_BUILTIN, LOW);
}

/** Send a possible cough to the ESP32 (no LED blink: nothing was detected for sure). */
void sendPossible(int level, float score) {
  Serial1.print("MAYBE:");
  Serial1.print(level);
  Serial1.print(",");
  Serial1.println(score, 2);
  Serial.printf("Possible cough: level %d, score %.2f\n", level, score);
}

// ---------- Cloud updates over the UART link ----------
// CRC-16/CCITT; start with crc = 0xFFFF (no default argument: the Arduino builder repeats it in its prototype).
uint16_t crc16(const uint8_t *data, size_t len, uint16_t crc) {
  for (size_t i = 0; i < len; i++) {
    crc ^= (uint16_t)data[i] << 8;
    for (int b = 0; b < 8; b++) crc = (crc & 0x8000) ? (crc << 1) ^ 0x1021 : crc << 1;
  }
  return crc;
}

/** Read exactly n bytes from the ESP32 within timeoutMs, feeding the watchdog while waiting. */
bool readExact(uint8_t *buf, size_t n, unsigned long timeoutMs) {
  size_t got = 0;
  unsigned long start = millis();
  while (got < n) {
    int c = Serial1.read();
    if (c >= 0) {
      buf[got++] = (uint8_t)c;
      continue;
    }
    if (millis() - start >= timeoutMs) return false;
    rp2040.wdt_reset();  // long waits are normal during an update
  }
  return true;
}

/** One line from the ESP32 (without the newline) within timeoutMs, feeding the watchdog. */
String readLineWaiting(unsigned long timeoutMs) {
  String line;
  unsigned long start = millis();
  while (millis() - start < timeoutMs) {
    int c = Serial1.read();
    if (c < 0) {
      rp2040.wdt_reset();
      continue;
    }
    if (c == '\n') break;
    line += (char)c;
  }
  line.trim();
  return line;
}

/** Receive a new build ("UPD:<size>" was just read) and install it, or leave everything as is. */
void receiveUpdate(size_t size) {
  if (size == 0 || !Update.begin(size)) {
    Serial1.println("ERR:no room for the update (Flash Size must be 2MB with 1MB FS)");
    return;
  }
  Serial.printf("Receiving an update: %u bytes\n", (unsigned)size);
  Serial1.println("READY");
  digitalWrite(LED_BUILTIN, HIGH);

  size_t got = 0;
  uint16_t expected = 0;
  uint8_t head[4], data[256], tail[2];
  while (got < size) {
    uint8_t start;
    if (!readExact(&start, 1, 15000)) {
      Serial.println("Update: the ESP32 stopped sending");
      Serial1.println("ERR:timeout");
      digitalWrite(LED_BUILTIN, LOW);
      return;
    }
    if (start != 0xA5) continue;
    if (!readExact(head, 4, 2000)) { Serial1.println("N"); continue; }
    uint16_t seq = (head[0] << 8) | head[1];
    uint16_t len = (head[2] << 8) | head[3];
    if (len == 0 || len > sizeof(data) || !readExact(data, len, 2000) || !readExact(tail, 2, 2000)) {
      Serial1.println("N");
      continue;
    }
    uint16_t crc = crc16(data, len, crc16(head, 4, 0xFFFF));
    if (crc != ((tail[0] << 8) | tail[1])) { Serial1.println("N"); continue; }
    if (seq == (uint16_t)(expected - 1)) { Serial1.println("K"); continue; }  // our "K" got lost: already written
    if (seq != expected) { Serial1.println("N"); continue; }
    if (Update.write(data, len) != len) {
      Serial1.println("ERR:write failed");
      digitalWrite(LED_BUILTIN, LOW);
      return;
    }
    got += len;
    expected++;
    Serial1.println("K");
  }

  String end = readLineWaiting(10000);
  digitalWrite(LED_BUILTIN, LOW);
  if (end != "END") {
    Serial.println("Update aborted: nothing installed");
    Serial1.println("ERR:aborted");
    return;
  }
  if (!Update.end(true)) {
    Serial1.println("ERR:the update could not be installed");
    return;
  }
  Serial.println("Update stored: restarting to install it");
  Serial1.println("DONE");
  Serial1.flush();
  delay(200);
  rp2040.reboot();
}

/** Lines from the ESP32: "VER?", "UPD:<size>" or "THR:<score>". */
void handleLink() {
  if (!Serial1.available()) return;
  Serial1.setTimeout(50);
  String line = Serial1.readStringUntil('\n');
  line.trim();
  if (line == "VER?") sayHello();
  else if (line.startsWith("UPD:")) receiveUpdate((size_t)line.substring(4).toInt());
  else if (line.startsWith("THR:")) {
    float t = line.substring(4).toFloat();
    if (t >= 0.3f && t <= 0.95f) {
      coughThreshold = t;
      Serial.printf("Cough threshold set to %.2f\n", t);
    }
  }
}

void setup() {
  Serial.begin(115200);         // USB debug
  Serial1.setFIFOSize(1024);    // room for whole update frames
  Serial1.begin(LINK_BAUD);     // UART0 on GP0/GP1 -> ESP32
  pinMode(LED_BUILTIN, OUTPUT);
  LittleFS.begin();             // the FS area that receives cloud updates
  Serial.println(EDGE_AI_TAG);

  while (!micChecked) delay(1);  // core 1 starts the microphone
  if (micReady) Serial.println("I2S microphone ready. Listening for coughs...");
  // Keep answering the ESP32 (version, updates) even without the microphone.
  else Serial.println("Failed to initialise I2S. Check INMP441 wiring (SCK=GP14, WS=GP15, SD=GP13).");
  sayHello();
  // A hang (the classifier, the link, or recording stuck on core 1) restarts the Pico; the ESP32
  // then sees its hello again. Fed by feedWatchdog() in loop() and while an update is received.
  rp2040.wdt_begin(WATCHDOG_MS);
}

// ---------- Core 1: records the microphone without a break ----------
// 2nd-order Butterworth high-pass at HIGH_PASS_HZ. It removes mains hum (50 Hz and its harmonics,
// measured on the first device at about 10x the level of everything above 1 kHz), rumble and the
// microphone's DC offset. A cough's sound is mostly 300-4000 Hz.
const float HIGH_PASS_HZ = 200.0f;
float hpB0, hpB1, hpB2, hpA1, hpA2;

void setup1() {
  const float w0 = 2.0f * (float)M_PI * HIGH_PASS_HZ / SAMPLE_RATE;
  const float alpha = sinf(w0) / (2.0f * 0.70710678f);  // Q = 1/sqrt(2)
  const float a0 = 1.0f + alpha;
  hpB0 = (1.0f + cosf(w0)) / 2.0f / a0;
  hpB1 = -2.0f * hpB0;
  hpB2 = hpB0;
  hpA1 = -2.0f * cosf(w0) / a0;
  hpA2 = (1.0f - alpha) / a0;

  i2s.setBCLK(I2S_BCLK);
  i2s.setDATA(I2S_DATA);
  i2s.setBitsPerSample(32);  // INMP441 sends 24-bit samples in 32-bit slots
  i2s.setFrequency(SAMPLE_RATE);
  bool ok = i2s.begin();
  // Pull the data line down: while the microphone starts (or if it is missing) the line would
  // float and pick up noise. The INMP441 datasheet asks for this too.
  if (ok) gpio_pull_down(I2S_DATA);
  micReady = ok;
  micChecked = true;
}

void loop1() {
  if (!micReady) {
    delay(100);
    return;
  }
  static float x1 = 0, x2 = 0, y1 = 0, y2 = 0;  // high-pass filter state
  int32_t left = 0, right = 0;
  i2s.read32(&left, &right);         // blocking; L/R tied to GND => data is on the left slot
  float x = (float)(left >> 8);      // 24-bit signed sample
  float y = hpB0 * x + hpB1 * x1 + hpB2 * x2 - hpA1 * y1 - hpA2 * y2;
  x2 = x1;
  x1 = x;
  y2 = y1;
  y1 = y;
  int32_t s = (int32_t)(y * (1.0f / (1 << (MIC_SHIFT - 8))));  // to 16-bit with gain
  if (s > 32767) s = 32767;
  if (s < -32768) s = -32768;
  uint32_t pos = ringPos;
  ring[pos] = (int16_t)s;
  ringPos = pos + 1 == (uint32_t)WINDOW ? 0 : pos + 1;
  samplesRecorded = samplesRecorded + 1;
}

// ---------- Core 0: classifies, talks to the ESP32 ----------
/** Repeat the version every 10 minutes, so the ESP32 knows it even if one of them restarted alone. */
void helloNowAndThen() {
  static unsigned long last = 0;
  if (millis() - last < 600000UL) return;
  last = millis();
  sayHello();
}

/** Audio for the Edge Impulse classifier, as floats. */
int getAudio(size_t offset, size_t length, float *out) {
  numpy::int16_to_float(&window[offset], out, length);
  return 0;
}

/**
 * True when the window holds a clear burst: its loudest 16 ms block is at least BURST_FACTOR times
 * its typical (median) block. Keeps steady sounds and hum from becoming possible coughs.
 */
bool hasBurst() {
  const int blocks = WINDOW / 256;
  static float rms[WINDOW / 256];
  for (int b = 0; b < blocks; b++) {
    int64_t sumSq = 0;
    for (int i = b * 256; i < (b + 1) * 256; i++) sumSq += (int32_t)window[i] * window[i];
    rms[b] = sqrtf((float)sumSq / 256);
  }
  float loudest = 0;
  for (int b = 0; b < blocks; b++) loudest = max(loudest, rms[b]);
  // Median by insertion sort (62 values)
  for (int i = 1; i < blocks; i++) {
    float v = rms[i];
    int j = i - 1;
    while (j >= 0 && rms[j] > v) {
      rms[j + 1] = rms[j];
      j--;
    }
    rms[j + 1] = v;
  }
  return loudest >= BURST_FACTOR * max(rms[blocks / 2], 1.0f);
}

/** RMS of the loudest 16 ms block of the window (16-bit samples). */
float loudestRms() {
  float loudest = 0;
  for (int b = 0; b + 256 <= WINDOW; b += 256) {
    int64_t sumSq = 0;
    for (int i = b; i < b + 256; i++) sumSq += (int32_t)window[i] * window[i];
    float rms = sqrtf((float)sumSq / 256);
    if (rms > loudest) loudest = rms;
  }
  return loudest;
}

/** Loudness level 1-4 from the loudest 16 ms block of the window (as heard, before the level guard). */
int loudnessFrom(float loudest) {
  float ratio = loudest / LEVEL_RMS;
  if (ratio >= 6) return 4;
  if (ratio >= 4) return 3;
  if (ratio >= 2) return 2;
  return 1;
}

/**
 * USB command "rec <seconds>" (1-60): stream that much raw audio, exactly as the model hears it
 * (16-bit little-endian, 16 kHz), framed as "REC <samples>\n" + data + "\nEND\n". Used by
 * hardware/tools/record_audio.ps1 to record training data with this microphone.
 */
void handleUsbCommand() {
  if (!Serial.available()) return;
  Serial.setTimeout(50);
  String cmd = Serial.readStringUntil('\n');
  cmd.trim();
  if (!cmd.startsWith("rec ") || !micReady) return;
  long seconds = cmd.substring(4).toInt();
  if (seconds < 1 || seconds > 60) return;
  const uint32_t total = (uint32_t)seconds * SAMPLE_RATE;
  Serial.printf("REC %lu\n", (unsigned long)total);
  uint32_t idx = ringPos;            // the next sample core 1 writes
  uint32_t counted = samplesRecorded;
  uint32_t sent = 0;
  while (sent < total) {
    rp2040.wdt_reset();
    uint32_t avail = samplesRecorded - counted;  // new samples since the last chunk (wrap-safe)
    if (avail == 0) {
      delay(1);
      continue;
    }
    if (avail > (uint32_t)WINDOW / 2) {  // USB fell behind: skip ahead
      idx = (idx + avail - WINDOW / 2) % WINDOW;
      counted += avail - WINDOW / 2;
      avail = WINDOW / 2;
    }
    uint32_t run = min(avail, (uint32_t)(WINDOW - idx));
    run = min(run, total - sent);
    run = min(run, (uint32_t)512);
    Serial.write((const uint8_t *)(ring + idx), run * sizeof(int16_t));
    idx = (idx + run) % WINDOW;
    counted += run;
    sent += run;
  }
  Serial.print("\nEND\n");
}

/**
 * Level guard: the model recognises coughs near the loudness it learned (training coughs: loudest
 * 16 ms about LEVEL_LOW-LEVEL_HIGH at this gain). A window with a clear burst outside that band is
 * scaled into it (LEVEL_MIN_GAIN to LEVEL_MAX_GAIN), so distance and microphone placement matter
 * less. Windows without a burst (a quiet room, steady sound) are left as they are.
 */
void levelGuard(float loudest, bool burst) {
  if (!burst || loudest <= 0) return;
  float gain = 1.0f;
  if (loudest < LEVEL_LOW) gain = min(LEVEL_LOW / loudest, LEVEL_MAX_GAIN);
  else if (loudest > LEVEL_HIGH) gain = max(LEVEL_HIGH / loudest, LEVEL_MIN_GAIN);
  if (gain == 1.0f) return;
  for (int i = 0; i < WINDOW; i++) {
    float v = window[i] * gain;
    window[i] = (int16_t)(v > 32767.0f ? 32767.0f : (v < -32768.0f ? -32768.0f : v));
  }
}

/** Feed the watchdog while core 1 keeps recording (or there is no microphone to record from). */
void feedWatchdog() {
  static uint32_t lastCount = 0;
  static unsigned long lastProgressAt = 0;
  uint32_t count = samplesRecorded;
  if (!micReady || count != lastCount) {
    lastCount = count;
    lastProgressAt = millis();
  }
  // Recording stuck for 3 s: stop feeding, and the watchdog restarts the Pico.
  if (millis() - lastProgressAt < 3000) rp2040.wdt_reset();
}

void loop() {
  feedWatchdog();
  handleLink();
  handleUsbCommand();
  helloNowAndThen();
  if (!micReady) {
    delay(10);
    return;
  }

  // Wait for STEP of new sound (and a full second at the start). Compared by difference, so the
  // sample count wrapping after ~74 h changes nothing.
  static uint32_t nextAt = WINDOW;
  uint32_t recorded = samplesRecorded;
  if ((int32_t)(recorded - nextAt) < 0) {
    delay(2);
    return;
  }
  nextAt = recorded + STEP;  // if classifying ever takes longer, skip ahead instead of falling behind

  // Copy the last second out of the ring, oldest sample (where core 1 writes next) first.
  uint32_t start = ringPos;
  memcpy(window, ring + start, (WINDOW - start) * sizeof(int16_t));
  memcpy(window + (WINDOW - start), ring, start * sizeof(int16_t));

  // Loudness as heard (for the report and the possible-cough check), then the level guard.
  float loudest = loudestRms();
  bool burst = hasBurst();
  levelGuard(loudest, burst);

  signal_t signal;
  signal.total_length = WINDOW;
  signal.get_data = &getAudio;
  ei_impulse_result_t result = { 0 };
  if (run_classifier(&signal, &result, false) != EI_IMPULSE_OK) return;

  float cough = 0;
  for (size_t i = 0; i < EI_CLASSIFIER_LABEL_COUNT; i++) {
    if (strcmp(result.classification[i].label, "cough") == 0) cough = result.classification[i].value;
  }
  // The last three windows (0.25 s apart, so a cough is in all three): a cough when at least two
  // reach the threshold. One odd window is not enough, and a short cough that one window catches
  // only partly is not averaged away. The score reported is the mean of the two highest.
  static float scores[3] = {0, 0, 0};
  scores[2] = scores[1];
  scores[1] = scores[0];
  scores[0] = cough;
  int over = (scores[0] >= coughThreshold) + (scores[1] >= coughThreshold) + (scores[2] >= coughThreshold);
  float lowest = min(scores[0], min(scores[1], scores[2]));
  float score = (scores[0] + scores[1] + scores[2] - lowest) / 2;

  // With a PC on the USB port (Serial Monitor at 115200 open), print what the microphone hears.
  // "loudest" near 0 all the time means no signal from the microphone: check its wiring.
  if (Serial) {
    Serial.printf("cough %.2f  top-two %.2f  loudest %5.0f%s  (%d ms)\n", cough, score, loudest, burst ? " burst" : "",
                  (int)(result.timing.dsp + result.timing.classification));
  }
  if (millis() < SETTLE_MS) return;

  unsigned long now = millis();
  if (over >= 2) {
    if (now - lastCoughTime < DEBOUNCE_MS) return;
    lastCoughTime = now;
    sendCough(loudnessFrom(loudest), score);
    return;
  }
  // A possible cough: a little under the threshold, in a window with a clear burst of sound.
  // Caregivers review it; confirmed ones make the room more sensitive (server side).
  static unsigned long lastPossibleTime = 0;
  if (score >= coughThreshold - POSSIBLE_MARGIN && burst && now - lastCoughTime >= DEBOUNCE_MS
      && (lastPossibleTime == 0 || now - lastPossibleTime >= POSSIBLE_GAP_MS)) {
    lastPossibleTime = now;
    sendPossible(loudnessFrom(loudest), score);
  }
}
