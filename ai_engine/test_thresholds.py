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

# The room's normal-range tops reported to the dashboard (used to warn about a user limit below them).
assert main.room_normal_limits(ROOM) == {"pm25_threshold": 9.6, "temperature_threshold": 31.4, "humidity_threshold": 73.6}
assert main.room_normal_limits(None) is None

# Learned limits (C6): mean + 3 std per room reading, within the safety range; gas keeps its default.
# This room: dust 2.6 + 3 x 3.5 = 13.0 -> raised to the 15 floor; temperature 31.9; humidity 75.6.
assert main.room_limits(ROOM) == {
    "pm25_threshold": 15.0, "temperature_threshold": 31.9, "humidity_threshold": 75.6, "mq135_threshold": 1000.0,
}, main.room_limits(ROOM)
assert main.room_limits(None) == main.DEFAULT_THRESHOLDS
assert main.room_limits(humid_room)["humidity_threshold"] == 90.0

# Stage 1 tightens from the learned limits as the room gets more unusual, never below mean + 2 std.
level, limits = main.stage1(ROOM, pm25=3.0, temp=30.5, hum=70.0)  # an ordinary reading
assert level == 0.1 and limits == main.room_limits(ROOM), (level, limits)
level, limits = main.stage1(ROOM, pm25=3.0, temp=30.5, hum=73.0)  # humidity 1.7 std above usual
assert level == 0.5 and limits == {
    "pm25_threshold": 15.0, "temperature_threshold": 31.7, "humidity_threshold": 74.6, "mq135_threshold": 900.0,
}, (level, limits)
level, limits = main.stage1(ROOM, pm25=3.0, temp=30.5, hum=75.0)  # 2.7 std above usual
assert level == 0.8 and limits == {
    "pm25_threshold": 15.0, "temperature_threshold": 31.4, "humidity_threshold": 73.6, "mq135_threshold": 800.0,
}, (level, limits)
assert limits == main.respect_room_normal(limits, ROOM), "very unusual = the top of the normal range, not below"

# Gas learned (C6 follow-up): a room usually at 500 +/- 100 ppm gets mean + 3 std = 800 ppm instead of 1000;
# "very unusual" goes to mean + 2 std = 700. A gas reading alone can make the room unusual.
gas_room = dict(ROOM, mq135_level_mean=500.0, mq135_level_std=100.0)
assert main.room_limits(gas_room)["mq135_threshold"] == 800.0, main.room_limits(gas_room)
assert main.room_normal_limits(gas_room)["mq135_threshold"] == 700.0
level, limits = main.stage1(gas_room, pm25=3.0, temp=30.5, hum=70.0, gas=780.0)  # gas 2.8 std above usual
assert level == 0.8 and limits["mq135_threshold"] == 700.0, (level, limits)
level, _ = main.stage1(gas_room, pm25=3.0, temp=30.5, hum=70.0, gas=None)  # no gas reading right now
assert level == 0.1
# A clean room never pushes the gas limit below the 700 ppm floor.
assert main.room_limits(dict(ROOM, mq135_level_mean=430.0, mq135_level_std=50.0))["mq135_threshold"] == 700.0

print("respect_room_normal, room_limits, stage1: all checks passed")
