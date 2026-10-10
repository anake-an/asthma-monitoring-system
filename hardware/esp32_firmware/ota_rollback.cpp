// Arduino-ESP32 hook (esp32-hal-misc): returning true keeps a newly installed firmware
// "pending verification" instead of accepting it at start-up. The sketch accepts it once it has
// reached the cloud (confirmFirmware); a restart before that boots the previous firmware again.
// In its own .cpp file so the Arduino builder does not generate a C++ prototype for it.
extern "C" bool verifyRollbackLater() {
  return true;
}
