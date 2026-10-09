# RespiroSync Reset Commands

This document contains useful commands to clear out existing data, reset the database, or reset the AI Engine to its default state. This is highly useful for testing or presenting a fresh dashboard.

---

## 1. Database: Soft Reset (Clear Only Event Logs)
Use this if you want to wipe out all the graphs, cough history, and medication history to start fresh, **but you want to keep your user accounts and Smart Alerts config intact**.

**Step 1:** Open a terminal in the `backend` folder and run Tinker:
```bash
cd backend
sudo docker compose exec backend php artisan tinker
```

**Step 2:** Paste the following commands into the Tinker console to truncate the log tables:
```php
DB::table('telemetry_logs')->truncate();
DB::table('cough_events')->truncate();
DB::table('inhaler_logs')->truncate();
exit;
```

---

## 2. Database: Hard Reset (Nuke Everything)
Use this if you want to completely destroy **all** data in the database (including users and configurations) and rebuild the tables from absolute zero.

```bash
cd backend
php artisan migrate:fresh --seed
```
*(Note: If you have seeders set up, this will re-populate default demo data. If you don't have seeders, the database will be 100% empty).*

---

## 3. Reset AI Engine (Forget Training Data)
If you want to force the AI to forget all logged doses and return to **"Stage 1 (Anomaly Detection)"**, you need to delete its compiled model files (`.pkl`).

Models are stored per user in the `ai_models` Docker volume (`/app/models/user_<id>_model.pkl` and `user_<id>_baseline.pkl`).

```bash
# one user
docker compose exec ai_engine sh -c 'rm -f /app/models/user_1_*'
# everyone
docker compose exec ai_engine sh -c 'rm -f /app/models/*.pkl'
```
No restart is needed: the engine checks for the files on every request.

---

## 4. Reset Browser State (Frontend)
If you cleared the database but your frontend is acting weird or still stuck in "Sleep Mode", clear your Local Storage in the browser:
1. Right-click the website -> **Inspect**
2. Go to the **Application** tab (Chrome) or **Storage** tab (Firefox)
3. Select **Local Storage** on the left menu
4. Right-click your website URL and select **Clear**
5. Refresh the page!

## 5. Docker Full Hard Reset
If you are running the system via Docker and want to nuke the database and models completely:
```bash
# Destroys the database AND the ai_models volume, then recreates everything.
docker compose down -v
docker compose up -d
# The backend runs `php artisan migrate --force` on start, so the tables are recreated automatically.
# (Do not use --seed in production: the seeder creates admin@asthma.local / password123.)
```

## 6. Send Factory Reset to ESP32 Manually
Removing the device in the dashboard does this for you. To do it by hand (the broker requires the backend account):
```bash
docker compose exec mqtt mosquitto_pub -u respirosync_backend -P 'BACKEND_PASSWORD' -t respirosync/devices/YOUR_TOKEN/commands -m '{"command":"factory_reset"}'
```
*(Replace `YOUR_TOKEN` with the device token and `BACKEND_PASSWORD` with the broker password from `.env`.)*

## 7. Run the backend tests
**Never** use `docker compose exec backend php artisan test`: the container's `DB_CONNECTION=mysql` overrides `phpunit.xml` and the tests would wipe the live database. Use a throwaway container with no network instead:
```bash
docker run --rm --network none -v "$PWD/backend:/app" -w /app webdevops/php:8.2-alpine php artisan test
```
