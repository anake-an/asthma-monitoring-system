# Changelog

All notable changes to RespiroSync. Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- **Night mode switch:** Smart Alerts has **Night Mode** per room (on by default). Off keeps the device's screen lit at night. It needs firmware 3.2.0, which owners get as an update.

## [6.2.0] - 2026-10-10

Device firmware updates from the dashboard (built and published by GitHub Actions), a "device offline" alert, honest cough reviews, and a quieter Pico. Device firmware now has its own versions (3.1.0 Build 261010.7, see `hardware/FIRMWARE_HISTORY.md`).

### Upgrading from 6.1.0
- **Database:** migrations run by themselves on start (offline alerts, firmware releases and builds).
- **ESP32:** flash once over USB with **Tools → Partition Scheme → "Minimal SPIFFS (1.9MB APP with OTA/190KB SPIFFS)"**. After that, updates come from the dashboard.
- **Automatic firmware publishing** (optional):
    - set `FIRMWARE_UPLOAD_TOKEN` in `backend/.env`;
    - add the GitHub secrets `MQTT_URI`, `MQTT_USERNAME`, `MQTT_PASSWORD`, `FIRMWARE_UPLOAD_URL` and `FIRMWARE_UPLOAD_TOKEN`;
    - in Cloudflare, turn **Bot Fight Mode** off and add a custom rule that **skips** security for `/api/firmware/upload`, placed first (before any geo-blocking).

  See `hardware/README.md`, "Updating the ESP32 from the cloud".
- **Pico:** re-flash `pico_cough_ai.ino` over USB for the noise fix.

### Added
- **Cloud firmware updates (OTA) for the ESP32.** The developer publishes a build on the server (`php artisan firmware:publish <file>.ino.bin`). The server checks it is an ESP32 app image that fits, and reads the version from the file. Owners then see a blue dot on Account Settings, and **Update to x.y.z** for each room under Rooms. The device:
    - downloads the firmware from the server over HTTPS, through a one-time link (10 minutes, that device only), with progress on the LCD;
    - checks its SHA-256 and installs it into the spare slot;
    - restarts, and keeps the new firmware only once it reaches the cloud. Otherwise (within 2 minutes) it goes back to the previous one.

  Rooms shows each device's firmware version and Updating / Updated / Update failed (with the reason). A device answers with its version when it connects. **Needs** the "Minimal SPIFFS (1.9MB APP with OTA)" partition scheme and one USB flash of firmware 6.2.0. The firmware is not signed (no secure boot).

  **Automatic publishing:** when a push to `main` changes the firmware, GitHub Actions builds it with the real broker settings (GitHub secrets) and uploads it to the server (`POST /api/firmware/upload`, key `FIRMWARE_UPLOAD_TOKEN`). Owners see the update without anyone exporting or copying files. The `.bin` holds the device password, so it is never kept in GitHub. A version the server already has is ignored.
- **Firmware versions like a real device:** `3.1.0 Build 261011`, separate from the website's versions. The version is set in the firmware (major.feature.fix); CI adds the build stamp (build date, `.2` for a second build that day). The earlier builds are numbered from the Git history in `hardware/FIRMWARE_HISTORY.md` (1.0.0 → 3.0.1); the first cloud builds that called themselves "6.2.0" / "6.2.1" show as 3.0.0 / 3.0.1. The LCD shows `FW 3.1.0` / `Build 261011` at start-up. The server compares version, then build.
- **Friendlier firmware updates for owners:**
    - a dashboard banner "An update is available for Ali Bedroom" (dismissible);
    - in Rooms, one short status: ✓ Up to date, ● Update available, Updating... don't unplug, or Update didn't finish with "Your device is still working normally" and Try again;
    - an "Update available" dialog with "What's new" points, "Takes about 1 minute. Keep the device plugged in." and **Later / Update now**;
    - a toast when it is done.

  "What's new" comes from `hardware/esp32_firmware/WHATS_NEW.txt`, written for owners, instead of the PR title. The device and model (RespiroSync Room Monitor, RS-100 rev A) are in the API for the dashboard.
