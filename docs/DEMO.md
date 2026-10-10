# Demo script (about 12 minutes)

A walk-through for a presentation or assessment. It shows every part working live, including the alerts that normally take minutes or hours to trigger.

NAS commands run from the project folder (`/volume2/docker/iot-project`) and need `sudo`. Replace `ABC123` with your device's token (shown in Account Settings → Children & rooms) and `Aiman` with your child's name.

---

## Before the demo (10 minutes earlier)

- [ ] **Power:** ESP32 and Pico powered for at least **5 minutes**, so the gas sensor has finished warming up and the LCD shows numbers.
- [ ] **Dashboard:** open it on the laptop (projector) and on the iPhone. The iPhone app must have been added to the Home Screen, with notifications on.
- [ ] **Second account:** for the sharing part, register a second account in a private browser window.
- [ ] **Email:** have the inbox open on the phone.
- [ ] **Buzzer:** decide whether it may beep. If not, turn on "Silence Physical Alarm" in Smart Alerts. The LCD and LEDs still show alarms.
- [ ] **Limit alert:** for the 5-minute email to arrive during the demo, do step 3 now (set the PM2.5 limit below the current reading) and leave it.

---

## 1. The device (1 min)

1. Press the ESP32's **EN** (reset) button. The start-up appears: RespiroSync slides in, then Connecting WiFi, WiFi connected (network name), Setting clock, Connecting cloud, Ready.
2. Point out the pages, every 5 s: **Air quality** (PM2.5 and gas), **Room climate** (temperature and humidity), the **clock** with the online icon.
3. Press the **BOOT** button to skip to the next page. At night, the screen is dark and BOOT lights it.

## 2. The dashboard (2 min)

1. Live readings update every few seconds. They are the same values as on the LCD.
2. The **room picker** in the header shows the room with the child's badge and an online dot.
3. Show the **AI panel**:
   - A new room is in **Learning**, then **Stage 1**: it compares the room with its own usual readings.
   - **Stage 2** (a risk model per child) needs several real episodes, so it is not shown live.

## 3. A reading over its limit (2 min, the alert arrives later)

1. Open **Smart Alerts**. Set **PM2.5** a little below the current reading, then **Save**.
2. Within seconds:
   - the LCD shows `!! DUST HIGH !!` with the reading and the limit;
   - the backlight blinks 3 times;
   - the red LED lights (and the buzzer beeps unless muted).

   This works even without the internet.
3. After **5 minutes** over the limit, everyone with alerts on gets an **email** and a **push**. If you started this before the demo, show them now.
4. Set the limit back. The LCD returns to the pages.

## 4. A cough (2 min)

1. On the **Pico test button** (GP2), press **short three times** within a minute. Without a button, type `c` three times in the Pico's Serial Monitor.
2. Each press: the Pico's LED blinks, the LCD shows "Cough heard" with a pulse, and the ESP32 chirps.
3. The dashboard's cough history shows three events. The third meets the rule (**3 coughs in 10 minutes**), so it is an **Alert**: email and push.
4. Mention that a long press (a strong cough) alerts after 2.
5. Explain that the microphone today detects loud bursts, not coughs specifically (Phase 2: a trained model).

## 5. Device offline (1 min)

Normally it takes 30 minutes. To show it now:
1. **Unplug the ESP32.**
2. On the NAS, pretend it went silent 31 minutes ago, then run the check:
   ```bash
   sudo docker compose exec backend php artisan tinker --execute='App\Models\Device::where("device_token","ABC123")->update(["last_seen_at" => now()->subMinutes(31)]);'
   ```
   ```bash
   sudo docker compose exec backend php artisan devices:offline-alerts
   ```
3. The phone gets **"Device offline"** (push and email).
4. **Plug the ESP32 back in.** On its first message, the phone gets **"Back online"**, which replaces the offline push.

## 6. Daily-dose reminder (1 min)

It appears when a child who uses a daily inhaler has none logged for 26 hours. To show it now:
1. On the NAS, add a pretend daily dose from 2 days ago, and send the reminder:
   ```bash
   sudo docker compose exec backend php artisan tinker --execute='$p = App\Models\Patient::where("name","Aiman")->firstOrFail(); App\Models\InhalerLog::create(["user_id" => $p->users()->first()->id, "patient_id" => $p->id, "is_manual" => true, "type" => "controller", "administered_at" => now()->subDays(2)]);'
   ```
   ```bash
   sudo docker compose exec backend php artisan devices:dose-reminders
   ```
2. The LCD's pages now include **"Daily dose? / Log it in app"**.
3. In the dashboard, log a **Daily (Brown)** inhaler dose. The reminder disappears from the LCD within seconds.

## 7. Report and sharing (2 min)

1. Open the **Activity Log**:
   - summary cards;
   - the 7-day chart;
   - alert limit changes with who changed them and why.
2. **Print PDF** on the laptop. On the iPhone app, **Share PDF** opens the share sheet (Print, Files, Mail).
3. Account Settings → Children & rooms → **Share**: invite the second account as a **viewer**. The viewer sees readings and the report, but cannot change limits, log doses or pair devices.

## 8. Privacy (30 s)

- Only a child's name and badge are stored, never a photo.
- Data can be downloaded as CSV files from the Activity Log.
- Deleting a child or account also deletes the AI models trained on it.
- Old readings are thinned out automatically.

---

## After the demo

- **Limits:** put the PM2.5 limit back; turn off "Silence Physical Alarm" if you turned it on.
- **Test coughs and the pretend dose:** they are stored like real ones. Mark the coughs as **false alarm** in the dashboard. To remove the pretend dose:
  ```bash
  sudo docker compose exec backend php artisan tinker --execute='App\Models\InhalerLog::where("type","controller")->where("administered_at","<",now()->subDay())->where("is_manual",true)->latest("id")->first()?->delete();'
  ```
- **Clean slate:** to clear all events at once, see `OPERATIONS.md` section 3.
