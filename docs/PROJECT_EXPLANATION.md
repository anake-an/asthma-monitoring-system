# 🫁 RespiroSync: System Architecture & Code Explanation

How the hardware, AI, backend and frontend fit together, what each part really does, and where the code lives.

---

## 1. Hardware (dual-processor)

### 🎤 Raspberry Pi Pico: sound-level cough detector
*   **Hardware:** Raspberry Pi Pico + INMP441 I2S microphone.
*   **Why a second chip:** continuous 16 kHz audio sampling is kept off the ESP32, which handles Wi-Fi, TLS and sensors.
*   **How it works:** every 16 ms it computes the RMS sound level (DC offset removed). A burst above the threshold, at most once per 1.5 s, is reported as a cough candidate with a **detection strength** from 0 (just above threshold) to 1 (8× threshold, +18 dB).
*   **Limits (be honest in the report):** this is a loudness heuristic, not a classifier. Claps, door slams and shouting also trigger it. `pico_cough_ai/data_collection/` and `hardware/README.md` describe the Phase 2 path to a trained model (Edge Impulse).
*   **Code:** `hardware/pico_cough_ai/pico_cough_ai.ino`
*   **Output:** `COUGH:<level 1-4>,<strength 0.00-1.00>` over UART to the ESP32.

### 📡 ESP32: gateway and environment monitor
*   **Hardware:** ESP32 + DHT22 + MQ-135 + Sharp GP2Y1010AU0F (or the pin-compatible GP2Y1014AU0F), passive buzzer, red/green LEDs, 16x2 I2C LCD behind a BSS138 level shifter, MB-102 5 V supply for the sensors.
*   **How it works:**
    *   Reads the sensors every 3 s and publishes them to `respirosync/devices/<token>/telemetry`. Dust and gas are averaged over the last 4 readings (~12 s); the same smoothed values drive the local alarm, so the device and dashboard agree. A failed DHT22 read is sent as `null`, never as 0. Gas is an estimated CO₂-equivalent ppm (MQ-135 datasheet curve, calibrated against the cleanest air seen since power-on taken as 420 ppm), not a measured CO₂ value.
    *   Forwards each Pico detection to `respirosync/devices/<token>/events`.
    *   Receives its owner's thresholds on `respirosync/devices/<token>/config` (a retained message, so they arrive again after every reconnect) and sounds the buzzer/red LED when a reading crosses a threshold. This works offline too, using the last thresholds received. The dashboard can mute the buzzer.
    *   Obeys `factory_reset`, `buzzer_on` and `buzzer_off` on `respirosync/devices/<token>/commands`.
*   **Code:** `hardware/esp32_firmware/esp32_firmware.ino` (credentials in a gitignored `secrets.h`).

---

## 2. AI

### A. On-device detection
The Pico heuristic above. It produces candidate events and a strength value; it does not diagnose anything.

### B. Cloud risk model (`ai_engine/main.py`, FastAPI + scikit-learn)
*   **Per account:** every call carries `user_id`; the engine reads only that user's devices and inhaler logs and stores `user_<id>_model.pkl` / `user_<id>_baseline.pkl` in the `ai_models` volume.
*   **Data:** 10-minute windows of average PM2.5, temperature and humidity plus cough count. Gaps up to 30 minutes are interpolated; longer gaps (device offline) are dropped rather than invented. Cough events the caregiver marked as **false alarm** are excluded.
*   **Label:** "an inhaler dose or 2+ coughs within the next 60 minutes".
*   **Stage 1 (no events yet):** z-score of the current readings against the room's own baseline → low / moderate / high risk.
*   **Stage 2 (events exist):** Random Forest (balanced class weights). Accuracy is reported on the most recent 25% of windows, which the model did not train on; with too little data, no accuracy is claimed.
*   **Threshold suggestions:** `/predict` returns suggested thresholds. The scheduler runs `php artisan ai:optimize` every 5 minutes, which applies them for every account with "AI optimization" enabled (overwriting that account's manual thresholds) and pushes them to its devices.

---

## 3. Backend & cloud infrastructure

### 📨 Mosquitto (MQTT broker): `mosquitto/config/`
*   `allow_anonymous false`, with two accounts: `respirosync_backend` (full access to `respirosync/#`) and `respirosync_device` (shared by devices).
*   `acl` pins every device to `respirosync/devices/<its client id>/...`. A device cannot read another device's data, learn other tokens, or publish as another device. The password file is created on the server and never committed.

### ⚙️ Laravel 12
*   **MQTT worker:** `app/Console/Commands/MqttSubscribe.php` subscribes to `respirosync/devices/+/telemetry` and `/events` and hands each message to `app/Services/DeviceMessageHandler.php`. Device identity comes from the topic, never from the payload.
*   **Alert rule:** a cough raises an alert when there are **3 coughs in 10 minutes**, or **2 coughs in 10 minutes where this one has strength ≥ 0.8**. A single loud sound never alerts on its own. At most **one email per device per 10 minutes**. A mail failure is logged and never stops the worker.
*   **Alert email:** `resources/views/emails/cough_alert.blade.php` shows the cough count and the device-reported strength, or "Not reported by device". It never shows an invented confidence.
*   **Per-account data:** thresholds (`hardware_configs.user_id`), inhaler logs (`inhaler_logs.user_id`), cough events and telemetry (through the user's devices), reports and AI calls are all scoped to the signed-in user.
*   **REST API:** `routes/api.php`, protected by Sanctum tokens.
*   **Tests:** `backend/tests/` (`php artisan test` on a development machine or an isolated container; never inside the production backend container, see README).

---

## 4. Frontend (`frontend/`)
*   **Live Monitor:** latest readings; shows "Sensor Error" when the DHT22 failed.
*   **Command Center:** thresholds and buzzer mute. Saving pushes the new config to every device on the account.
*   **AI Risk panel:** model stage, risk level, and coughs in the last hour.
*   **Cough History:** each event's detection strength and whether it was an Alert or only Logged; caregivers can mark events as false alarms, which then feeds into training.

---

## 5. Deployment & security
*   **Docker Compose:** frontend, backend, MQTT worker, scheduler, AI engine, MySQL, Mosquitto, cloudflared. Secrets come from `.env` (see `.env.example`).
*   **Cloudflare Tunnel:** the NAS opens an outbound tunnel. The dashboard and the broker's WebSocket listener are reachable through Cloudflare without port forwarding, and every service binds only to `127.0.0.1` on the host.
*   **Broker hardening:** authentication + per-device ACL (above). TLS terminates at Cloudflare, and the ESP32 verifies Cloudflare's certificate.