- **"Device offline" alert:** when a room's device has sent nothing for **30 minutes** (power cut, Wi-Fi down, unplugged), everyone who gets that child's alerts gets an email and a push. It is sent once per outage, and a "back online" push follows when the device reports again. Devices that never connected are ignored. `php artisan devices:offline-alerts` runs every 5 minutes. The "Send me alerts" switch now covers coughs, readings over a limit and offline devices.

### Changed
- **ESP32 start-up screens are slower, so they can be read** (firmware 3.0.1): the name slides in at a gentler speed, the firmware version shows for 1.5 s, "WiFi connected" for 2 s and "Ready" for 2.5 s.

### Fixed
- **Reviewing coughs:** marking a cough as a false alarm now really takes it out:
    - out of the Activity Log's counts and chart (the report says how many were left out);
    - out of the red "cough-like sound" banner;
    - out of the 3-coughs-in-10-minutes alert rule.

  The review has three choices: **Inhaler**, **Real cough** (new: no dose logged) and **False alarm**. A **Change** link undoes a review, and removes the dose it logged. The emergency dose from the Inhaler choice is logged at the cough's time, not when it was reviewed.
- **Pico: no more coughs from nothing.** Without a microphone (and briefly at power-on), the microphone's data pin picked up electrical noise that was reported as a weak cough. The firmware now pulls that line down (the INMP441 datasheet asks for this too), ignores the first 3 seconds after power-on, and needs a burst to last at least 2 blocks (32 ms; a cough lasts 200-500 ms).

## [6.1.0] - 2026-10-10

Alerts when a reading stays over its limit, account photos and child badges, a PDF report that works in the iPhone app, and a redesigned device screen.

### Upgrading from 6.0.0
- **Database:** two migrations: the account photo and the child badge columns, and the removal of the unused automatic children. Run `php artisan migrate --force` if your backend does not run migrations on start.
- **ESP32:** re-flash with this release's firmware. You get the new LCD, the gas warm-up, the clock that also works where NTP is blocked, and the daily-dose reminder. Older firmware keeps working, and ignores the new `dose_due` config field.
- **Scheduler:** a new 5-minute task, `devices:dose-reminders`. The scheduler container picks it up by itself.

