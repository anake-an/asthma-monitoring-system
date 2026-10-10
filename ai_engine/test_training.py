"""Checks for model choice and honest evaluation. Run: MODEL_DIR=/tmp/models python test_training.py (CI does)."""
import os

import numpy as np
import pandas as pd

import main

START = pd.Timestamp("2026-01-01 00:00")
MINUTES = pd.date_range(START, periods=72 * 60, freq="1min")  # 3 days, one reading per minute
EMPTY = pd.DataFrame({"recorded_at": pd.to_datetime([])})


def frames(dose_hours):
    """Room air is clean except the hour before each rescue dose, when dust rises: a learnable pattern."""
    doses = [START + pd.Timedelta(hours=h) for h in dose_hours]
    pm25 = 5 + np.sin(np.arange(len(MINUTES)) / 37.0)
    for d in doses:
        pm25[(MINUTES >= d - pd.Timedelta(minutes=60)) & (MINUTES < d)] = 40.0
    telemetry = pd.DataFrame({"recorded_at": MINUTES, "pm25_level": pm25, "temperature": 30.0, "humidity": 70.0})
    return telemetry, EMPTY, pd.DataFrame({"recorded_at": doses}), EMPTY


def risk(key, telemetry, coughs, rescue, controller):
    """Train a patient's risk model from one room."""
    return main.train_risk_from_frames(key, [(telemetry, coughs)], rescue, controller)


# Room model (per device): a baseline of what is normal for that room, no health events needed.
telemetry, *_ = frames([10])
r = main.train_room_from_frames("device_900", telemetry)
assert os.path.exists(main.baseline_path("device_900")) and r["baseline"]["windows"] == r["windows_used"], r

# 1 episode = 6 episode windows: below MIN_POSITIVES the child stays on Stage 1 and no model is kept.
r = risk("patient_901", *frames([10]))
assert r["episode_windows"] == 6 and "Stage 1" in r["message"], r
assert not os.path.exists(main.model_path("patient_901"))

# 3 episodes (18 windows): logistic regression, evaluated on the last 25% (one episode falls there).
r = risk("patient_902", *frames([10, 30, 62]))
assert r["model_type"] == "logistic_regression", r
ev = r["evaluation"]
assert "accuracy" not in str(r).lower(), r
assert ev["test_episode_windows"] == 6 and ev["recall"] is not None and ev["recall"] >= 0.5, ev

# 5 episodes (30 windows): random forest.
r = risk("patient_903", *frames([8, 20, 32, 44, 64]))
assert r["model_type"] == "random_forest", r

# Feature weights work for both model types and sum to 1.
for key in ("patient_902", "patient_903"):
    w = main.feature_weights(__import__("joblib").load(main.model_path(key)))
    assert set(w) == set(main.FEATURES) and abs(sum(w.values()) - 1) < 1e-6, w

# No later episode in the test slice: no score is claimed.
r = risk("patient_904", *frames([10, 20, 30]))
assert r["evaluation"]["recall"] is None and "Too few" in r["evaluation"]["note"], r

# A child with two rooms: both rooms' windows train one model, in time order (the held-out slice
# is still the latest 25 % of time, not "the second room").
bedroom, coughs, rescue, controller = frames([10, 30, 62])
living = bedroom.assign(pm25_level=bedroom["pm25_level"] + 1.0)
r = main.train_risk_from_frames("patient_905", [(bedroom, coughs), (living, coughs)], rescue, controller)
one_room = risk("patient_906", bedroom, coughs, rescue, controller)
assert r["rooms"] == 2 and r["windows_used"] == 2 * one_room["windows_used"], (r, one_room)
assert r["evaluation"]["test_episode_windows"] == 2 * one_room["evaluation"]["test_episode_windows"], r

print("training: all checks passed")
