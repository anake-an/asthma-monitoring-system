"""
RespiroSync AI engine.

Two levels (DESIGN_MULTI_PATIENT.md section 5), kept under MODEL_DIR:
  room model per device   (/train/room?device_id=)   what is normal for that room
  risk model per patient  (/train/risk?patient_id=)  what preceded that child's episodes, from
                                                      their own rooms (never a shared room)
/predict?device_id= answers for one room. Access control is done by the backend, which only
calls with ids the signed-in user may see; the engine is not reachable from outside.

Stage 1 (no asthma events yet): z-score anomaly check against the room's own baseline.
Stage 2 (events recorded):      logistic regression / Random Forest predicting
                                "attack-like event in the next hour" for the room's child.

These are prototype models trained on small, self-reported data. The reported
accuracy is measured on a held-out, later slice of the data when there is
enough of it; otherwise no accuracy is claimed.
"""
import math
import os
from datetime import datetime, timedelta

import joblib
import numpy as np
import pandas as pd
from fastapi import FastAPI, HTTPException, Query
from sklearn.ensemble import RandomForestClassifier
from sklearn.linear_model import LogisticRegression
from sklearn.metrics import precision_score, recall_score
from sklearn.pipeline import make_pipeline
from sklearn.preprocessing import StandardScaler
from sqlalchemy import create_engine, text
from sqlalchemy.engine import URL

app = FastAPI(title="RespiroSync AI Engine")

# Built from parts, not pasted into a string: a password containing '@', ':' or '/' would
# otherwise be split in the wrong place (it was: "Unknown MySQL server host '...@db'").
DB_URL = os.getenv("DB_URL") or URL.create(
    "mysql+mysqlconnector",
    username=os.getenv("DB_USERNAME", ""),
    password=os.getenv("DB_PASSWORD", ""),
    host=os.getenv("DB_HOST", "db"),
    database=os.getenv("DB_DATABASE", "asthma_db"),
)
engine = create_engine(DB_URL, pool_pre_ping=True)

MODEL_DIR = os.getenv("MODEL_DIR", "/app/models")
os.makedirs(MODEL_DIR, exist_ok=True)

# controller_24h: 1 if a controller (daily, preventive) dose was logged in the 24 h up to this window.
# It is an input, not a label: only rescue doses mark an episode.
FEATURES = ["pm25_level", "temperature", "humidity", "cough_count", "controller_24h"]
CONTROLLER_WINDOWS = 144  # 24 h of 10-minute windows
WINDOW = "10min"
HORIZON = 6  # windows = 60 minutes
MAX_INTERPOLATE_WINDOWS = 3  # bridge gaps up to 30 min; longer gaps (device offline) are dropped
MIN_STD = {"pm25_level": 2.0, "temperature": 0.5, "humidity": 2.0}

DEFAULT_THRESHOLDS = {
    "pm25_threshold": 35.0,
    "temperature_threshold": 35.0,
    "humidity_threshold": 75.0,  # Malaysian indoor baseline
    "mq135_threshold": 1000.0,  # estimated ppm (CO2-equivalent) from the firmware
}


# Safety range for any automatically suggested limit (DESIGN_MULTI_PATIENT.md section 5.4).
LIMIT_RANGE = {
    "pm25_threshold": (15.0, 55.0),
    "temperature_threshold": (26.0, 38.0),
    "humidity_threshold": (55.0, 90.0),
    "mq135_threshold": (700.0, 3000.0),
}
ROOM_FEATURE = {"pm25_threshold": "pm25_level", "temperature_threshold": "temperature", "humidity_threshold": "humidity"}


def room_normal_limits(baseline: dict | None) -> dict | None:
    """Top of the room's normal range (mean + 2 std) for each limit that has a room reading."""
    if not baseline:
        return None
    return {key: round(baseline[f"{feat}_mean"] + 2 * baseline[f"{feat}_std"], 1) for key, feat in ROOM_FEATURE.items()}