### Added
- **Alerts for readings over a limit:** when dust, gas, temperature or humidity stays above its room's limit for **5 minutes** (every reading in that time over it; a dip or a failed sensor starts the wait again), everyone with alerts on gets an **email and a push** naming the reading, the room and child, the value and the limit; tapping it opens that room. At most one per room and reading per hour while it stays high. Same smoothed values and limits as the device's buzzer, same recipients as cough alerts (the "Send me alerts" switch per child now covers both). Before, only the buzzer and the dashboard showed it.
- **Account photo:** tap the circle in Account Settings to add, change or remove one. The browser crops it to a square and shrinks it to 256 × 256 before sending (which also drops hidden data such as the photo's GPS location); the server accepts only real JPEG, PNG or WebP images up to 512 px (no SVG). Stored with the account, so it is in database backups and goes when the account is deleted. People you share a child with see it in the Share list.
- **A badge per child** instead of a photo: one of 8 colours and an emoji (16 to choose from) or the first letter of the name, set by the child's owner (tap the badge in Children & rooms). Shown in Children & rooms, the room picker and the Activity Log; children without a choice get different colours automatically.

### Fixed
- **Activity Log in the iPhone/iPad Home Screen app:** "Print PDF" did nothing there (iOS ignores printing from Home Screen apps). The button is now **Share PDF**: the report is made into a PDF on the phone, in the same layout as the printout, and opens the share sheet (Print, Save to Files, Mail, WhatsApp...). The data downloads open the share sheet too. Browsers elsewhere still print as before.
- **Every dropdown is themed now**, not only the room picker: the Activity Log's child picker, a room's child and the "pair for" choice in Children & rooms, and the role choices in Share used the browser's own list (white on Windows). One shared component (`ThemedSelect`): light and dark, a tick on the current choice, role options say what each role may do, keyboard and outside-tap support, and the list floats above the scrolling Account Settings window instead of being cut off.
- **Activity Log summary cards all have an icon**, on screen and in the printed PDF: sound waves (cough events), a ringing bell (cough alerts), an inhaler (doses). Only the first card had one, faint and hidden in print.
- **Invited people no longer get an empty child of their own.** Registration created "My child" for every new account, so a viewer or caregiver saw a child with no rooms and a "Pair New ESP32 Device" button (pairing for that empty child, never for the shared one, which the server always refused). Now a first child is created only when someone pairs their own device or adds one; the pairing button shows only for children you own; pages without a chosen child open the first child you can see (a viewer's Activity Log opens on the shared child). A migration removes the leftover empty "My child" entries (no rooms, no doses, no other members, on accounts that also see another child).
- **Wording no longer presents RespiroSync as a medical product** (in Malaysia that is decided by intended use, Medical Device Act 2012): "medical network" and "Medical Dashboard" are gone, the rescue-dose notice states a fact and suggests the doctor instead of interpreting "asthma control", and "medical data" is "health data" (the PDPA's term). The cough-alert email names the room and child, like the push.
- **Room picker is a themed list** instead of the browser's own dropdown (white with a grey highlight on Windows, regardless of dark mode): rooms grouped by child, each with its status dot and "Online", "Offline" or "Waiting for setup" (was "(pending)"), a tick on the room on screen; arrow keys, Enter and Escape work, a tap outside closes it. The header now sits above the cards so the list is never covered.
- **Amber "near the limit" bars were invisible** (and the icon lost its round background) since the bar colours moved to `lib/readingStatus.ts` in 6.0.0: Tailwind did not scan `lib/`, so the amber classes were never generated (green and red happened to be used elsewhere). `lib/` is scanned now, and CI checks that the built CSS has all three status colours.

### Changed
- **ESP32 LCD redesigned** (re-flash the ESP32 to get it):
    - **Layout:** every line is centred, with icons (dust, gas, thermometer, drop, bell, heart, online/offline).
    - **Start-up steps:** boot animation, then Connecting WiFi (or the setup hotspot), WiFi connected with the network name, Setting clock, Connecting cloud, then Ready.
    - **Pages every 5 s,** title on top and values side by side below: **Air quality** (PM2.5 and gas), **Room climate** (temperature and humidity), and the **clock** with the connection icon.
    - **Over a limit:** a full alert screen (`!! DUST HIGH !!` / `38 > limit 35`) that blinks the backlight 3 times. Several readings over their limits take turns.
    - **Other screens:** cough animation, "Offline / Alarms still on" when the connection drops, "Check sensor" when the DHT22 fails.
    - **Night mode:** the backlight is off 21:00-07:00 unless there is an alert or the BOOT button is pressed.
    - **No flicker:** only changed characters are redrawn; it used to clear the screen every 3 s.
- **ESP32 gas warm-up:** the MQ-135 is not read for its first 3 minutes after power-on. The LCD counts down and gas is sent as empty, so a cold sensor neither sets off the gas alarm nor teaches the device a wrong clean-air reference (which made every later reading too high).
- **ESP32 clock works where NTP is blocked:** the time comes from the internet time servers; where a network blocks those (UDP port 123), the device asks the server for it over MQTT (`time_request` event, answered with a `set_time` command).
- **"Daily dose?" reminder on the LCD:** while a child who takes a daily inhaler has none logged for 26 h. The server sends it in the device's config (`dose_due`): it checks every 5 minutes and only sends changes (`devices:dose-reminders`). Logging a daily dose clears it at once.
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

[Unreleased]: https://github.com/anake-an/asthma-monitoring-system/compare/v6.2.0...HEAD
[6.2.0]: https://github.com/anake-an/asthma-monitoring-system/compare/v6.1.0...v6.2.0
[6.1.0]: https://github.com/anake-an/asthma-monitoring-system/compare/v6.0.0...v6.1.0
[6.0.0]: https://github.com/anake-an/asthma-monitoring-system/compare/v5.0.0...v6.0.0
[5.0.0]: https://github.com/anake-an/asthma-monitoring-system/compare/v4.3.0...v5.0.0
[4.3.0]: https://github.com/anake-an/asthma-monitoring-system/releases/tag/v4.3.0
