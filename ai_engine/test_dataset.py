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

# Rising fast vs stable, and night-time (DESIGN 5.2). Dust jumps from 5 to 25 at 01:00 and stays.
rising = telemetry.assign(pm25_level=[25.0 if m >= start + pd.Timedelta(hours=1) else 5.0 for m in minutes])
dr = main.build_dataset(rising, no_coughs, rescue, controller)
assert dr.loc[t("01:00"), "pm25_change"] == 20.0 and dr.loc[t("01:10"), "pm25_change"] == 0.0, dr["pm25_change"]
assert dr["humidity_change"].eq(0).all()
assert dr.loc[t("00:00"):t("04:00"), "night"].eq(1).all()  # 00:00-05:59 is night
night_minutes = pd.date_range("2026-01-01 21:00", periods=120, freq="1min")
evening = pd.DataFrame({"recorded_at": night_minutes, "pm25_level": 5.0, "temperature": 30.0, "humidity": 70.0})
de = main.build_dataset(evening, no_coughs, no_coughs, no_coughs)
assert de.loc[pd.Timestamp("2026-01-01 21:50"), "night"] == 0 and de.loc[pd.Timestamp("2026-01-01 22:00"), "night"] == 1, de["night"]

# Gas: no gas column (or too few gas readings) means no learned gas; enough readings learn it.
assert "mq135_level_mean" not in main.room_baseline(df)
with_gas = telemetry.assign(mq135_level=[480.0 + (m.minute % 2) * 40 for m in minutes])
b = main.room_baseline(main.build_dataset(with_gas, no_coughs, rescue, controller))
assert b["windows"] >= main.MIN_GAS_WINDOWS or "mq135_level_mean" not in b
assert "mq135_level_mean" not in b, "5 h of readings is below the 6 h needed to learn gas"
longer = pd.date_range(start, periods=8 * 60, freq="1min")
b = main.room_baseline(main.build_dataset(pd.DataFrame({"recorded_at": longer, "pm25_level": 5.0, "temperature": 30.0, "humidity": 70.0, "mq135_level": 500.0}), no_coughs, no_coughs, no_coughs))
assert b["mq135_level_mean"] == 500.0 and b["mq135_level_std"] == 50.0, b  # spread floored by MIN_STD

print("build_dataset: all checks passed")