def respect_room_normal(thresholds: dict, baseline: dict | None) -> dict:
    """The AI may tighten limits, but never below the room's own normal range (learned mean + 2 std):
    a limit inside the normal range alarms all the time. Every limit also stays within LIMIT_RANGE.
    Example: a room that is normally 69.6 +/- 2 % humidity never gets a humidity limit below 73.6 %."""
    out = {}
    for key, value in thresholds.items():
        feature = ROOM_FEATURE.get(key)
        if baseline and feature:
            value = max(value, baseline[f"{feature}_mean"] + 2 * baseline[f"{feature}_std"])
        low, high = LIMIT_RANGE[key]
        out[key] = round(min(max(value, low), high), 1)
    return out


# Learned limits (DESIGN_MULTI_PATIENT.md 5.4): each room reading's limit sits LEARNED_STD spreads above
# the room's own mean. Usual readings stay below it (about 1 window in 700 for a normal spread), so the
# alarm means "clearly unusual for this room" rather than "above a fixed national number".
# Gas is not learned: the MQ-135 estimate drifts with temperature and humidity, so it keeps its default.
LEARNED_STD = 3.0

# Stage 1 levels: how far above the room mean each limit sits, and the gas limit, per room level.
#   normal        -> the learned limits (mean + 3 std)
#   unusual       -> mean + 2.5 std
#   very unusual  -> mean + 2 std, the top of the room's normal range (never lower: respect_room_normal)
STAGE1_LEVELS = {0.1: (LEARNED_STD, 1000.0), 0.5: (2.5, 900.0), 0.8: (2.0, 800.0)}


def room_limits(baseline: dict | None, stds: float = LEARNED_STD,
                gas: float = DEFAULT_THRESHOLDS["mq135_threshold"]) -> dict:
    """Limits learned from the room: mean + `stds` spreads per room reading, within LIMIT_RANGE.
    Without a baseline, the defaults. Example: a room at 2.6 +/- 3.5 ug/m3 dust gets a PM2.5 limit of
    13.0, raised to the 15 floor; one at 69.6 +/- 2 % humidity gets 75.6."""
    limits = dict(DEFAULT_THRESHOLDS, mq135_threshold=gas)
    if baseline:
        for key, feature in ROOM_FEATURE.items():
            limits[key] = baseline[f"{feature}_mean"] + stds * baseline[f"{feature}_std"]
    return respect_room_normal(limits, baseline)


def stage1(baseline: dict, pm25: float, temp: float, hum: float) -> tuple[float, dict]:
    """Stage 1 (no personal model yet): how unusual the current window is for this room, as one of
    three fixed levels (0.1 normal, 0.5 unusual, 0.8 very unusual, not a calculated probability),
    and the limits for that level, tightened from the room's learned limits."""
    z = max((pm25 - baseline["pm25_level_mean"]) / baseline["pm25_level_std"],
            (temp - baseline["temperature_mean"]) / baseline["temperature_std"],
            (hum - baseline["humidity_mean"]) / baseline["humidity_std"])
    level = 0.8 if z > 2.5 else 0.5 if z > 1.5 else 0.1
    return level, room_limits(baseline, *STAGE1_LEVELS[level])


# The AI only adjusts limits once the room baseline covers a full day (24 h of 10-minute windows):
# a few hours miss the day/night cycle (DESIGN_MULTI_PATIENT.md 5.6, "no change until minimum data").
MIN_ADJUST_WINDOWS = 144


def room_baseline(df: pd.DataFrame) -> dict:
    """Mean and spread of each room reading over the training data (spread floored by MIN_STD)."""
    baseline = {"windows": len(df)}
    for col in ("pm25_level", "temperature", "humidity"):
        baseline[f"{col}_mean"] = safe_val(df[col].mean())
        baseline[f"{col}_std"] = max(safe_val(df[col].std(), 0.0), MIN_STD[col])
    return baseline


