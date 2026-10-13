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
 *   core 0 classifies the last second of sound every half second (about 260 ms of work). A cough
 *   is reported when the cough score, averaged over two windows in a row, reaches 0.6; the first
 *   3 s after power-on are ignored. Training data and results: docs/EDGE_IMPULSE.md.
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
 *   Pico -> ESP32: COUGH:<level 1-4 (loudness)>,<cough score 0.00-1.00>
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
#include <RespiroSync_Cough_inferencing.h>

// Raise for every build you publish; CI adds the build stamp (build_info.h). See FIRMWARE_HISTORY.md.
#define EDGE_AI_VERSION "3.0.1"
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
const int STEP = WINDOW / 2;                         // a new classification every 0.5 s

// INMP441 samples are 24-bit in a 32-bit slot. Shifting right by 13 gives 16-bit samples with
// 8x gain: the INMP441 is quiet (94 dB SPL = -26 dBFS) next to the phone and lab recordings the
// model learnt from. Retune together with the model once it is trained on this microphone.
const int MIC_SHIFT = 13;
const float COUGH_THRESHOLD = 0.6f;        // cough score, averaged over two windows in a row
const unsigned long DEBOUNCE_MS = 1500;    // one cough = one event
const unsigned long SETTLE_MS = 3000;      // ignore sound for this long after power-on
// Loudness level 1-4 of a cough: the loudest 16 ms of the window against this RMS (16-bit,
// about 75 dB SPL at the mic); level 2 from 2x, 3 from 4x, 4 from 6x.
const float LEVEL_RMS = 1500.0f;

I2S i2s(INPUT);
volatile bool micReady = false;
volatile bool micChecked = false;   // core 1 has tried to start the microphone
unsigned long lastCoughTime = 0;

// Core 1 writes the microphone into this ring; core 0 copies the last second out of it.
int16_t ring[WINDOW];
volatile uint32_t samplesRecorded = 0;
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

  while (!micChecked) delay(1);  // core 1 starts the microphone
  if (micReady) Serial.println("I2S microphone ready. Listening for coughs...");
  // Keep answering the ESP32 (version, updates) even without the microphone.
  else Serial.println("Failed to initialise I2S. Check INMP441 wiring (SCK=GP14, WS=GP15, SD=GP13).");
  sayHello();
}

// ---------- Core 1: records the microphone without a break ----------
void setup1() {
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
  static int32_t dc = 0;  // the microphone's DC offset, followed slowly
  int32_t left = 0, right = 0;
  i2s.read32(&left, &right);         // blocking; L/R tied to GND => data is on the left slot
  int32_t s = left >> 8;             // 24-bit signed sample
  dc += (s - dc) >> 10;
  s = (s - dc) >> (MIC_SHIFT - 8);   // to 16-bit with gain
  if (s > 32767) s = 32767;
  if (s < -32768) s = -32768;
  ring[samplesRecorded % WINDOW] = (int16_t)s;
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

/** Loudness level 1-4 from the loudest 16 ms block of the window. */
int loudnessLevel() {
  float ratio = loudestRms() / LEVEL_RMS;
  if (ratio >= 6) return 4;
  if (ratio >= 4) return 3;
  if (ratio >= 2) return 2;
  return 1;
}

void loop() {
  handleLink();
  helloNowAndThen();
  if (!micReady) {
    delay(10);
    return;
  }

  // Wait for half a second of new sound (and a full second at the start).
  static uint32_t nextAt = WINDOW;
  uint32_t recorded = samplesRecorded;
  if (recorded < nextAt) {
    delay(2);
    return;
  }
  nextAt = recorded + STEP;  // if classifying ever takes longer, skip ahead instead of falling behind

  // Copy the last second out of the ring, oldest sample first.
  uint32_t start = recorded % WINDOW;
  memcpy(window, ring + start, (WINDOW - start) * sizeof(int16_t));
  memcpy(window + (WINDOW - start), ring, start * sizeof(int16_t));

  signal_t signal;
  signal.total_length = WINDOW;
  signal.get_data = &getAudio;
  ei_impulse_result_t result = { 0 };
  if (run_classifier(&signal, &result, false) != EI_IMPULSE_OK) return;

  float cough = 0;
  for (size_t i = 0; i < EI_CLASSIFIER_LABEL_COUNT; i++) {
    if (strcmp(result.classification[i].label, "cough") == 0) cough = result.classification[i].value;
  }
  // Two windows in a row (they overlap by half) must agree: one odd window is not enough.
  static float previous = 0;
  float score = (cough + previous) / 2;
  previous = cough;

  // With a PC on the USB port (Serial Monitor at 115200 open), print what the microphone hears.
  // "loudest" near 0 all the time means no signal from the microphone: check its wiring.
  if (Serial) {
    Serial.printf("cough %.2f  two-window %.2f  loudest %5.0f  (%d ms)\n", cough, score, loudestRms(),
                  (int)(result.timing.dsp + result.timing.classification));
  }
  if (score < COUGH_THRESHOLD || millis() < SETTLE_MS) return;

  unsigned long now = millis();
  if (now - lastCoughTime < DEBOUNCE_MS) return;
  lastCoughTime = now;
  sendCough(loudnessLevel(), score);
}
