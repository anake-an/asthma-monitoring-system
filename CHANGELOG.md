# Changelog

All notable changes to RespiroSync. Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Fixed
- **Amber "near the limit" bars were invisible** (and the icon lost its round background) since the bar colours moved to `lib/readingStatus.ts` in 6.0.0: Tailwind did not scan `lib/`, so the amber classes were never generated (green and red happened to be used elsewhere). `lib/` is scanned now, and CI checks that the built CSS has all three status colours.

### Changed
- **ESP32 firmware: built-in humidity limit 75 % (was 60 %),** the same as the server's default. It only applies until the device receives its limits from the server, i.e. after booting without internet, where 60 % alarmed all the time in indoor air of 65-70 %. Takes effect at the next flash; no need to re-flash just for this.

## [6.0.0] - 2026-10-10

Children, rooms and sharing; an AI that learns per room and per child; push notifications, data export and data retention.

### Upgrading from 5.0.0
- **Database:** migrations run by themselves on start. Existing data is linked: each account gets a child "My child" (rename it in Account Settings → Children & rooms) that owns its devices and doses, and every device gets a copy of the account's limits.
- **`backend/.env` on the server:** `FRONTEND_URL` must be the address the dashboard is really served at (e.g. `https://app.respirosync.online`), or links in invite, password-reset and alert emails do not open; add `VAPID_SUBJECT=mailto:<an address you read>`, without which iPhones reject push notifications. Recreate `backend`, `mqtt-worker` and `scheduler` afterwards.
- **AI:** models are now per room and per child; the old per-account files are not read. Run `php artisan ai:train` once (it then runs every 4 h); each room starts in Learning mode.
- **ESP32:** re-flash with this release's firmware if your device still runs the 5.0.0 build (gas in ppm, self-calibrating dust sensor, passive buzzer, 3 s updates). Nothing else needs flashing.

