# RespiroSync diagrams

Diagrams for the report and slides. GitHub draws them from the Mermaid code below. To get an image, open this file on GitHub and take a screenshot, or paste a block into [mermaid.live](https://mermaid.live) and export it as PNG or SVG.

---

## 1. System architecture

```mermaid
flowchart LR
    subgraph Room["Child's room"]
        PICO["Raspberry Pi Pico<br/>+ INMP441 microphone<br/>cough candidates"]
        ESP["ESP32<br/>DHT22, MQ-135, Sharp dust<br/>LCD, buzzer, LEDs"]
        PICO -- "UART<br/>COUGH:level,strength" --> ESP
    end

    subgraph Cloud["Cloudflare"]
        CF["Tunnel + TLS<br/>app. and mqtt. hostnames"]
    end

    subgraph NAS["NAS (Docker Compose)"]
        MQ["Mosquitto<br/>auth + per-device ACL"]
        WORKER["MQTT worker<br/>(Laravel)"]
        API["Laravel API<br/>Sanctum, policies"]
        SCHED["Scheduler<br/>ai:train, ai:optimize,<br/>dose reminders, offline alerts,<br/>telemetry:prune"]
        AI["AI engine<br/>FastAPI + scikit-learn"]
        DB[("MySQL")]
        WEB["Next.js dashboard"]
    end

    USERS["Parents and caregivers<br/>browser / iPhone app"]
    MAIL["Email (SMTP)"]
    PUSH["Web Push"]

    ESP <-- "MQTT over WSS<br/>telemetry, events / config, commands" --> CF
    CF <--> MQ
    MQ <--> WORKER
    WORKER --> DB
    API <--> DB
    SCHED --> AI
    SCHED --> DB
    API <--> AI
    WEB <--> API
    USERS <-- "HTTPS" --> CF
    CF <--> WEB
    WORKER --> MAIL
    WORKER --> PUSH
    SCHED --> MAIL
    SCHED --> PUSH
    MAIL --> USERS
    PUSH --> USERS
```

---

## 2. A reading over its limit (local alarm and alert)

```mermaid
sequenceDiagram
    participant S as Sensors
    participant E as ESP32
    participant B as Mosquitto
    participant W as MQTT worker
    participant D as MySQL
    participant P as Parents (email + push)

    loop every 3 s
        S->>E: dust, gas, temperature, humidity
        E->>E: average last 4 readings (about 12 s)
        alt over the room's limit
            E->>E: LCD "!! DUST HIGH !!", buzzer, red LED (works offline)
        end
        E->>B: telemetry
        B->>W: telemetry
        W->>D: store reading, mark device online
        W->>W: over the limit for the whole last 5 minutes?
        alt yes, and no alert for this reading in the last hour
            W->>D: limit_alerts row
            W->>P: email + push "Dust above 35 for 5 minutes in Bedroom (Aiman)"
        end
    end
```

---

## 3. A cough (detection and alert rule)

```mermaid
sequenceDiagram
    participant M as Microphone
    participant PI as Pico
    participant E as ESP32
    participant W as MQTT worker
    participant P as Parents

    M->>PI: 16 kHz audio
    PI->>PI: RMS of each 16 ms block above the threshold?
    PI->>E: COUGH:2,0.45 (UART)
    E->>E: LCD "Cough heard" + chirp
    E->>W: event (MQTT)
    W->>W: 3 coughs in 10 min, or 2 with strength >= 0.8?
    alt rule met, no alert in the last 10 min
        W->>P: cough alert email + push
    else
        W->>W: logged only
    end
```

---

## 4. How the AI learns and changes limits

```mermaid
flowchart TB
    T["Telemetry<br/>(10-minute windows)"] --> RT["ai:train every 4 h"]
    C["Coughs and rescue doses<br/>(episodes)"] --> RT
    RT --> RM["Room model per device<br/>what is normal here<br/>(mean and spread)"]
    RT --> KM["Risk model per child<br/>(only with enough episodes)"]

    RM --> PRED["Prediction for a room<br/>(predict endpoint)"]
    KM --> PRED
    PRED --> OPT["ai:optimize every 5 min"]
    CAP["Parent's own limit (cap)<br/>and locks"] --> OPT
    RULE["Missed daily dose rule<br/>-15 % until a dose is logged"] --> OPT
    OPT -->|"never above the cap,<br/>max 10 % a day,<br/>once a day per limit"| LIM["Room limits"]
    LIM -->|"retained MQTT config"| DEV["ESP32 local alarm"]
    LIM --> LOG["Limit changes log<br/>(Activity Log)"]
```

---

## 5. Device life cycle

```mermaid
stateDiagram-v2
    [*] --> Pending: token generated in the dashboard
    Pending --> Online: device joins Wi-Fi and sends its first message
    Online --> Offline: no message for 20 s (dashboard shows Offline)
    Offline --> Online: message received
    Offline --> Alerted: silent for 30 min, email + push sent once
    Alerted --> Online: message received, "back online" push
    Online --> [*]: removed in the dashboard (factory reset sent)
```

---

## 6. Sharing a child (roles)

```mermaid
flowchart LR
    O["Owner"] -->|"invites by email,<br/>link valid 7 days"| I["Invitation"]
    I -->|"accepted with the<br/>invited email"| C["Caregiver"]
    I --> V["Viewer"]
    O ---|"everything: limits, rooms,<br/>sharing, deleting"| K(("Child"))
    C ---|"sees all, gets alerts,<br/>logs doses, marks false alarms"| K
    V ---|"sees readings and reports,<br/>alerts only if switched on"| K
```

---

## 7. LCD screens

```mermaid
stateDiagram-v2
    [*] --> Boot: power on
    Boot --> WiFi: "RespiroSync" slides in
    WiFi --> Setup: no Wi-Fi saved
    Setup --> WiFi: entered on RespiroSync-Setup
    WiFi --> Clock: "WiFi connected" + network name
    Clock --> Cloud: "Setting clock"
    Cloud --> Ready: "Connecting cloud"
    Ready --> Pages: "Ready"

    state Pages {
        [*] --> AirQuality
        AirQuality --> RoomClimate: 5 s
        RoomClimate --> ClockPage: 5 s
        ClockPage --> DailyDose: 5 s, only while due
        ClockPage --> AirQuality: 5 s
        DailyDose --> AirQuality: 5 s
    }

    Pages --> Alert: reading over its limit
    Alert --> Pages: back under the limit
    Pages --> Cough: cough heard (2 s)
    Cough --> Pages
```
