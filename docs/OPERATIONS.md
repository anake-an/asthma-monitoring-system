# RespiroSync Operations

Day-to-day commands for a running install: health checks, backups, resets, devices and tests.

Run everything from the project folder (the one with `docker-compose.yaml`). On the reference NAS that is `/volume2/docker/iot-project`, and every `docker` command needs `sudo` in front.

> [!CAUTION]
> Sections 3, 4 and 7 delete data for **every account** on the server. Take a backup (section 2) first.

---

## 1. Health check

```bash
docker compose ps
docker compose logs --tail 30 backend mqtt mqtt-worker scheduler ai_engine
docker compose exec backend php artisan migrate:status
```
All services should be `Up`. The worker log should show `Subscribed to respirosync/devices/+/{telemetry,events}`.

---

## 2. Backup

Both backups go to your home folder, outside the project, so the archive never includes itself.
```bash
# Database (consistent snapshot, no table locks)
docker compose exec -T db sh -c 'exec mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines --triggers asthma_db' > ~/asthma_db-$(date +%F).sql

# Project folder, including .env files and the broker password file
tar czf ~/iot-project-$(date +%F).tgz --exclude=backend/vendor --exclude=frontend/node_modules --exclude=frontend/.next -C .. "$(basename "$PWD")"
```
Both files contain secrets (database, mail and broker credentials, user password hashes). Keep them private: `chmod 600 ~/asthma_db-*.sql ~/iot-project-*.tgz`.

Restore the database into an empty `asthma_db`:
```bash
docker compose exec -T db sh -c 'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" asthma_db' < ~/asthma_db-YYYY-MM-DD.sql
```

---

## 3. Clear event history (keep accounts, devices and settings)

Removes all telemetry, coughs and inhaler logs for every account. Deleted in this order because inhaler logs reference cough events.

```bash
docker compose exec backend php artisan tinker --execute="DB::table('inhaler_logs')->delete(); DB::table('cough_events')->delete(); DB::table('telemetry_logs')->delete();"
```

### Automatic retention (nightly, 03:30)

`php artisan telemetry:prune` runs every night (scheduler container). Sensor readings only; coughs, doses, limit changes and the audit log are never touched.

| Age | Kept as |
|---|---|
| last 7 days | every reading (one every 3 s) |
| 7 days to 1 year | one average per device per 10 minutes (`samples` = how many readings it replaced) |
| older than 1 year | deleted |

The AI averages readings into the same 10-minute windows before it learns, so the averages train it the same way. Run it by hand with `docker compose exec backend php artisan telemetry:prune`; running it twice changes nothing. The numbers are `RAW_DAYS`, `BUCKET_MINUTES` and `KEEP_DAYS` in `backend/app/Models/TelemetryLog.php`.

---

## 4. Rebuild the database from zero

Drops every table (accounts included) and recreates the schema.

```bash
docker compose exec backend php artisan migrate:fresh --force
```
Never add `--seed` on the server: the seeder refuses to run in production (locally it creates `dev@respirosync.test` with a random password, printed once). Afterwards, register a new account in the dashboard and pair the devices again.

---

## 5. Reset the AI engine

Models are stored in the `ai_models` volume, two levels (DESIGN §5): a **room model per device** (`/app/models/device_<id>_baseline.pkl`) and a **risk model per child** (`patient_<id>_model.pkl` + `_meta.pkl`). Deleting them returns that room or child to Learning mode / Stage 1 until it is retrained. Files named `user_<id>_*` are from before patients existed and are no longer read.

```bash
# one room / one child
docker compose exec ai_engine sh -c 'rm -f /app/models/device_1_*'
docker compose exec ai_engine sh -c 'rm -f /app/models/patient_1_*'
# everything
docker compose exec ai_engine sh -c 'rm -f /app/models/*.pkl'
# retrain now instead of waiting for the 4-hourly schedule
docker compose exec backend php artisan ai:train
```
No restart is needed: the engine checks for the files on every request.

---

## 6. Reset the browser state

If the dashboard still shows old data or is stuck in Sleep Mode after a reset, clear the site's Local Storage:
1. Right-click the page, then **Inspect**.
2. **Application** tab (Chrome) or **Storage** tab (Firefox), then **Local Storage**.
3. Right-click the site's URL, **Clear**, and refresh.

---

## 7. Full Docker reset

Destroys the database and the AI models (named volumes), then starts everything again. The backend runs `php artisan migrate --force` on start, so the tables come back empty.

```bash
docker compose down -v
docker compose up -d
```
The broker password file and `.env` files are on disk, not in volumes, so they survive.

---

## 8. Factory-reset a device by hand

Removing a device in the dashboard already does this. By hand (the broker only accepts the backend account for this topic):
```bash
docker compose exec mqtt mosquitto_pub -u respirosync_backend -P 'BACKEND_PASSWORD' -t respirosync/devices/YOUR_TOKEN/commands -m '{"command":"factory_reset"}'
```
Replace `YOUR_TOKEN` with the device's 6-character token and `BACKEND_PASSWORD` with `MQTT_PASSWORD` from `.env`.

---

## 9. Run the backend tests

**Never** run `docker compose exec backend php artisan test`. The container's `DB_CONNECTION=mysql` overrides `phpunit.xml`, and the tests would wipe the live database (`tests/TestCase.php` now refuses, but don't rely on that alone). Use a throwaway container with no network:
```bash
docker run --rm --network none -v "$PWD/backend:/app" -w /app webdevops/php:8.2-alpine php artisan test
```
GitHub Actions runs the same tests on every push (`.github/workflows/ci.yml`).

---

## 10. Scheduled tasks

The `scheduler` container runs these by itself (`backend/routes/console.php`). Run any of them by hand with `docker compose exec backend php artisan <command>`.

| Command | When | What it does |
|---|---|---|
| `ai:train` | every 4 h | Trains each room's model, then each child's risk model. Run it by hand after an AI reset. |
| `ai:optimize` | every 5 min | Applies the AI's limit suggestions per room, and the missed-dose rule, then sends changed limits to the devices. |
| `devices:dose-reminders` | every 5 min | Tells each device whether its child's daily dose is overdue (the LCD's "Daily dose?"). Only devices whose answer changed are sent anything. |
| `devices:offline-alerts` | every 5 min | Emails and pushes a room's alert recipients when its device has been silent for 30 minutes, once per outage. |
| `telemetry:prune` | daily 03:30 | Thins out old readings (section 3). |

The MQTT worker also answers a device's `time_request` event with the current time (`set_time` command). Devices use it when their network blocks the internet time servers.

---

## 11. Testing alerts without waiting

These commands are also in `docs/DEMO.md`, with the steps around them. Replace `ABC123` with the device token.
- **Cough:** use the Pico's test button (GP2) or type `c` / `s` in its Serial Monitor (`hardware/WIRING_GUIDE.md`, "Test button").
- **Device offline:** unplug the device, then:
  ```bash
  docker compose exec backend php artisan tinker --execute='App\Models\Device::where("device_token","ABC123")->update(["last_seen_at" => now()->subMinutes(31)]);'
  docker compose exec backend php artisan devices:offline-alerts
  ```
  Plug it back in to get the "back online" push.
- **Reading over its limit:** set the limit below the current reading in Smart Alerts. The device alarms at once; the email and push follow after 5 minutes.
