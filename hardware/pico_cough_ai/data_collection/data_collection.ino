/*
 * RespiroSync - Phase 2 AI: Data Collection Firmware
 * 
 * Purpose: This script reads raw audio data from the INMP441 I2S microphone 
 * and streams it directly to the Serial port. 
 * 
 * Usage: Flash this to the Pico, then run the Edge Impulse CLI on your PC:
 * "edge-impulse-data-forwarder --frequency 16000"
 */

#include <I2S.h>

#define I2S_WS 13
#define I2S_SCK 14
#define I2S_SD 15
const int SAMPLE_RATE = 16000;
const int BITS_PER_SAMPLE = 32;

void setup() {
  Serial.begin(115200);
  
  // Wait for Serial to connect so Edge Impulse doesn't miss the start
  while (!Serial);

  I2S.setBCLK(I2S_SCK);
  I2S.setDATA(I2S_SD);
  I2S.setBitsPerSample(BITS_PER_SAMPLE);

  if (!I2S.begin(I2S_PHILIPS_MODE, SAMPLE_RATE)) {
    Serial.println("Failed to initialize I2S!");
    while (1);
  }
}

void loop() {
  int32_t sample = 0;
  
  // Read raw audio sample from microphone
  I2S.read(); // dummy read for 32-bit alignment if necessary
  sample = I2S.read(); // Get actual data
  
  // Shift it down to a 16-bit range for Edge Impulse compatibility
  int16_t outSample = sample >> 12;
  
  // Print raw value to serial plotter / data forwarder
  Serial.println(outSample);
}
