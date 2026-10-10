/*
 * RespiroSync - Acoustic cough detector (Raspberry Pi Pico)
 * Core:     Earle Philhower "arduino-pico" (Boards Manager: "Raspberry Pi Pico/RP2040")
 * Hardware: Raspberry Pi Pico (or Pico W) + INMP441 I2S microphone
 *
 * WHAT THIS IS (and is not):
 *   A sound-level heuristic. It flags short, loud bursts and reports a
 *   "detection strength" between 0 and 1. It does NOT tell a cough apart from a
 *   clap, a door slam or a shout. See README "Phase 2" for a trained classifier.
 *
 * TEST MODE (works with or without the microphone):
 *   A push button between GP2 and GND sends a test cough to the ESP32:
 *     short press -> weak cough   (3 within 10 minutes raise a cough alert)
 *     long press  -> strong cough (2 within 10 minutes raise a cough alert)
 *   Or type in the Serial Monitor (115200 baud): c = weak cough, s = strong cough.
 *   The Pico's LED blinks once for every message sent. The server cannot tell a
 *   test cough from a real one: mark test events "false alarm", or clear them after a demo.
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
 * Test button: GP2 -> button -> GND (no resistor: the internal pull-up is used)
 *
 * UART message (9600 baud, one line per detection):
 *   COUGH:<level 1-4>,<strength 0.00-1.00>
 */

#include <I2S.h>
#include <math.h>

#define I2S_BCLK 14  // LRCLK/WS is automatically BCLK + 1 = GP15
#define I2S_DATA 13
#define TEST_BUTTON_PIN 2

const int SAMPLE_RATE = 16000;
const int FRAMES_PER_BLOCK = 256;  // 16 ms per analysis block

// RMS level (24-bit full scale = 8388608) that counts as a burst.
// INMP441: 94 dB SPL ~ -26 dBFS. 50,000 ~ -44.5 dBFS ~ 75 dB SPL at the mic.
// Calibrate in your room with DEBUG_LEVELS 1 (Serial Plotter) and adjust.
const float RMS_THRESHOLD = 50000.0f;
const unsigned long DEBOUNCE_MS = 1500;    // one cough = one event
const unsigned long LONG_PRESS_MS = 800;   // test button: held this long = strong cough
#define DEBUG_LEVELS 0

I2S i2s(INPUT);
bool micReady = false;
unsigned long lastCoughTime = 0;

/** Send one detection to the ESP32 and blink the LED. */
void sendCough(int level, float strength, const char *source) {
  Serial1.print("COUGH:");
  Serial1.print(level);
  Serial1.print(",");
  Serial1.println(strength, 2);

  Serial.print(source);
  Serial.print(": level ");
  Serial.print(level);
  Serial.print(", strength ");
  Serial.println(strength, 2);

  digitalWrite(LED_BUILTIN, HIGH);
  delay(60);
  digitalWrite(LED_BUILTIN, LOW);
}

/** Test button (GP2) and Serial Monitor commands. */
void handleTestInputs() {
  static bool wasDown = false;
  static unsigned long downAt = 0;
  bool down = digitalRead(TEST_BUTTON_PIN) == LOW;
  if (down && !wasDown) downAt = millis();
  if (!down && wasDown && millis() - downAt >= 30) {  // released (30 ms debounce)
    if (millis() - downAt >= LONG_PRESS_MS) sendCough(4, 0.95f, "Test button (long): strong cough");
    else sendCough(1, 0.20f, "Test button: weak cough");
  }
  wasDown = down;

  while (Serial.available()) {
    char c = Serial.read();
    if (c == 'c' || c == 'C') sendCough(1, 0.20f, "Test command: weak cough");
    else if (c == 's' || c == 'S') sendCough(4, 0.95f, "Test command: strong cough");
    else if (c == '?') Serial.println("Test mode: c = weak cough, s = strong cough (or the button on GP2: short / long press)");
  }
}

void setup() {
  Serial.begin(115200);  // USB debug and test commands
  Serial1.begin(9600);   // UART0 on GP0/GP1 -> ESP32
  pinMode(LED_BUILTIN, OUTPUT);
  pinMode(TEST_BUTTON_PIN, INPUT_PULLUP);

  i2s.setBCLK(I2S_BCLK);
  i2s.setDATA(I2S_DATA);
  i2s.setBitsPerSample(32);  // INMP441 sends 24-bit samples in 32-bit slots
  i2s.setFrequency(SAMPLE_RATE);

  micReady = i2s.begin();
  if (micReady) {
    Serial.println("I2S microphone ready. Listening... (test: c / s, or the GP2 button)");
  } else {
    Serial.println("I2S did not start: test mode only (c / s, or the GP2 button). Check INMP441 wiring (SCK=GP14, WS=GP15, SD=GP13).");
  }
}

void loop() {
  handleTestInputs();
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

  if (rms < RMS_THRESHOLD) return;

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

  sendCough(level, strength, "Burst detected");
}
