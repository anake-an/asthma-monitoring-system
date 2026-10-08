# RespiroSync Hardware Integration

This directory contains the firmware source code for the physical IoT hardware used in the RespiroSync Asthma Monitoring System. The architecture utilizes a **Dual-Processor Design** to handle complex Acoustic AI alongside standard environmental telemetry.

## 1. ESP32 Master Gateway (`esp32_firmware/esp32_firmware.ino`)
The ESP32 is the central brain of the hardware system. 
*   **Connectivity:** Uses `WiFiManager` to broadcast a "RespiroSync-Setup" Hotspot if it can't find WiFi. Securely connects to Cloudflare WebSockets (WSS).
*   **Environment:** Polls the DHT22 (Climate), Sharp GP2Y1014AU (Dust/PM2.5), and MQ-135 (Gas) sensors every 5 seconds.
*   **Comms:** Listens to UART2 (Serial2) for acoustic triggers from the Raspberry Pi Pico.

## 2. Raspberry Pi Pico (Acoustic AI Coprocessor)
The Raspberry Pi Pico is dedicated entirely to high-speed audio sampling.
*   **Phase 1 (Heuristic AI):** `pico_cough_ai/pico_cough_ai.ino` uses mathematical amplitude-squaring to detect loud bursts of acoustic energy and calculates a severity score.
*   **Phase 2 (True Machine Learning):** Instructions below for training a Neural Network using Edge Impulse.

---

## Phase 2: Edge Impulse Neural Network (How-To)
To upgrade the Pico from Phase 1 math-based logic to Phase 2 True AI (Distinguishing coughs from sneezes/talking), follow these steps:

1. **Flash Data Collector:** Open `pico_cough_ai/data_collection/data_collection.ino` in the Arduino IDE and flash it to the Pico. This script streams raw 16KHz audio over the USB Serial port.
2. **Install Edge Impulse:** Create a free account at [edgeimpulse.com](https://edgeimpulse.com/). Install the Edge Impulse CLI on your PC (`npm install -g edge-impulse-cli`).
3. **Stream Data:** Open your PC terminal and run: `edge-impulse-data-forwarder --frequency 16000`. It will detect the Pico and link it to your cloud project.
4. **Train the Brain:** 
    *   Record 5 minutes of coughing and label it `cough`.
    *   Record 5 minutes of talking/room noise and label it `noise`.
    *   In the Edge Impulse Studio, add an **Audio (MFCC)** processing block and a **Classification (Keras)** learning block. Click "Start Training".
5. **Deploy:** Go to the "Deployment" tab, select **Arduino Library**, and download the `.zip`. Add this ZIP to your Arduino IDE and replace the Phase 1 script with the generated Edge Impulse inference script!

---

## Setup Instructions

1.  Open `WIRING_GUIDE.md` and wire all hardware exactly as specified.
2.  Open `esp32_firmware/esp32_firmware.ino` in the Arduino IDE.
3.  Connect your ESP32 via USB and click **Upload**.
4.  Once booted, use your phone to connect to the **RespiroSync-Setup** WiFi network to configure your WiFi password and enter your Dashboard Device Token!
5.  Open `pico_cough_ai/pico_cough_ai.ino` in the Arduino IDE.
6.  Connect your Raspberry Pi Pico via USB and click **Upload**.
7.  Mount the hardware in the bedroom and monitor the dashboard!
