"""Checks for build_dataset() labels and features. Run: MODEL_DIR=/tmp/models python test_dataset.py (CI does)."""
import pandas as pd

import main

start = pd.Timestamp("2026-01-01 00:00")
minutes = pd.date_range(start, periods=300, freq="1min")  # 5 h of readings, one per minute
telemetry = pd.DataFrame({"recorded_at": minutes, "pm25_level": 5.0, "temperature": 30.0, "humidity": 70.0})
no_coughs = pd.DataFrame({"recorded_at": pd.to_datetime([])})
controller = pd.DataFrame({"recorded_at": [start + pd.Timedelta(minutes=55)]})   # daily dose, window 00:50
rescue = pd.DataFrame({"recorded_at": [start + pd.Timedelta(minutes=185)]})      # rescue dose, window 03:00

df = main.build_dataset(telemetry, no_coughs, rescue, controller)
t = lambda hhmm: pd.Timestamp(f"2026-01-01 {hhmm}")

# Only the hour before the RESCUE dose is labelled "episode soon".
assert df.loc[t("02:00"):t("02:50"), "target_attack_soon"].eq(1).all(), df["target_attack_soon"]
assert df.loc[t("03:00"):, "target_attack_soon"].eq(0).all(), df["target_attack_soon"]
# The hour before the CONTROLLER dose is not an episode (it was, before this fix).
assert df.loc[:t("01:50"), "target_attack_soon"].eq(0).all(), df["target_attack_soon"]

# controller_24h switches on at the dose's window and stays on for 24 h.
assert df.loc[t("00:40"), "controller_24h"] == 0
assert df.loc[t("00:50"):, "controller_24h"].eq(1).all()

# The training features include the new input.
assert "controller_24h" in main.FEATURES and df[main.FEATURES].notna().all(axis=None)

print("build_dataset: all checks passed")
