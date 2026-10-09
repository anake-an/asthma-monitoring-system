# Security Policy

## Supported versions

| Version | Supported |
|---|---|
| 5.0.x | ✅ |
| < 5.0 | ❌ Do not deploy 4.3.0: its MQTT broker accepts anonymous connections and one account can read another's data. |

## Reporting a vulnerability

Please **do not open a public issue** for security problems.

Report privately through GitHub: on this repository, open the **Security** tab and choose **Report a vulnerability**. Include what you found, how to reproduce it, and what an attacker could do with it.

This is a student project maintained by a small team, so there is no guaranteed response time, but every report is read and fixed issues are credited in [`CHANGELOG.md`](CHANGELOG.md) unless you ask otherwise.

## In scope

- The Laravel API, MQTT worker and scheduler (`backend/`)
- The Next.js dashboard (`frontend/`)
- The AI engine (`ai_engine/`)
- The broker configuration and ACL (`mosquitto/config/`)
- ESP32 and Pico firmware (`hardware/`)

## Known limitations (already tracked)

- **Shared device password.** Every ESP32 uses the same broker account. Someone who extracts it from one device can connect; the ACL still limits each connection to the topics of the token it uses as client id, so they cannot read other households' data, but they could publish fake readings for a token they know or guess. Planned fix: a per-device secret issued at pairing and checked against the database.
- **Open setup hotspot.** A device in setup mode opens the `RespiroSync-Setup` Wi-Fi without a password. Pair devices where nobody else can join it.
- **No physical factory reset** on the device yet; a device can only be reset from the dashboard.

## Secrets

No credentials are stored in this repository. `.env`, `backend/.env`, `mosquitto/config/passwd` and `hardware/esp32_firmware/secrets.h` are gitignored and created on each installation from the `*.example` files. If you ever find a real secret in the history, report it as a vulnerability.
