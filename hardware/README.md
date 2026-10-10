# RespiroSync Hardware Integration

This directory contains the firmware source code for the physical IoT hardware used in the RespiroSync Asthma Monitoring System. The architecture utilizes a **Dual-Processor Design** to handle complex Acoustic AI alongside standard environmental telemetry.

## 1. ESP32 Master Gateway (`esp32_firmware/esp32_firmware.ino`)
The ESP32 is the central brain of the hardware system. 
*   **Connectivity:** Uses `WiFiManager` to broadcast a "RespiroSync-Setup" Hotspot if it can't find WiFi. Securely connects to Cloudflare WebSockets (WSS).
*   **Environment:** Polls the DHT22 (Climate), Sharp GP2Y1010AU0F (Dust/PM2.5), and MQ-135 (Gas, estimated ppm) sensors every 3 seconds.
*   **Comms:** Listens to UART2 (Serial2) for acoustic triggers from the Raspberry Pi Pico.
*   **Broker:** connects as the `respirosync_device` account with client id = its pairing token, and only uses `respirosync/devices/<token>/...` topics.
*   **Local alarm:** applies the thresholds it receives from the dashboard (retained `config` topic) and beeps/turns the red LED on when a reading crosses one, even when offline.
*   **LCD (16×2):** every line is centred, and only changed characters are rewritten, so it never flickers.
    *   **Start-up:**
        1. "RespiroSync ♥" slides in.
        2. "Connecting WiFi" (or "WiFi setup / Join RespiroSync" when the setup hotspot opens).
        3. "WiFi connected" with the network name.
        4. "Setting clock".
        5. "Connecting cloud".
        6. "Ready ♥ / Monitoring room".
    *   **Pages, every 5 s:** the title stays on the top line, the values sit side by side on the bottom line.
        1. **Air quality:** PM2.5 (µg/m³, shown as `ug`) and gas (ppm), e.g. `12.4ug  420ppm`. For the first **3 minutes** after power-on the gas sensor warms up: it is not read, the LCD shows a countdown (`Gas 2:15`), and gas is sent to the server as empty.
        2. **Room climate:** temperature and humidity, e.g. `28.5C  64%`, or "Check sensor" when the DHT22 fails.
        3. **Clock:** time with the online/offline icon, and the date (Malaysia time).
        4. **Daily dose? (only while due):** shown while the child's daily dose is overdue. The server sends `dose_due` in the config: a daily inhaler is used, but none was logged for 26 h.
    *   **Over a limit:** the backlight blinks 3 times and the screen stays on e.g. `!! DUST HIGH !!` / `38 > limit 35` until the reading is back under the limit. Several readings over their limits take turns every 2 s.
    *   **Other screens:**
        *   **Cough:** "Cough heard" with a moving pulse, for 2 s.
        *   **Alert from the app:** "!! ALERT !!".
        *   **Connection drops:** "Offline / Alarms still on".
    *   **Clock source:** the internet time servers (NTP). Where a network blocks those (UDP port 123), the device asks the server over MQTT (a `time_request` event, answered with a `set_time` command), every 30 s until it has the time.
    *   **Night mode (21:00-07:00):** the backlight is off unless there is an alarm or a cough. It also lights up for 30 s after the board's **BOOT** button is pressed. Outside night mode, the BOOT button shows the next page.

## 2. Raspberry Pi Pico (Acoustic AI Coprocessor)
The Raspberry Pi Pico is dedicated entirely to high-speed audio sampling.
*   **Phase 1 (current, heuristic):** `pico_cough_ai/pico_cough_ai.ino` measures the RMS sound level of each 16 ms block and reports loud bursts as `COUGH:<level>,<strength>`. Strength is 0 at the threshold and 1 at 8× the threshold. It is a loudness measure, **not** a cough classifier: claps and door slams also trigger it.
*   **Phase 2 (not implemented yet):** train a real cough/noise classifier with Edge Impulse (steps below).
*   **Core:** use the **arduino-pico** core by Earle Philhower ("Raspberry Pi Pico/RP2040" in Boards Manager). The sketches use its `I2S i2s(INPUT)` API.
*   **Calibration:** set `DEBUG_LEVELS 1`, open the Serial Plotter, and set `RMS_THRESHOLD` above your room's normal speech level.

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
2.  Copy `esp32_firmware/secrets.example.h` to `esp32_firmware/secrets.h` and fill in the broker URL and the device password.
    Delete `esp32_firmware.example.ino` if it is still in the folder: the IDE compiles every `.ino` in a sketch folder together.
