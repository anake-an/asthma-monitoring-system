# Changelog

All notable changes to RespiroSync. Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- **Your limit is a cap, with an optional lock (all four limits):** the value set in Smart Alerts is the maximum; with AI optimization on, the AI may lower the effective limit when the room is unusual, never raise it above your value, and never changes a locked limit. Sliders stay usable with AI on. The dashboard marks limits the AI lowered ("AI"), locked limits ("locked") and limits inside the room's usual range ("!": expect frequent alerts). Existing limits become caps on upgrade. `ai:optimize` re-publishes to devices only when a limit changes.

### Changed
- **Bars show each reading against its own limit:** full at the limit, amber from 80 %, red above it (they used fixed scales, so 1006 ppm over a 1000 ppm limit showed a half-full bar).
- Default humidity limit for new accounts 75 % (was 60 %, which alarmed constantly in Malaysian indoor air).
- **ESP32 firmware drives a passive buzzer** with a 2.7 kHz `tone()` (alarm, cough chirp, cloud `buzzer_on`); idles low so no DC flows through a magnetic coil.
- **Dust sensor self-calibrates:** the clean-air baseline is learned as the lowest reading since boot, readings average 25 LED pulses, and the rise is converted with the datasheet's typical sensitivity. The fixed formula reported 0.0 on sensors with a low clean-air output.
- **Gas is an estimated CO₂-equivalent ppm** instead of the raw ADC value: the firmware applies the MQ-135 datasheet curve, calibrated against the cleanest air seen since power-on (420 ppm). Default gas limit 1000 ppm everywhere (firmware, backend, AI engine, dashboard); a migration resets existing gas limits, which were on the raw scale. Shown as "ppm"; hover text on the label and "ppm (est.)" in Smart Alerts explain that it is an estimate. **Re-flash the ESP32.**
- **Smoother, faster updates:** the ESP32 sends every 3 s (was 5 s); dust and gas are averaged over the last 4 readings, and the local alarm uses the same smoothed values, so a single noisy reading no longer beeps without the dashboard showing it. The dashboard refreshes live values every 2 s, requests only the newest reading (`/api/telemetry?limit=1`), runs its requests in parallel and never stacks them.
- **Offline detection by the server's clock** (`X-Server-Time`), after 20 s without data (was 90 s): a wrong clock on the viewer's computer made the device flicker between online and offline.
- The backend serves 4 requests in parallel (`PHP_CLI_SERVER_WORKERS`) instead of one at a time.
- `WIRING_GUIDE.md` rewritten for the reference kit: MB-102 split power, BSS138 level shifter on the LCD, GP2Y1010AU0F (150 Ω from 220 ∥ 470 Ω), DHT22 module, passive buzzer through 220 Ω, pin summary, bring-up order, power-on order.

### Fixed
- **AI engine could never reach the database** when the DB password contains `@` (and other URL characters): the connection string was assembled by text, so MySQL saw a host like `…@db`. Prediction hid it (it checks for a model file first); training failed with a 500, so the AI could not leave Learning mode. The URL is now built from parts (`URL.create`), with a CI regression check.
- **AI reported plain accuracy and trained a Random Forest on any amount of data:** about 95% of windows are "safe", so even a useless model scored about 95%. Training now stays on Stage 1 below 12 episode windows (about 2 episodes), uses logistic regression up to 19 and a Random Forest from 20, and reports recall and precision on the most recent 25% of windows (or no score when fewer than 2 later episode windows exist). The AI panel shows the model type and those scores. CI trains on synthetic episodes to check all three cases.
- **AI treated daily doses as attacks:** every inhaler dose, including controller (daily, preventive) doses, was an "episode" label, so a child who takes the daily inhaler looked like one with daily attacks. Only rescue doses (and cough clusters) are episodes now; "controller dose in the last 24 h" is a model input instead. A model trained on the old inputs is not used and is replaced on the next training run. CI checks the labels with synthetic data.
- **AI limits alarmed in a normal room:** Stage 1 picked one of three fixed limit sets that ignored the room's own normal, e.g. humidity 65 % in a room that is normally 69.6 %. The AI may still tighten limits, but never below the room's learned normal range (mean + 2 standard deviations) and always within a safety range (PM2.5 15-55 µg/m³, temperature 26-38 °C, humidity 55-90 %, gas 700-3000 ppm). The room baseline is now saved at both training stages. CI checks the rule with a real baseline.
- **Devices kept stale limits after a deploy:** the MQTT worker re-publishes every device's current limits as retained config whenever it starts, so database changes (like the gas limit moving to ppm) reach devices that still held the old retained message.

### Fixed (honest wording)
- **AI panel, Stage 1:** shown as Normal / Unusual / Very unusual ("Room compared with its usual readings") instead of an "Attack Probability" percentage; Stage 1 returns one of three fixed levels, not a calculated probability. A hint says personal risk prediction starts after about 2 recorded flare-ups (rescue dose or 2+ coughs in an hour). Stage 2 is labelled "Risk of an asthma flare-up in the next hour (model estimate)".
- **Dust level** uses fixed published bands instead of a fraction of the user's alert limit (which called 21 µg/m³ "Hazardous"): Low ≤ 15 (WHO 2021 24-h guideline), Moderate ≤ 35, High > 35 µg/m³ (MAAQS 2020 24-h limit). Hover text on the label explains the bands and that the value is an indicative estimate.
- The air-quality card shows "Gas High" when gas exceeds its limit (it used to ignore gas while the warning banner counted it).
- **No unearned "AI" claims:** limit badges show "AI" only while the AI engine has a model (Stage 1 or 2) and AI optimisation is on, and nothing while it is still learning; banners say "Cough-like Sound Detected" and "Warning: Alert Limit Exceeded"; the rescue-dose notice states its rule; the doctor report no longer mentions an "acoustic AI model"; subtitles drop "Edge AI".
- `/api/ai/predict` answers `learning: true` while there is no model or no recent data, instead of a 400 error.