# Model files (DESIGN_MULTI_PATIENT.md section 5): a room model per device ("device_<id>": what is
# normal for that room) and a risk model per patient ("patient_<id>": what triggers that child).
def room_key(device_id: int) -> str:
    return f"device_{int(device_id)}"


def risk_key(patient_id: int) -> str:
    return f"patient_{int(patient_id)}"


def model_path(key: str) -> str:
    """Stage 2 risk model of a patient."""
    return os.path.join(MODEL_DIR, f"{key}_model.pkl")


def baseline_path(key: str) -> str:
    """Room baseline (mean and spread of each reading) of a device."""
    return os.path.join(MODEL_DIR, f"{key}_baseline.pkl")


def meta_path(key: str) -> str:
    """Model type and evaluation of a patient's Stage 2 model, returned by /predict."""
    return os.path.join(MODEL_DIR, f"{key}_meta.pkl")


def safe_val(val, default=0.0):
    try:
        f = float(val)
        return default if (math.isnan(f) or math.isinf(f)) else f
    except (TypeError, ValueError):
        return default


def device_patient(device_id: int):
    """The device's patient id, or None for a shared room. 404 when the device does not exist."""
    with engine.connect() as conn:
        row = conn.execute(text("SELECT patient_id FROM devices WHERE id = :d"), {"d": device_id}).fetchone()
    if row is None:
        raise HTTPException(status_code=404, detail="Unknown device.")
    return row[0]


def patient_device_ids(patient_id: int) -> list:
    """The patient's own rooms. Shared rooms have no patient, so they never train a child's model."""
    with engine.connect() as conn:
        rows = conn.execute(text("SELECT id FROM devices WHERE patient_id = :p ORDER BY id"), {"p": patient_id}).fetchall()
    return [r[0] for r in rows]


def db_now() -> datetime:
    """Use the database clock so 'last hour' matches how recorded_at was stored."""
    with engine.connect() as conn:
        if engine.dialect.name == "sqlite":
            return pd.to_datetime(conn.execute(text("SELECT CURRENT_TIMESTAMP")).scalar()).to_pydatetime()
        return conn.execute(text("SELECT NOW()")).scalar()


def _frame(sql: str, params: dict) -> pd.DataFrame:
    df = pd.read_sql(text(sql), engine, params=params)
    df["recorded_at"] = pd.to_datetime(df["recorded_at"])
    return df


def fetch_telemetry(device_id: int) -> pd.DataFrame:
    return _frame("SELECT recorded_at, pm25_level, temperature, humidity FROM telemetry_logs WHERE device_id = :d",
                  {"d": device_id})


def fetch_coughs(device_id: int) -> pd.DataFrame:
    # Events the caregiver marked as false alarms (is_verified = 0) are excluded from training.
    return _frame("SELECT recorded_at FROM cough_events WHERE device_id = :d AND (is_verified IS NULL OR is_verified = 1)",
                  {"d": device_id})


def fetch_doses(patient_id: int):
    """(rescue, controller) doses of a patient. Rescue doses mark episodes; controller (daily) doses
    are preventive: counting them as episodes taught the model that every daily dose was an attack."""
    inhaler = _frame("SELECT administered_at AS recorded_at, type FROM inhaler_logs WHERE patient_id = :p", {"p": patient_id})
    return (inhaler.loc[inhaler["type"] != "controller", ["recorded_at"]],
            inhaler.loc[inhaler["type"] == "controller", ["recorded_at"]])


def forward_window(series: pd.Series, n: int, how: str) -> pd.Series:
    """Aggregate the NEXT n windows (t+1 .. t+n), excluding the current one."""
    rev = series[::-1].rolling(n, min_periods=1)
    agg = rev.max() if how == "max" else rev.sum()
    return agg[::-1].shift(-1)


def per_window(events: pd.DataFrame, index: pd.DatetimeIndex) -> pd.Series:
    """Number of events in each 10-minute window of `index` (0 where none)."""
    if events.empty:
        return pd.Series(0, index=index)
    return events.set_index("recorded_at").resample(WINDOW).size().reindex(index).fillna(0)


