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

---

## 4. Rebuild the database from zero

Drops every table (accounts included) and recreates the schema.

```bash
docker compose exec backend php artisan migrate:fresh --force
```
Never add `--seed` on the server: the seeder refuses to run in production (locally it creates `dev@respirosync.test` with a random password, printed once). Afterwards, register a new account in the dashboard and pair the devices again.

---

## 5. Reset the AI engine

Models are stored per user in the `ai_models` volume (`/app/models/user_<id>_model.pkl` and `user_<id>_baseline.pkl`). Deleting them returns that account to Stage 1 (anomaly detection) until it is retrained.

```bash
# one account
docker compose exec ai_engine sh -c 'rm -f /app/models/user_1_*'
# every account
docker compose exec ai_engine sh -c 'rm -f /app/models/*.pkl'
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
