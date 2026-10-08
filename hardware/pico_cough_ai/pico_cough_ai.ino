/*
 * RespiroSync - Acoustic AI Cough Detection (Raspberry Pi Pico)
 * Hardware: Raspberry Pi Pico (Standard or W) + INMP441 I2S Microphone
 * 
 * Since we are not sure if you have a Pico or Pico W, we will use a highly robust UART Architecture!
 * The Pico will act purely as the "AI Audio Brain" and send signals to the ESP32.
 * The ESP32 remains the "Master Gateway" for all WiFi and MQTT traffic.
 * 
 * Wiring (INMP441 -> Pico):
 * VDD  -> 3.3V
 * GND  -> GND
 * L/R  -> GND (Sets to Left channel)
 * WS   -> GPIO 13 (Word Select / LRCLK)
 * SCK  -> GPIO 14 (Bit Clock / BCLK)
 * SD   -> GPIO 15 (Data)
 * 
 * Wiring (Pico -> ESP32 Gateway):
 * Pico GP0 (UART0 TX) -> ESP32 GPIO 16 (RX2)
 * Pico GP1 (UART0 RX) -> ESP32 GPIO 17 (TX2)
 * Pico GND            -> ESP32 GND (CRITICAL: Must share ground!)
 */

#include <I2S.h>

// I2S Pins for Pi Pico
#define I2S_WS 13
#define I2S_SCK 14
#define I2S_SD 15

// Acoustic tuning parameters
const int SAMPLE_RATE = 16000;
const int BITS_PER_SAMPLE = 32;
const int ENERGY_THRESHOLD = 5000000; // Adjust this sensitivity during testing!
const int DEBOUNCE_MS = 1500;         // Prevent multi-counting a single cough

unsigned long lastCoughTime = 0;

void setup() {
  Serial.begin(115200);   // USB Debugging Output
  
  // Initialize UART communication with ESP32 at 9600 baud
  // On Raspberry Pi Pico, Serial1 defaults to GP0 (TX) and GP1 (RX)
  Serial1.begin(9600);    

  Serial.println("Initializing Pico Acoustic AI...");

  // Setup I2S Microphone
  I2S.setBCLK(I2S_SCK);
  I2S.setDATA(I2S_SD);
  I2S.setBitsPerSample(BITS_PER_SAMPLE);

  if (!I2S.begin(I2S_PHILIPS_MODE, SAMPLE_RATE)) {
    Serial.println("Failed to initialize I2S! Check INMP441 wiring.");
    while (1);
  }
  
  Serial.println("I2S Microphone Ready. Listening for cough patterns...");
}

void loop() {
  int32_t sample = 0;
  long energy = 0;
  
  // Read a small 256-sample chunk of audio (about 16ms of audio)
  for (int i = 0; i < 256; i++) {
    I2S.read(); // Read the 32-bit sample
    sample = I2S.read(); // Get actual data
    
    // Shift down to avoid math overflow
    sample = sample >> 12; 
    
    // Calculate acoustic energy (Amplitude squared)
    energy += (sample * sample);
  }

  // AI Acoustic Heuristic Check (Sudden, loud burst)
  if (energy > ENERGY_THRESHOLD) {
    
    // Make sure we don't count the same cough twice in 1.5 seconds
    if (millis() - lastCoughTime > DEBOUNCE_MS) { 
      Serial.println("⚠️ COUGH DETECTED! Analyzing waveform...");
      
      // Determine severity based on acoustic energy
      int severity = 1;
      if (energy > ENERGY_THRESHOLD * 2) severity = 2;
      if (energy > ENERGY_THRESHOLD * 4) severity = 3;
      if (energy > ENERGY_THRESHOLD * 6) severity = 4;
      
      Serial.print("Severity Level Calculated: ");
      Serial.println(severity);

      // Transmit the event to the ESP32 Master Gateway via UART wires
      // Format: COUGH:SEVERITY (Example: "COUGH:3")
      Serial1.print("COUGH:");
      Serial1.println(severity);

      lastCoughTime = millis();
    }
  }
}