def build_dataset(telemetry, coughs, rescue, controller) -> pd.DataFrame:
    tel = telemetry.set_index("recorded_at").sort_index()
    df = tel.resample(WINDOW).mean()
    # Bridge short gaps only. Long gaps stay NaN and are dropped: no invented readings.
    df = df.interpolate(limit=MAX_INTERPOLATE_WINDOWS, limit_area="inside")

    df["cough_count"] = per_window(coughs, df.index)
    inh = (per_window(rescue, df.index) > 0).astype(int)
    # Was a controller dose logged in the last 24 h (this window included)?
    df["controller_24h"] = (per_window(controller, df.index).rolling(CONTROLLER_WINDOWS, min_periods=1).sum() > 0).astype(int)

    future_inhaler = forward_window(inh, HORIZON, "max")
    future_coughs = forward_window(df["cough_count"], HORIZON, "sum")
    df["target_attack_soon"] = ((future_inhaler > 0) | (future_coughs >= 2)).astype(int)
    df["_has_future"] = future_coughs.notna()

    df = df.dropna(subset=FEATURES)
    return df[df["_has_future"]].drop(columns="_has_future")


# A risk model needs enough "episode soon" windows (each episode labels the 6 windows before it).
# Below MIN_POSITIVES the account stays on Stage 1. Logistic regression is stable on little data;
# a Random Forest needs more examples before it stops memorising (DESIGN_MULTI_PATIENT.md 5.3).
MIN_POSITIVES = 12        # ~2 episodes
RF_MIN_POSITIVES = 20     # ~4 episodes
MIN_EVAL_POSITIVES = 2    # held-out episode windows needed before any metric is reported


def make_model(positives: int):
    if positives >= RF_MIN_POSITIVES:
        return "random_forest", RandomForestClassifier(n_estimators=100, random_state=42, class_weight="balanced")
    return "logistic_regression", make_pipeline(StandardScaler(), LogisticRegression(class_weight="balanced", max_iter=1000))


def feature_weights(model) -> dict:
    """Relative influence of each input (sums to 1): forest importances, or |coefficient| of the
    logistic regression (its inputs are standardised, so coefficients are comparable)."""
    if hasattr(model, "feature_importances_"):
        w = np.asarray(model.feature_importances_, dtype=float)
    else:
        w = np.abs(model[-1].coef_[0])
    total = w.sum()
    return dict(zip(FEATURES, w / total if total > 0 else np.zeros(len(FEATURES))))


def evaluate(df: pd.DataFrame) -> dict:
    """Time-ordered hold-out: fit on the earliest 75% of windows, score the latest 25%.
    Reports recall (share of later episode windows the model flagged) and precision (share of its
    warnings that were real), never plain accuracy: ~95% of windows are 'safe', so a model that
    always says 'safe' would score ~95%."""
    split = int(len(df) * 0.75)
    train, test = df.iloc[:split], df.iloc[split:]
    test_pos = int(test["target_attack_soon"].sum())
    result = {"test_windows": len(test), "test_episode_windows": test_pos, "recall": None, "precision": None}
    if test_pos < MIN_EVAL_POSITIVES or train["target_attack_soon"].nunique() < 2:
        result["note"] = "Too few later episodes to evaluate; no score is claimed."
        return result
    _, probe = make_model(int(train["target_attack_soon"].sum()))
    probe.fit(train[FEATURES], train["target_attack_soon"])
    predicted = probe.predict(test[FEATURES])
    result["recall"] = round(float(recall_score(test["target_attack_soon"], predicted, zero_division=0)), 2)
    result["precision"] = round(float(precision_score(test["target_attack_soon"], predicted, zero_division=0)), 2)
    result["note"] = "Measured on the most recent 25% of windows, which the model did not train on."
    return result


NO_EVENTS = pd.DataFrame({"recorded_at": pd.to_datetime([])})


