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


# 1 episode = 6 episode windows: below MIN_POSITIVES, the account stays on Stage 1 and no model is kept.
r = main.train_from_frames(901, *frames([10]))
assert r["episode_windows"] == 6 and "Stage 1" in r["message"], r
assert not os.path.exists(main.model_path(901)) and os.path.exists(main.baseline_path(901))

# 3 episodes (18 windows): logistic regression, evaluated on the last 25% (one episode falls there).
r = main.train_from_frames(902, *frames([10, 30, 62]))
assert r["model_type"] == "logistic_regression", r
ev = r["evaluation"]
assert "accuracy" not in str(r).lower(), r
assert ev["test_episode_windows"] == 6 and ev["recall"] is not None and ev["recall"] >= 0.5, ev

# 5 episodes (30 windows): random forest.
r = main.train_from_frames(903, *frames([8, 20, 32, 44, 64]))
assert r["model_type"] == "random_forest", r

# Feature weights work for both model types and sum to 1.
for uid in (902, 903):
    w = main.feature_weights(__import__("joblib").load(main.model_path(uid)))
    assert set(w) == set(main.FEATURES) and abs(sum(w.values()) - 1) < 1e-6, w

# No later episode in the test slice: no score is claimed.
r = main.train_from_frames(904, *frames([10, 20, 30]))
assert r["evaluation"]["recall"] is None and "Too few" in r["evaluation"]["note"], r

print("training: all checks passed")