3.  Open `esp32_firmware/esp32_firmware.ino` in the Arduino IDE. It needs these libraries: WiFiManager, ArduinoJson 6 or 7, DHT sensor library, LiquidCrystal_I2C.
4.  Under **Tools**, choose:
    *   **Board:** "ESP32 Dev Module".
    *   **Partition Scheme:** "**Minimal SPIFFS (1.9MB APP with OTA/190KB SPIFFS)**". It has the two app slots that cloud updates need; the default scheme is too small.
5.  Connect your ESP32 via USB and click **Upload**.
6.  Once it has started, use your phone to connect to the **RespiroSync-Setup** Wi-Fi network, then enter your Wi-Fi password and the token from the dashboard.
7.  Open `pico_cough_ai/pico_cough_ai.ino` in the Arduino IDE with the arduino-pico core selected.
8.  Connect your Raspberry Pi Pico via USB and click **Upload**. The first time, hold **BOOTSEL** while plugging it in (it appears as the RPI-RP2 drive).
9.  Mount the hardware in the bedroom and monitor the dashboard!

## Updating the ESP32 from the cloud (OTA)

Once a device runs firmware 6.2.0 or newer (flashed once over USB with the partition scheme above), new versions are installed over the internet. Nothing needs to be on the same Wi-Fi.

**Automatic (recommended):**
1.  Raise `FIRMWARE_VERSION` and push to `main`.
2.  GitHub Actions builds the firmware with the real broker settings and uploads it to the server (job "Publish ESP32 firmware to the server").
3.  Owners see the update in the dashboard.

The `.bin` contains the device password, so it is never stored in GitHub (no artifact, no release).

One-time setup:
*   **NAS:** in `backend/.env`, add `FIRMWARE_UPLOAD_TOKEN=` followed by a long random value (`openssl rand -hex 32`), then `docker compose up -d --force-recreate backend`.
*   **GitHub:** repository → Settings → Secrets and variables → Actions → New repository secret. Add these five:

| Secret | Value |
|---|---|
| `MQTT_URI` | the same as in your `secrets.h` |
| `MQTT_USERNAME` | the same as in your `secrets.h` |
| `MQTT_PASSWORD` | the same as in your `secrets.h` (no `"` or `\` in it) |
| `FIRMWARE_UPLOAD_URL` | `https://app.respirosync.online/api/firmware/upload` |
| `FIRMWARE_UPLOAD_TOKEN` | the same value as on the NAS |

If Cloudflare's "Bot Fight Mode" is on, it may block the upload; turn it off, or skip the challenge for `/api/firmware/upload`.

**By hand (without GitHub):**

**1. Build** (developer, on the PC):
1.  In `esp32_firmware.ino`, raise `FIRMWARE_VERSION` (e.g. `"6.2.1"`).
2.  In the Arduino IDE, keep the same board and partition scheme. Then **Sketch → Export Compiled Binary**.
3.  The file you need is `build/.../esp32_firmware.ino.bin`, not `.merged.bin` or `.bootloader.bin`.

**2. Publish** (developer, on the NAS):
1.  Copy the `.ino.bin` into `backend/storage/app/firmware/` in the project folder (e.g. with the NAS file manager).
2.  Run:
    ```bash
    sudo docker compose exec backend php artisan firmware:publish storage/app/firmware/esp32_firmware.ino.bin --notes="What changed"
    ```
    It checks the file is an ESP32 app image that fits, reads the version from it, and keeps it as `esp32-<version>.bin`.
3.  `--list` shows the published versions, and `--remove=6.2.1` withdraws one.

**3. Install** (owner, in the dashboard):
1.  **Account Settings** shows a blue dot. Under **Rooms**, each device shows its firmware and **Update to 6.2.1**.
2.  The device must be online, and no alarm may be active.
3.  The LCD shows **Updating 6.2.1** with a percentage, then "Update done", and restarts. Rooms then shows **Updated**.

**Safety:**
*   **The link:** the device downloads over HTTPS through a one-time link (valid 10 minutes, only for that device).
*   **The checksum:** after writing the image, the device compares its SHA-256 with the published one. On a mismatch it keeps the old firmware.
*   **Rollback:** the new firmware must reach the cloud within 2 minutes, or the ESP32 starts the previous firmware again. Rooms then shows "Update failed" with the reason. A failed download also leaves the old firmware running.
*   **Not done here:** the image is not cryptographically signed. Commercial devices also use secure boot / signed images. That burns keys into the chip permanently, so it is left out of this prototype.
