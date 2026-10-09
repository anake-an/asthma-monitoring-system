// The MQ-135 value is an estimate computed on the ESP32 (see esp32_firmware.ino, readMq135Ppm):
// the sensor resistance is converted with the datasheet curve and calibrated against the
// cleanest air seen since power-on, taken as fresh air at 420 ppm. It reacts to many gases and
// drifts with temperature and humidity, so it is shown as "est.", never as a measured CO2 value.

export const GAS_DEFAULT_LIMIT_PPM = 1000; // common indoor ventilation guide

export const GAS_NOTE =
  "Estimated CO2-equivalent ppm from the MQ-135 gas sensor, self-calibrated to fresh air (420 ppm). " +
  "Approximate: the sensor also reacts to other gases and drifts with temperature and humidity.";
