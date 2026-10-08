# 🫁 RespiroSync: Full System Architecture & Code Explanation

This document serves as a comprehensive breakdown of the RespiroSync v4.3.0 project. It explains how the hardware, AI, cloud backend, and frontend communicate to form a complete IoT medical telemetry system, alongside exactly where the code is located.

---

## 1. The Hardware Architecture (Dual-Processor)

To prevent the system from crashing under heavy load, the hardware is split into two separate microcontrollers:

### 🎤 Raspberry Pi Pico (The Audio & Edge AI Processor)
*   **Hardware:** Raspberry Pi Pico + INMP441 (I2S Digital Microphone).
*   **Purpose:** Processing audio requires intense CPU cycles. If we made the ESP32 do this while also handling WiFi and MQTT, it would crash. The Pico acts as a dedicated Digital Signal Processor (DSP).
*   **How it works:** It continuously listens to the room using the INMP441 mic. It runs a lightweight **Edge AI model** to distinguish a human cough from background noise. 
*   **Code Location:** `hardware/pico_cough_ai/pico_cough_ai.ino`
*   **Output:** When it detects a cough, it calculates a "confidence score" (e.g., 85% sure it's a cough) and sends a simple serial signal over UART to the ESP32.

### 📡 ESP32 (The IoT Gateway & Environment Tracker)
*   **Hardware:** ESP32 + DHT22 (Temperature/Humidity) + MQ-135 (Gas/VOCs) + Sharp GP2Y1014AU0F (PM2.5 Dust).
*   **Feedback Hardware:** Active Buzzer (Alarm), Red & Green LEDs (Status indicators), and a 16x2 I2C LCD (Live status display).
*   **Purpose:** Acts as the brain of the physical device. It handles all internet connectivity, reads environmental sensors, controls feedback hardware, and forwards data to the cloud.
*   **How it works:** 
    *   It connects to the local WiFi. Every few seconds, it reads the room's air quality. 
    *   If the sensors exceed safe thresholds (or if an MQTT message tells it to), the ESP32 triggers the **Buzzer** and **Red LED** to alert the patient physically. 
    *   The **LCD Display** continuously updates to show live PM2.5 levels, WiFi status, and AI alerts. The **Green LED** indicates the system is armed and normal.
    *   If it receives a "cough signal" from the Pico, it bundles the cough event along with the current environmental data.
*   **Code Location:** `hardware/esp32_firmware/esp32_firmware.example.ino`
*   **Output:** It publishes this bundled JSON data to the Cloud via the MQTT protocol.

---

## 2. The Artificial Intelligence (AI) Engines

RespiroSync uses a **Two-Tiered AI Architecture** (Edge + Cloud):

### A. Edge AI (Acoustic Classification)
*   **Where it lives:** On the Raspberry Pi Pico (`hardware/pico_cough_ai/pico_cough_ai.ino`).
*   **What it does:** Audio pattern recognition. It doesn't know anything about asthma; it is strictly trained to identify the specific soundwave frequencies of a cough, filtering out talking, clapping, or television noises.

### B. Cloud AI (Predictive Risk Engine)
*   **Where it lives:** In the Dockerized `ai_engine` container (built with Python & FastAPI).
*   **Code Location:** `ai_engine/main.py`
*   **Model Used:** **Random Forest Classifier** (Machine Learning).
*   **What it does:** This is the predictive brain. It takes all historical data from the MySQL database (how many coughs happened in the last hour, was the PM2.5 high, is the room too hot?) and predicts the probability of an asthma attack.
*   **Smart Optimization:** It doesn't just predict; it adapts. If the AI notices the patient coughs heavily whenever the PM2.5 hits 40 µg/m³, it will automatically communicate with the backend to lower the "Safe PM2.5 Threshold" for that specific patient.

---

## 3. The Backend & Cloud Infrastructure

The backend is responsible for receiving hardware data, routing it to the AI, and alerting the users.

### 📨 Eclipse Mosquitto (MQTT Broker)
*   **Configuration Location:** `mosquitto/config/mosquitto.conf`
*   **Why MQTT?:** Standard HTTP requests are too slow for IoT. MQTT is an ultra-lightweight, real-time messaging protocol.
*   **How it works:** The ESP32 "publishes" a message to a topic. The backend "subscribes" to that topic to receive it instantly.

### ⚙️ Laravel 11 (The Core Backend & Worker)
*   **The MQTT Worker:** A PHP daemon that constantly listens to the Mosquitto broker. 
    *   **Code Location:** `backend/app/Console/Commands/MqttSubscribe.php`
*   **Smart Spam Filtering:** When the worker receives a cough event, it checks the Pico's "confidence score". If the score is `< 80%`, and there haven't been at least 3 coughs in the last 10 minutes, it logs the data but **blocks** the emergency email. This prevents parents/doctors from getting spammed by false alarms.
*   **Brevo SMTP:** If a true emergency is detected (or the Python AI flags a 90% risk), Laravel compiles a beautiful HTML medical alert template (`backend/resources/views/emails/cough_alert.blade.php`) and fires it via Brevo to the caretaker's email.
*   **REST API:** Laravel also provides secure API endpoints (`backend/routes/api.php`), protected by Sanctum tokens, so the Next.js frontend can fetch charts and settings.

---

## 4. The Frontend Dashboard

### 🖥️ Next.js 14 & Tailwind CSS
*   **Code Location:** All files within the `frontend/` directory.
*   **Purpose:** The clinical UI for doctors and guardians.
*   **Key Features:**
    *   **Live Monitor (`frontend/components/LiveMonitor.tsx`):** Displays real-time PM2.5, Gas, Temp, and Humidity pulled from Laravel.
    *   **Command Center (`frontend/components/CommandCenter.tsx`):** Allows users to change hardware thresholds (e.g., set max temperature to 30°C). When saved, Laravel pushes an MQTT message *back* to the ESP32 to update its internal rules instantly, which might turn off the buzzer or update the LCD.
    *   **UX/UI:** Features modern, dark-mode aesthetics with smooth state transitions, built using Tailwind CSS. 

---

## 5. Deployment & Security (Zero-Trust)

### 🐳 Docker Compose
*   **Configuration Location:** `docker-compose.yaml` at the root of the project.
*   The entire cloud stack (Next.js, Laravel, Python AI, MySQL, Mosquitto) is bundled into this single file. Passwords are securely injected using `.env` variables so they are never hardcoded on GitHub.

### 🛡️ Cloudflare Tunnels (Zero-Trust)
*   The system is hosted on a local NAS (NOVAFORTRESS). 
*   Normally, to put this on the internet, you have to "Port Forward" your home router, which invites hackers.
*   Instead, RespiroSync uses a **Cloudflare Tunnel** (configured in the `docker-compose.yaml`). A daemon inside the NAS creates an outbound, encrypted tunnel to Cloudflare's servers. Users visit the domain name, and Cloudflare routes the traffic securely into the Next.js container, completely hiding the NAS's true IP address and keeping the database isolated from the public internet.
