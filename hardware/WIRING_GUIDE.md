# RespiroSync Hardware Wiring Guide

This document outlines the **exact** wiring diagram for the Dual-Processor Architecture using both the ESP32 and the Raspberry Pi Pico.

## ⚠️ Important Power Rules
1. **Common Ground (GND):** All components, the ESP32, and the Raspberry Pi Pico MUST share a common Ground (GND) connection. If grounds are not connected, the sensors and communication will fail.
2. **5V vs 3.3V:** The ESP32 and Pico operate at **3.3V logic**. However, sensors like the MQ135 and Sharp Dust Sensor **REQUIRE 5V** to function properly. You must power these sensors from the `VIN` or `5V` pin of the ESP32.

> [!WARNING]
> **Hardware Liability Disclaimer:** 
> This wiring guide is a **reference implementation** only. Because ESP32 development boards and sensor modules vary wildly by manufacturer, you should *never* blindly follow these exact diagrams without verifying them against your own hardware. Always double-check your specific manufacturer datasheets for pinouts, voltages, and common grounds before powering on the system. The authors are not responsible for any fried boards, short circuits, or damaged components. Proceed at your own risk.

---

## 1. Raspberry Pi Pico (Audio AI Coprocessor)
The Pico handles the INMP441 I2S Microphone for cough detection and signals the ESP32 over Serial Wires.

### INMP441 (I2S Microphone)
*Operates at 3.3V. Do not use 5V.*
*   **VDD** ➔ Pico **3V3 (Out)**
*   **GND** ➔ Pico **GND**
*   **L/R** ➔ Pico **GND** (Sets channel to Left)
*   **WS (Word Select)** ➔ Pico **GP 13**
*   **SCK (Serial Clock)** ➔ Pico **GP 14**
*   **SD (Serial Data)** ➔ Pico **GP 15**

### Pico ➔ ESP32 Communication (UART)
*Direct connection is safe (both are 3.3V logic).*
*   **Pico GND** ➔ **ESP32 GND**
*   **Pico GP0 (UART0 TX)** ➔ ESP32 **GPIO 16 (UART2 RX)**
*   **Pico GP1 (UART0 RX)** ➔ ESP32 **GPIO 17 (UART2 TX)**

---

## 2. ESP32 (Main IoT Gateway - esp32_firmware)
The ESP32 handles environmental sensors, triggers alarms, and talks to the Cloudflare MQTT Broker via WebSockets.

### DHT22 (Temperature & Humidity)
*Operates at 3.3V.*
*   **VCC** ➔ ESP32 **3.3V**
*   **GND** ➔ ESP32 **GND**
*   **DATA** ➔ ESP32 **GPIO 4**
    *(Note: Requires a **10kΩ Pull-up Resistor** connecting the DATA pin to the 3.3V pin if you are using a bare white sensor. If using a PCB module, the resistor is usually built-in).*

### MQ-135 (Gas/Air Quality Sensor)
*Requires 5V to heat the internal coil.*
*   **VCC** ➔ ESP32 **5V (VIN)**
*   **GND** ➔ ESP32 **GND**
*   **A0 (Analog Out)** ➔ ESP32 **GPIO 34** 

### Sharp GP2Y1014AU (Dust Sensor / PM2.5)
*Requires 5V. **Crucial:** Needs a 150Ω resistor and 220µF capacitor as per datasheet to stabilize the LED flash.*
*   **V-LED (Pin 1)** ➔ Connect to ESP32 **5V (VIN)** through a **150Ω resistor**. ALSO connect a **220µF capacitor** between this pin and GND.
*   **LED-GND (Pin 2)** ➔ ESP32 **GND**
*   **LED-Pin (Pin 3)** ➔ ESP32 **GPIO 5** (Digital output to pulse the LED)
*   **S-GND (Pin 4)** ➔ ESP32 **GND**
*   **Vo (Pin 5)** ➔ ESP32 **GPIO 35** (Analog Input for dust reading)
*   **Vcc (Pin 6)** ➔ ESP32 **5V (VIN)**

### Active Buzzer
*Used for immediate hardware alarming.*
*   **VCC** ➔ ESP32 **3.3V** or **5V**
*   **GND** ➔ ESP32 **GND**
*   **I/O (Signal)** ➔ ESP32 **GPIO 18**