### Added
- Serial log of raw dust and MQ-135 readings and the learned dust baseline.
- CI compiles the ESP32 firmware (arduino-esp32 2.0.17 and latest) and both Pico sketches.

## [5.0.0] - 2026-10-09

A security and honesty release. **Not a drop-in upgrade from 4.3.0:** the broker now requires credentials, the MQTT topics changed, both firmwares must be re-flashed and the Pico microphone rewired. See "Upgrading from 4.3.0" below.

### Breaking
- **MQTT broker requires authentication.** Anonymous access is off on every listener. Two accounts: `respirosync_backend` (API, worker, scheduler) and `respirosync_device` (shared by devices). An ACL pins each device, connected with its pairing token as client id, to its own topics.
- **New topic layout** per device: `respirosync/devices/<token>/{telemetry,events,config,commands}`. The old `respirosync/telemetry` and `asthma/config` topics are gone.
- **ESP32 firmware:** broker URL and device password move to a gitignored `secrets.h` (copy `secrets.example.h`). `esp32_firmware.example.ino` is removed.
- **Pico firmware rewritten** for the arduino-pico `I2S` API (the 4.3.0 sketch did not compile). Microphone pins change to SCK GP14, WS GP15, SD GP13.
- **10 kΩ / 20 kΩ voltage dividers** are required on the MQ-135 and Sharp dust sensor outputs (5 V sensors into 3.3 V ESP32 inputs).
- **Laravel 11 → 12** and `laravel-notification-channels/webpush` 8 → 13 (see Security).
- New required `.env` values: `MQTT_USERNAME`, `MQTT_PASSWORD` (root `.env`), `FRONTEND_URL` (`backend/.env`).

### Security
- Fixed: one account could read or verify another account's cough events, inhaler logs and reports (IDOR). All data is now scoped to the signed-in account.
- Upgraded to Laravel 12.69: Laravel 11 no longer receives security fixes, and CVE-2026-48019 (CRLF injection through the `email` validation rule) is fixed only in 12.60+.
- The database seeder no longer creates the public login `admin@asthma.local` / `password123`. It refuses to run in production.
- Removed the `clear:data` command, which deleted every account's data without confirmation.
- Tests refuse to run against anything but in-memory SQLite. Running them inside the production container used to wipe the live database.
- Password-reset links now expire after 60 minutes.
- Bumped Next.js to 14.2.35 (CVE-2025-55184, CVE-2025-66478).
- Service ports bound to `127.0.0.1` only; no default fallbacks for secrets in `docker-compose.yaml`.
- Committed `backend/composer.lock` (audited: no advisories, no abandoned packages).

### Changed
- **Honest outputs.** The alert email no longer shows an invented "98.5%" confidence; the Pico reports a 0-1 detection *strength* (a loudness heuristic, not a probability). The AI panel shows "Learning mode" instead of a placeholder risk percentage. Removed the made-up "estimated next inhaler" metric.
- **Alert rule:** 3 coughs in 10 minutes, or 2 when one has strength ≥ 0.8. A single loud sound is logged but never alerts. At most one email per device per 10 minutes.
- **AI engine** trains one model per account (`?user_id=` required), stores models in the `ai_models` volume, excludes coughs marked as false alarms, and validates on the most recent 25% of data.
- AI threshold optimization (`ai:optimize`) runs every 5 minutes per account. While enabled, it overwrites manual thresholds.
- Thresholds (`hardware_configs`) are per account; inhaler logs record their account.
- The backend runs `php artisan migrate --force` on every start.
- ESP32: local threshold alarm that works offline, verified TLS, corrected dust formula, LCD and LED status feedback.
- Docs moved to `docs/` (`PROJECT_EXPLANATION.md`, `OPERATIONS.md`); README lists them.

### Added
- GitHub Actions CI: backend tests, frontend type check and build, AI engine check.
- Reset-password page in the dashboard.
- Laravel application skeleton (artisan, bootstrap, config, public, tests) committed to the repo.
- `.env.example` files, `SECURITY.md`, this changelog.

### Fixed
- Cough alerts compared database-generated timestamps with the app clock; with different timezones, alerts never fired. Timestamps are now written by the app.
- A failed DHT22 read is stored as empty, not as a fake value. Cough messages no longer create fake telemetry rows.
- PM2.5 shows one decimal place in sleep mode; stale UI flash during login/logout; TypeScript error in the AI panel.

### Removed
- `TestDataSeeder` (written for the pre-devices schema) and `frontend/apply-light-mode.js` (one-off script).

### Upgrading from 4.3.0
1. Back up the database and the project folder (`docs/OPERATIONS.md`, section 2).
2. Create the broker password file with both accounts and add `MQTT_USERNAME` / `MQTT_PASSWORD` to `.env` (README, "Broker credentials"). Add `FRONTEND_URL` to `backend/.env`.
3. Pull and restart. Migrations run automatically.
4. Rewire the Pico microphone and add the two voltage dividers (`hardware/WIRING_GUIDE.md`).
5. Fill in `secrets.h`, flash both boards, and pair each ESP32 with a token from the dashboard.

## [4.3.0] - 2026-10-08

Initial public release.

> **Superseded.** 4.3.0 runs an open MQTT broker and has the cross-account data access fixed in 5.0.0. Do not deploy it.

[5.0.0]: https://github.com/anake-an/asthma-monitoring-system/compare/v4.3.0...v5.0.0
[4.3.0]: https://github.com/anake-an/asthma-monitoring-system/releases/tag/v4.3.0