### Added
- **Smarter AI inputs:** the risk model also learns from **how fast** dust and humidity are rising (change since the previous 10 minutes) and from **night-time** (22:00-05:59), next to the window averages, coughs and the daily-dose input. The **gas limit is now learned per room** too (mean + 3 spreads of that room's gas, floor 700 ppm), once a room has 6 h of gas readings, and an unusual gas reading alone can make Stage 1 "unusual". Old risk models (5 inputs) are not used and are replaced on the next training run; older room baselines without gas keep the 1000 ppm gas default until retrained.
- **Data export (PDPA right of access):** the Activity Log has "Download <child>'s data" with four spreadsheet files (CSV): sensor readings (with how many readings each average replaced), coughs (alert rule, review, inhaler used), inhaler doses (type, who logged it, by hand or from a cough) and alert limit changes (by AI, user or rule, with the reason). Only that child's rooms and doses, for anyone who can see the child; streamed, so a large file never sits in memory; cells that look like spreadsheet formulas are kept as text; each export is in the audit log. `GET /api/patients/{id}/export/{readings|coughs|doses|limits}`.
- **Deleting really deletes the AI's copies too:** removing a room, a child or an account now also deletes the AI model files trained on their data (`DELETE /models` on the engine); the privacy page had promised this. The privacy page now also says where to download data and how long readings are kept.
- **Push notifications switched on:** Account Settings has "Notifications on this device" (turn on / off per browser or phone). It asks the browser's permission, subscribes it and registers it with the backend; before, the backend could send pushes but no browser was ever subscribed, so alerts came by email only. Explains when it cannot work (blocked in the browser, or iPhone/iPad not opened from the home screen, iOS 16.4+). A cough-alert push now names the room and child, a newer alert for the same room replaces the older one, and tapping it opens the dashboard on that room. Signing out unsubscribes that browser on the server too (`DELETE /api/push-subscribe`).
- **Telemetry retention (nightly, 03:30):** every reading is kept for 7 days, then replaced by one average per device per 10 minutes (the AI's own training window, so it learns the same way), and deleted after a year. About 29,000 readings per device per day become 144 after the first week. Only sensor readings; coughs, doses, limit changes and the audit log are never touched. `php artisan telemetry:prune`, safe to run again; a failed sensor value stays out of the average instead of counting as 0. New `(device_id, recorded_at)` index, which also speeds up the dashboard's 2-second poll.
- **Two-level AI (multi-patient phase 4):** a **room model per device** learns what is normal for that room (two bedrooms are no longer averaged into a room that does not exist), and a **risk model per child** learns from that child's own rooms, rescue doses and the coughs heard there; shared rooms never train a child's model. Predictions, the AI panel, the "AI" badges and `ai:optimize` work per room (the room picker chooses it); a child's daily-dose input comes from that child's doses. New `php artisan ai:train` (scheduled every 4 h, also by hand after a reset) trains every room, then every child. Models are `device_<id>_*` and `patient_<id>_*`; the old per-account `user_<id>_*` files are no longer read, so each room starts in Learning mode until its first training run.
- **Sharing in the dashboard (multi-patient phase 3):** each child in Account Settings > Children & rooms has **Share**: members with their roles (owners change them or remove people), pending invitations (cancel), an invite form (email + owner / caregiver / viewer), your own "send me cough alerts" switch, Leave, and the child's **history** (audit log). Children shared with you are listed under "Shared with you"; their rooms appear in the room picker. The invitation link opens **/invite**, which asks you to sign in or register with the invited email and brings you back to accept. Viewers see a read-only dashboard (no Log Dose, no cough review, Smart Alerts read-only with a note); deleting an account that owns a shared child asks a second time.
- **Sharing a child (multi-patient phase 3, backend):** an owner invites an email to one child as **owner**, **caregiver** (sees everything, logs doses, marks false alarms) or **viewer** (sees only). The emailed link works once, expires after 7 days, and only a hash of it is stored; accepting also needs an account signed in with the invited email (emails are not verified at sign-up). Owners change roles and remove members, anyone can leave, a child always keeps an owner; removal takes effect on the next request. **Cough alerts go to every member** with alerts on (owners and caregivers by default, viewers opt in); each member switches their own. Deleting an account that owns a shared child asks for confirmation; with another owner the child, its rooms, limits and limit history stay with them. **Audit log** (`audit_logs`) of sharing, children, rooms, settings, doses and cough marks; owners read it per child. A member without the right now gets 403 (a stranger still 404). Invites are rate-limited (10 per hour).
- **Room picker and Children & rooms (multi-patient phase 2, dashboard):** a picker in the header chooses the room (device) on screen, with its child and an online / offline dot, remembered per browser; live values, Sleep Mode, cough history and Smart Alerts all follow it, and Smart Alerts names the room it changes. The medication panel logs doses for that room's child. Account Settings has **Children** (add, rename, delete with their dose history) and **Rooms** (rename, move to a child or a shared room, last seen, remove, pair a new device for a chosen child). The Activity Log is per child, with a child picker (hidden when printing) and the room on each limit change when the child has several rooms.
- **Patients and rooms (multi-patient phase 2, backend):** a **patient** (the child: display name and optional birth year only) is now separate from the login. Each account starts with "My child"; devices belong to a child or to a **shared room** (coughs shown, never attributed to a child); inhaler doses belong to a child. **Alert limits are per device** (room) with their own caps, locks and AI budget; a child's new room starts from that child's limits. `ai:optimize` asks the AI once per account and applies it per room; the missed-dose rule follows the room's child. Devices record **last seen** on every message and show online / offline (20 s). New API: `/api/patients` (list, add, rename, delete with dose history), `PATCH /api/devices/{id}` (rename, move to a child or shared room); `device_id` on telemetry, cough events and config, `patient_id` on doses and the report. One central access check (`PatientPolicy`, `DevicePolicy`, roles owner / caregiver / viewer ready for sharing); anything not yours is a 404. Deleting an account now deletes its children and their doses. The migration links existing data: one patient per account, every device a copy of the account's limits.
- **Missed daily dose rule (C7):** if you use a daily (brown) inhaler, meaning a dose was logged in the last 7 days, and none was logged in the last 26 h, every unlocked limit is 15 % lower until one is logged; then they go straight back. It is a documented rule, not AI: logged as "Rule" in the Activity Log ("Daily inhaler dose missed: limits 15 % lower until one is logged"), marked "dose" on the dashboard, never compounding. Accounts that never log daily doses are not affected. A limit you change while it is on is lowered too.
- **Turning AI optimization off puts every limit back to your own value** (and ends the rule). Before, limits the AI had lowered stayed lowered after switching it off.
- **Limits learned from your room (C6):** the AI's suggestion for a normal room is now the room's own learned limit, mean + 3 standard deviations of its usual readings (within the safety range), instead of fixed national numbers. "Unusual" and "very unusual" tighten from there to mean + 2.5 and mean + 2 std (the old fixed sets were 25/32/65 and 20/30/60), and the Stage 2 risk model tightens from the learned limits too. Your value stays the cap, so a room that is usually clean gets an earlier dust alarm (e.g. PM2.5 15 in a room that is usually 3), while a humid room never gets a humidity limit above yours. Gas keeps 1000 ppm: the MQ-135 estimate drifts too much to learn. Log reason: "Learned from your room's usual readings (Stage 1)".
- **Log of every alert limit change** in the Activity Log ("Alert Limit Changes"): when, which limit, old → new value, by the AI or by you, and why (e.g. "Room unusual (Stage 1)", "Flare-up risk 62% (Stage 2 model)", "Changed in Smart Alerts"). Stored in a new `limit_changes` table; `/api/report` returns the last 7 days.
- **At most one AI change per limit per day:** once the AI has moved a limit, it holds it until the next 24-hour window (a change in Smart Alerts gives it a fresh one). A room flipping between "normal" and "unusual" no longer moves limits up and down all day, which flooded the log and re-sent limits to the device. The Activity Log shows one line per update (an AI run or a Smart Alerts save) instead of one line per limit, so the printed report stays short: at most about 4 AI lines a day, usually 1.
- **Limits return to your values while the AI is not ready:** until the AI has a model and 24 h of readings, every limit is your own value (cap). This undoes limits the AI lowered before the 24 h rule, or before an AI reset, and each restore is logged. An AI engine error still leaves limits as they are.
- **AI changes are gradual:** the AI changes no limit until the room baseline covers 24 h of readings, and then moves each limit by at most 10 % per 24 h, so a Stage 1 flip between "Normal" and "Unusual" no longer swings limits every 5 minutes. A change in Smart Alerts starts a fresh daily budget; lowering your cap or locking a limit always applies at once.
- **Your limit is a cap, with an optional lock (all four limits):** the value set in Smart Alerts is the maximum; with AI optimization on, the AI may lower the effective limit when the room is unusual, never raise it above your value, and never changes a locked limit. Sliders stay usable with AI on. The dashboard marks limits the AI lowered ("AI"), locked limits ("locked") and limits inside the room's usual range ("!": expect frequent alerts). Existing limits become caps on upgrade. `ai:optimize` re-publishes to devices only when a limit changes.

### Changed
- **Dashboard header on phones:** the room picker has its own full-width row and the four buttons (Sleep Mode, Activity Log, theme, log out) share one row below it at equal size; before, the picker squeezed next to one button and the rest wrapped onto a ragged second line. Unchanged from tablet width up.
- **Bars show each reading against its own limit:** full at the limit, red above it (they used fixed scales, so 1006 ppm over a 1000 ppm limit showed a half-full bar). Each bar starts where there is nothing to worry about: dust 0, gas 400 ppm (fresh air), temperature 20 °C, humidity 40 %.
- **Card headlines and bar colours follow one rule** (`lib/readingStatus.ts`), the same on both cards and in standby mode: green below the limit, amber when near it (dust and gas within 20 %, temperature within 2 °C, humidity within 5 %), red above it. The climate card could say "Comfortable" over two amber bars before. Air quality now reads like room climate: **Clean Air** (dust ≤ 15) / **Fair Air** (≤ 35) / **Dusty** (> 35), **Slightly Dusty** / **Slightly Stuffy** (gas) near a limit, **Action Needed** above one. All four bars share the same colours (gas was purple, temperature and humidity blue).
- Default humidity limit for new accounts 75 % (was 60 %, which alarmed constantly in Malaysian indoor air).
- **ESP32 firmware drives a passive buzzer** with a 2.7 kHz `tone()` (alarm, cough chirp, cloud `buzzer_on`); idles low so no DC flows through a magnetic coil.
- **Dust sensor self-calibrates:** the clean-air baseline is learned as the lowest reading since boot, readings average 25 LED pulses, and the rise is converted with the datasheet's typical sensitivity. The fixed formula reported 0.0 on sensors with a low clean-air output.
- **Gas is an estimated CO₂-equivalent ppm** instead of the raw ADC value: the firmware applies the MQ-135 datasheet curve, calibrated against the cleanest air seen since power-on (420 ppm). Default gas limit 1000 ppm everywhere (firmware, backend, AI engine, dashboard); a migration resets existing gas limits, which were on the raw scale. Shown as "ppm"; hover text on the label and "ppm (est.)" in Smart Alerts explain that it is an estimate. **Re-flash the ESP32.**
- **Smoother, faster updates:** the ESP32 sends every 3 s (was 5 s); dust and gas are averaged over the last 4 readings, and the local alarm uses the same smoothed values, so a single noisy reading no longer beeps without the dashboard showing it. The dashboard refreshes live values every 2 s, requests only the newest reading (`/api/telemetry?limit=1`), runs its requests in parallel and never stacks them.
- **Offline detection by the server's clock** (`X-Server-Time`), after 20 s without data (was 90 s): a wrong clock on the viewer's computer made the device flicker between online and offline.
- The backend serves 4 requests in parallel (`PHP_CLI_SERVER_WORKERS`) instead of one at a time.
- `WIRING_GUIDE.md` rewritten for the reference kit: MB-102 split power, BSS138 level shifter on the LCD, GP2Y1010AU0F (150 Ω from 220 ∥ 470 Ω), DHT22 module, passive buzzer through 220 Ω, pin summary, bring-up order, power-on order.

### Fixed
- The dashboard footer and the Activity Log showed version 5.0.0 whatever was deployed (a hard-coded fallback); they read `frontend/lib/version.ts` now, and CI checks it equals `VERSION`. `backend/.env.example` had the wrong dashboard address and no `VAPID_SUBJECT`.
- **AI engine could never reach the database** when the DB password contains `@` (and other URL characters): the connection string was assembled by text, so MySQL saw a host like `…@db`. Prediction hid it (it checks for a model file first); training failed with a 500, so the AI could not leave Learning mode. The URL is now built from parts (`URL.create`), with a CI regression check.
- **AI reported plain accuracy and trained a Random Forest on any amount of data:** about 95% of windows are "safe", so even a useless model scored about 95%. Training now stays on Stage 1 below 12 episode windows (about 2 episodes), uses logistic regression up to 19 and a Random Forest from 20, and reports recall and precision on the most recent 25% of windows (or no score when fewer than 2 later episode windows exist). The AI panel shows the model type and those scores. CI trains on synthetic episodes to check all three cases.
- **AI treated daily doses as attacks:** every inhaler dose, including controller (daily, preventive) doses, was an "episode" label, so a child who takes the daily inhaler looked like one with daily attacks. Only rescue doses (and cough clusters) are episodes now; "controller dose in the last 24 h" is a model input instead. A model trained on the old inputs is not used and is replaced on the next training run. CI checks the labels with synthetic data.
- **AI limits alarmed in a normal room:** Stage 1 picked one of three fixed limit sets that ignored the room's own normal, e.g. humidity 65 % in a room that is normally 69.6 %. The AI may still tighten limits, but never below the room's learned normal range (mean + 2 standard deviations) and always within a safety range (PM2.5 15-55 µg/m³, temperature 26-38 °C, humidity 55-90 %, gas 700-3000 ppm). The room baseline is now saved at both training stages. CI checks the rule with a real baseline.
- **Devices kept stale limits after a deploy:** the MQTT worker re-publishes every device's current limits as retained config whenever it starts, so database changes (like the gas limit moving to ppm) reach devices that still held the old retained message.

### Fixed (honest wording)
- **Activity Log states facts, not medical judgements:** "Medication Analysis" (which called up to 3 emergency doses a week "an acceptable range for maintenance", or more "potential asthma instability") is now "Medication Log": how often each inhaler was logged, that this is not a medical assessment, and to show it to the doctor. "High Severity Flags … critical events" is now "Cough Alerts … met the alert rule".
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

[Unreleased]: https://github.com/anake-an/asthma-monitoring-system/compare/v6.0.0...HEAD
[6.0.0]: https://github.com/anake-an/asthma-monitoring-system/compare/v5.0.0...v6.0.0
[5.0.0]: https://github.com/anake-an/asthma-monitoring-system/compare/v4.3.0...v5.0.0
[4.3.0]: https://github.com/anake-an/asthma-monitoring-system/releases/tag/v4.3.0
