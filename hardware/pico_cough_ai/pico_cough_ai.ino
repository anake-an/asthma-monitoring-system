/*
 * RespiroSync - Edge AI module (Raspberry Pi Pico)
 * Core:     Earle Philhower "arduino-pico" (Boards Manager: "Raspberry Pi Pico/RP2040")
 * Board:    Raspberry Pi Pico, Tools > Flash Size > "2MB (Sketch: 1MB, FS: 1MB)" (the FS half
 *           receives cloud updates; flash this once over USB with that setting)
 * Hardware: Raspberry Pi Pico (or Pico W) + INMP441 I2S microphone
 *
 * WHAT THIS IS (and is not):
 *   A sound-level heuristic. It flags short, loud bursts (at least 32 ms, ignoring the first
 *   3 s after power-on) and reports a
 *   "detection strength" between 0 and 1. It does NOT tell a cough apart from a
 *   clap, a door slam or a shout. See README "Phase 2" for a trained classifier.
 *   The Pico's LED blinks once for every detection sent to the ESP32.
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
 *   Pico -> ESP32: COUGH:<level 1-4>,<strength 0.00-1.00>
 *                  HELLO:<version> build <build>      (at start-up, and when asked with VER?)
 *   ESP32 -> Pico: VER?
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

// Raise for every build you publish; CI adds the build stamp (build_info.h). See FIRMWARE_HISTORY.md.
#define EDGE_AI_VERSION "2.0.0"
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

const int SAMPLE_RATE = 16000;
const int FRAMES_PER_BLOCK = 256;  // 16 ms per analysis block

// RMS level (24-bit full scale = 8388608) that counts as a burst.
// INMP441: 94 dB SPL ~ -26 dBFS. 50,000 ~ -44.5 dBFS ~ 75 dB SPL at the mic.
// Calibrate in your room with DEBUG_LEVELS 1 (Serial Plotter) and adjust.
const float RMS_THRESHOLD = 50000.0f;
const unsigned long DEBOUNCE_MS = 1500;    // one cough = one event
const unsigned long SETTLE_MS = 3000;      // ignore sound for this long after power-on
const int MIN_LOUD_BLOCKS = 2;             // a burst must last 2 blocks (32 ms); a cough lasts 200-500 ms
#define DEBUG_LEVELS 0

I2S i2s(INPUT);
bool micReady = false;
unsigned long lastCoughTime = 0;
int loudBlocks = 0;  // consecutive blocks above the threshold

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

// ---------- Cloud updates over the UART link ----------
// CRC-16/CCITT; start with crc = 0xFFFF (no default argument: the Arduino builder repeats it in its prototype).
uint16_t crc16(const uint8_t *data, size_t len, uint16_t crc) {
  for (size_t i = 0; i < len; i++) {
    crc ^= (uint16_t)data[i] << 8;
    for (int b = 0; b < 8; b++) crc = (crc & 0x8000) ? (crc << 1) ^ 0x1021 : crc << 1;
  }
  return crc;
}

bool readExact(uint8_t *buf, size_t n, unsigned long timeoutMs) {
  Serial1.setTimeout(timeoutMs);
  return Serial1.readBytes(buf, n) == n;
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

  Serial1.setTimeout(10000);
  String end = Serial1.readStringUntil('\n');
  end.trim();
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

/** Lines from the ESP32: "VER?" or "UPD:<size>". */
void handleLink() {
  if (!Serial1.available()) return;
  Serial1.setTimeout(50);
  String line = Serial1.readStringUntil('\n');
  line.trim();
  if (line == "VER?") sayHello();
  else if (line.startsWith("UPD:")) receiveUpdate((size_t)line.substring(4).toInt());
}

void setup() {
  Serial.begin(115200);         // USB debug
  Serial1.setFIFOSize(1024);    // room for whole update frames
  Serial1.begin(LINK_BAUD);     // UART0 on GP0/GP1 -> ESP32
  pinMode(LED_BUILTIN, OUTPUT);
  LittleFS.begin();             // the FS area that receives cloud updates
  Serial.println(EDGE_AI_TAG);

  i2s.setBCLK(I2S_BCLK);
  i2s.setDATA(I2S_DATA);
  i2s.setBitsPerSample(32);  // INMP441 sends 24-bit samples in 32-bit slots
  i2s.setFrequency(SAMPLE_RATE);

  micReady = i2s.begin();
  if (micReady) {
    // Pull the data line down: while the microphone starts (or if it is missing) the line would
    // float and pick up noise that looks like a loud burst. The INMP441 datasheet asks for this too.
    gpio_pull_down(I2S_DATA);
    Serial.println("I2S microphone ready. Listening...");
  } else {
    // Keep answering the ESP32 (version, updates) even without the microphone.
    Serial.println("Failed to initialise I2S. Check INMP441 wiring (SCK=GP14, WS=GP15, SD=GP13).");
  }
  sayHello();
}

void loop() {
  handleLink();
  if (!micReady) {
    delay(10);
    return;
  }

  int64_t sum = 0;
  int64_t sumSq = 0;

  for (int i = 0; i < FRAMES_PER_BLOCK; i++) {
    int32_t left = 0, right = 0;
    i2s.read32(&left, &right);  // blocking; L/R tied to GND => data is on the left slot
    int32_t s = left >> 8;      // 24-bit signed sample
    sum += s;
    sumSq += (int64_t)s * s;    // 64-bit: no overflow (max 2^46 per sample)
  }

  // RMS with the DC offset removed
  double mean = (double)sum / FRAMES_PER_BLOCK;
  double variance = (double)sumSq / FRAMES_PER_BLOCK - mean * mean;
  float rms = variance > 0 ? (float)sqrt(variance) : 0.0f;

#if DEBUG_LEVELS
  Serial.println(rms);
#endif

  if (rms < RMS_THRESHOLD) {
    loudBlocks = 0;
    return;
  }
  // Not during start-up, and not for a single 16 ms spike (electrical noise, a click).
  if (millis() < SETTLE_MS || ++loudBlocks < MIN_LOUD_BLOCKS) return;

  unsigned long now = millis();
  if (now - lastCoughTime < DEBOUNCE_MS) return;
  lastCoughTime = now;

  // Strength: 0 at the threshold, 1 at 8x the threshold (+18 dB). A heuristic, not a probability.
  float ratio = rms / RMS_THRESHOLD;
  float strength = log2f(ratio) / 3.0f;
  if (strength < 0) strength = 0;
  if (strength > 1) strength = 1;

  int level = 1;
  if (ratio >= 2) level = 2;
  if (ratio >= 4) level = 3;
  if (ratio >= 6) level = 4;

  sendCough(level, strength);
}