def train_room_from_frames(key: str, telemetry: pd.DataFrame) -> dict:
    """Room model (Stage 1) of one device: what is normal for that room. Needs no health events,
    so it is useful from the first day, also for a shared room."""
    df = build_dataset(telemetry, NO_EVENTS, NO_EVENTS, NO_EVENTS) if not telemetry.empty else telemetry
    if len(df) == 0:
        raise HTTPException(status_code=400, detail="Not enough valid data yet. Please wait for more telemetry.")
    baseline = room_baseline(df)
    joblib.dump(baseline, baseline_path(key))
    return {"message": "Room baseline (anomaly detection) trained.", "windows_used": len(df), "baseline": baseline}


def train_risk_from_frames(key: str, rooms: list, rescue: pd.DataFrame, controller: pd.DataFrame) -> dict:
    """Risk model (Stage 2) of one patient, from the 10-minute windows of each of their rooms
    (rooms = [(telemetry, coughs), ...]), labelled by their rescue doses and the coughs heard in that
    room. Windows of all rooms are combined in time order: the model learns what preceded this
    child's episodes, wherever the child was. Below MIN_POSITIVES no model is kept (Stage 1)."""
    frames = [build_dataset(t, c, rescue, controller) for t, c in rooms if not t.empty]
    frames = [f for f in frames if len(f)]
    if not frames:
        raise HTTPException(status_code=400, detail="Not enough valid data yet. Please wait for more telemetry.")
    df = pd.concat(frames).sort_index(kind="stable")  # time order: evaluate() holds out the latest 25 %

    X, y = df[FEATURES], df["target_attack_soon"]
    positives = int(y.sum())

    if positives < MIN_POSITIVES:
        for path in (model_path(key), meta_path(key)):
            if os.path.exists(path):
                os.remove(path)
        message = ("No asthma events recorded yet." if positives == 0
                   else f"{positives} episode windows so far; a risk model needs {MIN_POSITIVES}.")
        return {"message": f"{message} The rooms use Stage 1 (anomaly detection).",
                "windows_used": len(df), "episode_windows": positives}

    evaluation = evaluate(df)
    model_type, model = make_model(positives)
    model.fit(X, y)
    joblib.dump(model, model_path(key))
    meta = {"model_type": model_type, "rooms": len(frames), "windows_used": len(df),
            "episode_windows": positives, "evaluation": evaluation}
    joblib.dump(meta, meta_path(key))

    return {"message": "Stage 2 personalised model trained.", **meta}


@app.get("/train/room")
def train_room(device_id: int = Query(..., ge=1)):
    device_patient(device_id)  # 404 for an unknown device
    return train_room_from_frames(room_key(device_id), fetch_telemetry(device_id))


@app.get("/train/risk")
def train_risk(patient_id: int = Query(..., ge=1)):
    ids = patient_device_ids(patient_id)
    if not ids:
        raise HTTPException(status_code=404, detail="This patient has no rooms.")
    rooms = [(fetch_telemetry(d), fetch_coughs(d)) for d in ids]
    return train_risk_from_frames(risk_key(patient_id), rooms, *fetch_doses(patient_id))


