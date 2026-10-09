"""
RespiroSync AI engine.

Every endpoint is scoped to one account (?user_id=). The engine only reads that
user's devices and inhaler logs, and keeps one model per user under MODEL_DIR.

Stage 1 (no asthma events yet): z-score anomaly check against the room's own baseline.
Stage 2 (events recorded):      Random Forest predicting "attack-like event in the next hour".

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
from sqlalchemy import create_engine, text

app = FastAPI(title="RespiroSync AI Engine")

DB_URL = os.getenv("DB_URL") or "mysql+mysqlconnector://{u}:{p}@{h}/{d}".format(
    u=os.getenv("DB_USERNAME", ""),
    p=os.getenv("DB_PASSWORD", ""),
    h=os.getenv("DB_HOST", "db"),
    d=os.getenv("DB_DATABASE", "asthma_db"),
)
engine = create_engine(DB_URL, pool_pre_ping=True)

MODEL_DIR = os.getenv("MODEL_DIR", "/app/models")
os.makedirs(MODEL_DIR, exist_ok=True)

FEATURES = ["pm25_level", "temperature", "humidity", "cough_count"]
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


def model_path(user_id: int) -> str:
    return os.path.join(MODEL_DIR, f"user_{user_id}_model.pkl")


def baseline_path(user_id: int) -> str:
    return os.path.join(MODEL_DIR, f"user_{user_id}_baseline.pkl")


def safe_val(val, default=0.0):
    try:
        f = float(val)
        return default if (math.isnan(f) or math.isinf(f)) else f
    except (TypeError, ValueError):
        return default


def device_ids(user_id: int) -> list:
    with engine.connect() as conn:
        rows = conn.execute(text("SELECT id FROM devices WHERE user_id = :u"), {"u": user_id}).fetchall()
    return [r[0] for r in rows]


def in_clause(ids: list) -> str:
    # ids come from the database as integers; never from the request.
    return ",".join(str(int(i)) for i in ids)


def db_now() -> datetime:
    """Use the database clock so 'last hour' matches how recorded_at was stored."""
    with engine.connect() as conn:
        if engine.dialect.name == "sqlite":
            return pd.to_datetime(conn.execute(text("SELECT CURRENT_TIMESTAMP")).scalar()).to_pydatetime()
        return conn.execute(text("SELECT NOW()")).scalar()


def fetch_user_data(user_id: int):
    ids = device_ids(user_id)
    if not ids:
        raise HTTPException(status_code=404, detail="This account has no paired devices.")
    dev = in_clause(ids)
    telemetry = pd.read_sql(
        f"SELECT recorded_at, pm25_level, temperature, humidity FROM telemetry_logs WHERE device_id IN ({dev})", engine
    )
    # Events the caregiver marked as false alarms (is_verified = 0) are excluded from training.
    coughs = pd.read_sql(
        f"SELECT recorded_at FROM cough_events WHERE device_id IN ({dev}) "
        f"AND (is_verified IS NULL OR is_verified = 1)",
        engine,
    )
    inhaler = pd.read_sql(
        text("SELECT administered_at AS recorded_at FROM inhaler_logs WHERE user_id = :u"), engine, params={"u": user_id}
    )
    for df in (telemetry, coughs, inhaler):
        df["recorded_at"] = pd.to_datetime(df["recorded_at"])
    return telemetry, coughs, inhaler


def forward_window(series: pd.Series, n: int, how: str) -> pd.Series:
    """Aggregate the NEXT n windows (t+1 .. t+n), excluding the current one."""
    rev = series[::-1].rolling(n, min_periods=1)
    agg = rev.max() if how == "max" else rev.sum()
    return agg[::-1].shift(-1)


def build_dataset(telemetry, coughs, inhaler) -> pd.DataFrame:
    tel = telemetry.set_index("recorded_at").sort_index()
    df = tel.resample(WINDOW).mean()
    # Bridge short gaps only. Long gaps stay NaN and are dropped: no invented readings.
    df = df.interpolate(limit=MAX_INTERPOLATE_WINDOWS, limit_area="inside")

    df["cough_count"] = coughs.set_index("recorded_at").resample(WINDOW).size().reindex(df.index).fillna(0) \
        if not coughs.empty else 0
    inh = (inhaler.set_index("recorded_at").resample(WINDOW).size() > 0).astype(int).reindex(df.index).fillna(0) \
        if not inhaler.empty else pd.Series(0, index=df.index)

    future_inhaler = forward_window(inh, HORIZON, "max")
    future_coughs = forward_window(df["cough_count"], HORIZON, "sum")
    df["target_attack_soon"] = ((future_inhaler > 0) | (future_coughs >= 2)).astype(int)
    df["_has_future"] = future_coughs.notna()

    df = df.dropna(subset=FEATURES)
    return df[df["_has_future"]].drop(columns="_has_future")


@app.get("/train")
def train_model(user_id: int = Query(..., ge=1)):
    telemetry, coughs, inhaler = fetch_user_data(user_id)
    if telemetry.empty:
        raise HTTPException(status_code=400, detail="Not enough data to train.")

    df = build_dataset(telemetry, coughs, inhaler)
    if len(df) == 0:
        raise HTTPException(status_code=400, detail="Not enough valid data after preprocessing. Please wait for more telemetry.")

    X, y = df[FEATURES], df["target_attack_soon"]

    if y.sum() == 0:
        baseline = {}
        for col in ("pm25_level", "temperature", "humidity"):
            baseline[f"{col}_mean"] = safe_val(df[col].mean())
            baseline[f"{col}_std"] = max(safe_val(df[col].std(), 0.0), MIN_STD[col])
        joblib.dump(baseline, baseline_path(user_id))
        if os.path.exists(model_path(user_id)):
            os.remove(model_path(user_id))
        return {"message": "No asthma events recorded yet. Stage 1 baseline (anomaly detection) created.",
                "windows_used": len(df), "baseline": baseline}

    # Time-ordered hold-out: train on the earliest 75%, validate on the latest 25%.
    split = int(len(df) * 0.75)
    validation_accuracy = None
    note = "Not enough data for a held-out validation; accuracy not reported."
    if split >= 20 and len(df) - split >= 5 and y.iloc[:split].nunique() == 2:
        probe = RandomForestClassifier(n_estimators=100, random_state=42, class_weight="balanced")
        probe.fit(X.iloc[:split], y.iloc[:split])
        validation_accuracy = round(float(probe.score(X.iloc[split:], y.iloc[split:])), 3)
        note = "Accuracy on the most recent 25% of windows, which the model did not train on."

    model = RandomForestClassifier(n_estimators=100, random_state=42, class_weight="balanced")
    model.fit(X, y)
    joblib.dump(model, model_path(user_id))

    return {
        "message": "Stage 2 personalised model trained.",
        "windows_used": len(df),
        "positive_windows": int(y.sum()),
        "validation_accuracy": validation_accuracy,
        "note": note,
    }


@app.get("/predict")
def predict_attack(user_id: int = Query(..., ge=1)):
    has_stage2 = os.path.exists(model_path(user_id))
    has_stage1 = os.path.exists(baseline_path(user_id))
    if not has_stage2 and not has_stage1:
        raise HTTPException(status_code=400, detail="Model not trained yet. Call /train first.")

    ids = device_ids(user_id)
    if not ids:
        raise HTTPException(status_code=404, detail="This account has no paired devices.")
    dev = in_clause(ids)
    now = db_now()
    # Features must match training: the latest 10-minute window (readings averaged, coughs counted).
    since = now - timedelta(minutes=10)

    recent = pd.read_sql(
        text(f"SELECT AVG(pm25_level) AS pm25, AVG(temperature) AS temp, AVG(humidity) AS hum "
             f"FROM telemetry_logs WHERE device_id IN ({dev}) AND recorded_at >= :since"),
        engine, params={"since": since},
    )

    def count_coughs(start):
        return int(pd.read_sql(
            text(f"SELECT COUNT(*) AS c FROM cough_events WHERE device_id IN ({dev}) AND recorded_at >= :since "
                 f"AND (is_verified IS NULL OR is_verified = 1)"),
            engine, params={"since": start},
        )["c"][0])

    cough_count = count_coughs(since)
    coughs_last_hour = count_coughs(now - timedelta(hours=1))

    if recent[["pm25", "temp", "hum"]].isnull().any(axis=None):
        raise HTTPException(status_code=400, detail="No complete telemetry in the last 10 minutes.")

    pm25, temp, hum = (float(recent[c][0]) for c in ("pm25", "temp", "hum"))
    X_new = pd.DataFrame([[pm25, temp, hum, cough_count]], columns=FEATURES)
    thresholds = dict(DEFAULT_THRESHOLDS)

    if has_stage2:
        model = joblib.load(model_path(user_id))
        probability = float(model.predict_proba(X_new)[0][list(model.classes_).index(1)])
        # Lower a threshold by up to 50% in proportion to that feature's importance and the current risk.
        imp = dict(zip(FEATURES, model.feature_importances_))
        for key, feat in (("pm25_threshold", "pm25_level"), ("temperature_threshold", "temperature"),
                          ("humidity_threshold", "humidity")):
            thresholds[key] -= thresholds[key] * imp[feat] * probability * 0.5
        thresholds["mq135_threshold"] -= thresholds["mq135_threshold"] * probability * 0.3
        floors = {"pm25_threshold": 15.0, "temperature_threshold": 26.0, "humidity_threshold": 55.0, "mq135_threshold": 700.0}
        thresholds = {k: round(max(floors[k], v), 1) for k, v in thresholds.items()}
        stage = "Stage 2 (Personalised)"
    else:
        b = joblib.load(baseline_path(user_id))
        z = max((pm25 - b["pm25_level_mean"]) / b["pm25_level_std"],
                (temp - b["temperature_mean"]) / b["temperature_std"],
                (hum - b["humidity_mean"]) / b["humidity_std"])
        probability = 0.8 if z > 2.5 else 0.5 if z > 1.5 else 0.1
        if probability > 0.7:
            thresholds = {"pm25_threshold": 20.0, "temperature_threshold": 30.0, "humidity_threshold": 60.0, "mq135_threshold": 800.0}
        elif probability > 0.4:
            thresholds = {"pm25_threshold": 25.0, "temperature_threshold": 32.0, "humidity_threshold": 65.0, "mq135_threshold": 900.0}
        stage = "Stage 1 (Anomaly Detection)"

    return {
        "model_stage": stage,
        "probability_of_attack": round(probability, 2),
        "suggested_thresholds": thresholds,
        "current_inputs": {
            "pm25": round(pm25, 1), "temperature": round(temp, 1), "humidity": round(hum, 1),
            "coughs_last_10_min": cough_count, "coughs_last_hour": coughs_last_hour,
        },
    }
