"""Checks for respect_room_normal(). Run: MODEL_DIR=/tmp/models python test_thresholds.py (CI does)."""
import main

# Baseline learned on the reference device in Kota Kinabalu (2026-10-10).
ROOM = {
    "pm25_level_mean": 2.644, "pm25_level_std": 3.459,
    "temperature_mean": 30.44, "temperature_std": 0.5,
    "humidity_mean": 69.57, "humidity_std": 2.0,
}

# Stage 1 "very unusual" tier: humidity 60 and temperature 30 sit inside this room's normal range
# (they alarmed all the time); they must be raised to mean + 2 std.
very_unusual = {"pm25_threshold": 20.0, "temperature_threshold": 30.0, "humidity_threshold": 60.0, "mq135_threshold": 800.0}
assert main.respect_room_normal(very_unusual, ROOM) == {
    "pm25_threshold": 20.0, "temperature_threshold": 31.4, "humidity_threshold": 73.6, "mq135_threshold": 800.0,
}, main.respect_room_normal(very_unusual, ROOM)

# Limits already above the normal range are left alone.
defaults = dict(main.DEFAULT_THRESHOLDS)
assert main.respect_room_normal(defaults, ROOM) == {
    "pm25_threshold": 35.0, "temperature_threshold": 35.0, "humidity_threshold": 75.0, "mq135_threshold": 1000.0,
}

# Safety range: a very humid room cannot push the humidity limit above 90 %,
# and no limit goes below its floor even without a baseline.
humid_room = dict(ROOM, humidity_mean=88.0)
assert main.respect_room_normal(defaults, humid_room)["humidity_threshold"] == 90.0
assert main.respect_room_normal({"pm25_threshold": 5.0, "temperature_threshold": 20.0,
                                 "humidity_threshold": 40.0, "mq135_threshold": 100.0}, None) == {
    "pm25_threshold": 15.0, "temperature_threshold": 26.0, "humidity_threshold": 55.0, "mq135_threshold": 700.0,
}

print("respect_room_normal: all checks passed")