@app.get("/predict")
def predict_attack(device_id: int = Query(..., ge=1)):
    """Prediction for one room: its own baseline (Stage 1), or its patient's risk model (Stage 2)
    fed with this room's latest window. Limits come from this room's learned normal."""
    patient_id = device_patient(device_id)
    rkey = room_key(device_id)
    pkey = risk_key(patient_id) if patient_id else None
    has_stage1 = os.path.exists(baseline_path(rkey))
    has_stage2 = bool(pkey) and os.path.exists(model_path(pkey))
    if not has_stage2 and not has_stage1:
        raise HTTPException(status_code=400, detail="Model not trained yet. Call /train first.")

    now = db_now()
    # Features must match training: the latest 10-minute window (readings averaged, coughs counted).
    since = now - timedelta(minutes=10)
    recent = pd.read_sql(
        text("SELECT AVG(pm25_level) AS pm25, AVG(temperature) AS temp, AVG(humidity) AS hum "
             "FROM telemetry_logs WHERE device_id = :d AND recorded_at >= :since"),
        engine, params={"d": device_id, "since": since},
    )

    def count_coughs(start):
        return int(pd.read_sql(
            text("SELECT COUNT(*) AS c FROM cough_events WHERE device_id = :d AND recorded_at >= :since "
                 "AND (is_verified IS NULL OR is_verified = 1)"),
            engine, params={"d": device_id, "since": start},
        )["c"][0])

    cough_count = count_coughs(since)
    coughs_last_hour = count_coughs(now - timedelta(hours=1))

    if recent[["pm25", "temp", "hum"]].isnull().any(axis=None):
        raise HTTPException(status_code=400, detail="No complete telemetry in the last 10 minutes.")

    controller_24h = 0
    if patient_id:
        controller_24h = int(pd.read_sql(
            text("SELECT COUNT(*) AS c FROM inhaler_logs WHERE patient_id = :p AND type = 'controller' "
                 "AND administered_at >= :since"),
            engine, params={"p": patient_id, "since": now - timedelta(hours=24)},
        )["c"][0] > 0)

    pm25, temp, hum = (float(recent[c][0]) for c in ("pm25", "temp", "hum"))
    X_new = pd.DataFrame([[pm25, temp, hum, cough_count, controller_24h]], columns=FEATURES)

    # A model saved before the feature list changed cannot score the new inputs: fall back to
    # Stage 1 (or Learning mode) until the next training run replaces it.
    if has_stage2 and getattr(joblib.load(model_path(pkey)), "n_features_in_", len(FEATURES)) != len(FEATURES):
        has_stage2 = False
        if not has_stage1:
            raise HTTPException(status_code=400, detail="Model is outdated; it will be retrained on the next run.")
    room = joblib.load(baseline_path(rkey)) if has_stage1 else None

    if has_stage2:
        model = joblib.load(model_path(pkey))
        probability = float(model.predict_proba(X_new)[0][list(model.classes_).index(1)])
        # Start from the room's learned limits, then lower each by up to 50% in proportion to that
        # feature's importance and the current risk (the risk model only tightens, DESIGN 5.4).
        thresholds = room_limits(room)
        imp = feature_weights(model)
        for key, feat in (("pm25_threshold", "pm25_level"), ("temperature_threshold", "temperature"),
                          ("humidity_threshold", "humidity")):
            thresholds[key] -= thresholds[key] * imp[feat] * probability * 0.5
        thresholds["mq135_threshold"] -= thresholds["mq135_threshold"] * probability * 0.3
        stage = "Stage 2 (Personalised)"
    else:
        probability, thresholds = stage1(room, pm25, temp, hum)
        stage = "Stage 1 (Anomaly Detection)"

    # Never inside the room's normal range, always within the safety range (both stages).
    thresholds = respect_room_normal(thresholds, room)

    return {
        "model_stage": stage,
        "model": (joblib.load(meta_path(pkey)) if has_stage2 and os.path.exists(meta_path(pkey)) else None),
        "probability_of_attack": round(probability, 2),
        "suggested_thresholds": thresholds,
        # Top of the room's normal range per reading (mean + 2 std): a user limit below it will alarm often.
        "room_normal_limits": room_normal_limits(room),
        # Windows behind the room baseline; ai:optimize only changes limits once this covers 24 h.
        "training_windows": (room or {}).get("windows"),
        "ready_to_adjust": bool(room and room.get("windows", 0) >= MIN_ADJUST_WINDOWS),
        "current_inputs": {
            "pm25": round(pm25, 1), "temperature": round(temp, 1), "humidity": round(hum, 1),
            "coughs_last_10_min": cough_count, "coughs_last_hour": coughs_last_hour,
            "controller_dose_last_24h": bool(controller_24h),
        },
    }
