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

**If using Windows PowerShell:**
```powershell
Remove-Item -Path "ai_engine\asthma_model.pkl" -ErrorAction SilentlyContinue
Remove-Item -Path "ai_engine\baseline_model.pkl" -ErrorAction SilentlyContinue
```

**If using Mac / Linux / WSL:**
```bash
rm -f ai_engine/*.pkl
```

**After deleting the models, you must restart the AI Engine server** so it recognizes the files are gone!

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
# 1. Delete the saved AI machine learning models
rm -f /volume2/docker/iot-project/ai_engine/*.pkl

# 2. Destroy the entire database volume and restart containers
docker compose down -v
docker compose up -d

# 3. Wait about 10 seconds for MySQL to boot up, then initialize fresh tables
docker compose exec backend php artisan migrate:fresh --seed
```

## 6. Send Factory Reset to ESP32 Manually
If you want to force an ESP32 to wipe its WiFi credentials remotely over MQTT:
```bash
docker compose exec backend php artisan tinker --execute="\$m=new PhpMqtt\Client\MqttClient('mqtt',1883,'sniper');\$m->connect((new PhpMqtt\Client\ConnectionSettings)->setUseTls(false),true);\$m->publish('respirosync/commands/YOUR_TOKEN','{\"command\":\"factory_reset\"}',0);\$m->disconnect();echo \"Kill signal sent!\n\";"
```
*(Replace `YOUR_TOKEN` with the actual device token).*
