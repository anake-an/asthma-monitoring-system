# RespiroSync Room Monitor: firmware history

Product **RespiroSync Room Monitor**, model **RS-100**, hardware **rev A** (ESP32 gateway + Raspberry Pi Pico cough sensor).

Firmware versions read like **`3.1.0 Build 261011`**, in the style of other connected devices:
- **3.1.0** is set in `esp32_firmware.ino` (`FIRMWARE_VERSION`):
  - **major** (3.x.x) changes need something new from the owner (a USB re-flash, new setup);
  - **feature** (x.1.x) adds things the device does;
  - **fix** (x.x.1) only corrects.
- **Build 261011** is added by CI when it builds the firmware: the build date (YYMMDD, Malaysia time), plus `.2`, `.3`… for a second or third firmware build that day. A build made in the Arduino IDE says `dev`.
- The website has its own versions (6.x, `CHANGELOG.md`). The two are independent.

Raise `FIRMWARE_VERSION` and write `WHATS_NEW.txt` (what owners read in the update dialog) for every firmware you publish.

| Firmware | Build | What changed |
|---|---|---|
| **3.2.0** | set by CI | Night mode can be switched off per room in Smart Alerts |
| 3.1.0 | 261010.7 | Start-up shows the firmware version and build; clearer update screens ("Updating 45%", "Don't unplug"); reports its build stamp |
| 3.0.1 | 261010.6 | Start-up screens slower, so they can be read *(first update installed from the cloud; it called itself "6.2.1")* |
| **3.0.0** | 261010.5 | Cloud updates from the dashboard, with checksum and rollback; new memory layout (two app slots), **one USB re-flash needed** *(called itself "6.2.0")* |
| 2.3.0 | 261010.4 | Updates over Wi-Fi from the Arduino IDE (removed in 3.0.0) |
| 2.2.0 | 261010.3 | Centred LCD layout with titled pages; gas sensor warm-up (3 min); clock from the server when NTP is blocked |
| 2.1.0 | 261010.2 | LCD pages and icons, alert screens, cough animation, night mode, "Daily dose?" reminder |
| 2.0.2 | 261010 | Humidity limit 75 % by default (was 60 %) |
| 2.0.1 | 261009.2 | Small fixes alongside the dashboard update |
| **2.0.0** | 261009 | Secure broker login with a per-device topic, gas in ppm, self-calibrating dust sensor, passive buzzer, readings every 3 s. **Older firmware cannot connect** |
| 1.1.0 | 261008.2 | LCD and LED feedback |
| 1.0.0 | 261008 | First firmware: sensors, MQTT, setup hotspot |

The builds before 3.1.0 were numbered afterwards from the Git history of `hardware/esp32_firmware/`. The server shows a device that still reports "6.2.0" / "6.2.1" as 3.0.0 / 3.0.1.
