/*
 * RespiroSync - Phase 2 AI: audio data collection (Raspberry Pi Pico, arduino-pico core)
 *
 * Streams raw 16 kHz audio from the INMP441 over USB serial, one 16-bit sample per line.
 * Usage: flash this, then on your PC run:
 *   edge-impulse-data-forwarder --frequency 16000
 *
 * Wiring is the same as pico_cough_ai.ino: SCK=GP14, WS=GP15, SD=GP13, L/R=GND.
 */

#include <I2S.h>

#define I2S_BCLK 14  // WS/LRCLK = GP15
#define I2S_DATA 13

I2S i2s(INPUT);

void setup() {
  Serial.begin(115200);
  while (!Serial) delay(10);  // wait for the forwarder to attach

  i2s.setBCLK(I2S_BCLK);
  i2s.setDATA(I2S_DATA);
  i2s.setBitsPerSample(32);
  i2s.setFrequency(16000);

  if (!i2s.begin()) {
    Serial.println("Failed to initialise I2S!");
    while (true) delay(1000);
  }
}

void loop() {
  int32_t left = 0, right = 0;
  i2s.read32(&left, &right);         // mic data is on the left slot (L/R = GND)
  Serial.println((int16_t)(left >> 16));  // top 16 bits
}
