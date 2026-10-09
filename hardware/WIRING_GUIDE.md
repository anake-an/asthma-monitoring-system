# RespiroSync Hardware Wiring Guide

Wiring for the dual-processor build: an **ESP32** (sensors, display, alarm, Wi-Fi/MQTT) and a **Raspberry Pi Pico** (microphone, cough detection), linked over UART.

> [!WARNING]
> **Hardware liability disclaimer.** This is a reference build. Modules vary by manufacturer: check pin labels against your own parts and their datasheets before powering on. The authors are not responsible for damaged components. Proceed at your own risk.

---

## 0. Parts list (reference build)

| Part | Notes |
|---|---|
| Raspberry Pi Pico | arduino-pico core |
| ESP32 dev board (ESP32-WROOM) | arduino-esp32 core 2.0.x or 3.x |
| INMP441 I2S microphone | 3.3 V only |
| DHT22, bare 4-pin sensor | + 10 kΩ pull-up |
| MQ-135 gas sensor module | 5 V heater |
| Sharp GP2Y1010AU0F dust sensor, bare, 6-wire cable | + 150 Ω (220 Ω ∥ 470 Ω) and 220 µF |
| 16×2 LCD with PCF8574T I2C backpack | 5 V |
| BSS138 4-channel logic level shifter | for the LCD's I2C lines |
| Passive buzzer (2 terminals, `+` / `−`) | + 220 Ω series resistor |
| Red and green LED | + 220 Ω each |
| MB-102 breadboard power module + 9 V DC adapter | 5 V rail for the sensors and LCD |
| Resistors: 10 kΩ ×3, 20 kΩ ×2, 220 Ω ×4, 470 Ω ×1 | |
| Capacitors: 1000 µF ×1, 220 µF ×1 (electrolytic) | long leg = `+`, striped side = `−` |

---

## 1. Power (read first)

```
9 V adapter ──► MB-102 (rail jumper on 5 V) ──► 5 V rail: MQ-135, Sharp sensor, LCD, level shifter HV
USB (PC, or a 5 V phone charger) ──► ESP32 ── its 3V3 pin ──► DHT22, level shifter LV
USB (PC, or a 5 V phone charger) ──► Pico  ── its 3V3 pin ──► INMP441
GND: MB-102 GND, ESP32 GND and Pico GND all tied together
```

Rules:
1. **One common ground.** Every GND (MB-102, ESP32, Pico, every sensor) must be connected, or the UART link and the analog readings fail.
2. **Never connect the MB-102's 5 V to the ESP32's 5 V/VIN pin or to the Pico's VSYS/VBUS.** The boards run from their own USB supply; two 5 V sources fighting each other can damage either. Only the grounds are shared.
3. **MB-102 jumper:** the rail that feeds the sensors must be set to **5 V**, not 3.3 V. Leave the other rail off or unused.
4. **Why split:** the MB-102 uses linear regulators. At 9 V in, every 100 mA it supplies turns into ~0.4 W of heat. The sensors and LCD (~200 mA) are fine; adding the ESP32's Wi-Fi peaks and the Pico overheats it.
5. **Bulk capacitor:** 1000 µF across the MB-102's 5 V rail and GND, close to the module (`+` to 5 V).
6. **Never feed 5 V into an ESP32 or Pico pin.** The two 5 V analog sensors go through 10 kΩ / 20 kΩ dividers (5 V → 3.33 V), and the LCD's I2C lines go through the level shifter.

*(An LM2596 adjustable buck converter can replace the MB-102 only after its output has been set to 5.0 V with a multimeter, with nothing connected. Unadjusted, it can output close to its input voltage, which destroys the Pico and the 5 V sensors.)*

---

## 2. Raspberry Pi Pico (cough detection)

### INMP441 microphone (3.3 V only)
| INMP441 | Pico |
|---|---|
| VDD | **3V3 (OUT)** (pin 36) |
| GND | GND |
| L/R | GND (left channel) |
| SCK | **GP14** |
| WS | **GP15** |
| SD | **GP13** |

> The arduino-pico I2S driver requires WS (LRCLK) on the pin right after SCK (BCLK). Older versions of this guide had WS on GP13 and SD on GP15, which cannot work.

### Pico ↔ ESP32 (UART, both 3.3 V: direct connection)
| Pico | ESP32 |
|---|---|
| GP0 (UART0 TX) | **GPIO 16** (UART2 RX) |
| GP1 (UART0 RX) | **GPIO 17** (UART2 TX) |
| GND | GND |

---

## 3. ESP32 (sensors, display, alarm)

### DHT22, bare 4-pin (pins numbered left to right, grille facing you)
| DHT22 pin | Connect to |
|---|---|
| 1 VCC | ESP32 **3V3** |
| 2 DATA | ESP32 **GPIO 4**, plus a **10 kΩ** from DATA to 3V3 (pull-up) |
| 3 NC | not connected |
| 4 GND | GND |

### MQ-135 module (5 V heater)
| MQ-135 | Connect to |
|---|---|
| VCC | MB-102 **5 V** rail |
| GND | GND |
| AO | **10 kΩ** ➔ ESP32 **GPIO 34**, and **20 kΩ** from GPIO 34 to GND |
| DO | not used |

A new MQ-135 needs about **24-48 hours** of heating before its readings settle.

### Sharp GP2Y1010AU0F dust sensor (bare, 6-wire cable)
Pin 1 is marked on the sensor's connector; cable colours vary between suppliers, so count from the connector, not the colours.

| Sharp pin | Connect to |
|---|---|
| 1 V-LED | **~150 Ω** to the MB-102 **5 V** rail (a **220 Ω and a 470 Ω in parallel** = 149.9 Ω), and a **220 µF** capacitor from pin 1 to GND (`+` on pin 1) |
| 2 LED-GND | GND |
| 3 LED | ESP32 **GPIO 5** (the firmware pulses it; LOW = LED on) |
| 4 S-GND | GND |
| 5 Vo | **10 kΩ** ➔ ESP32 **GPIO 35**, and **20 kΩ** from GPIO 35 to GND |
| 6 Vcc | MB-102 **5 V** rail |

The 150 Ω and 220 µF are required by the datasheet: the capacitor supplies the LED's short current pulse. The firmware multiplies the reading by 1.5 to undo the divider (`DUST_DIVIDER_RATIO`). The GP2Y1014AU0F has the same pinout and wiring.

### LCD 16×2 (PCF8574T backpack) through the BSS138 level shifter
The backpack runs on 5 V and pulls SDA/SCL up to 5 V, so the I2C lines go through the level shifter.

| From | To |
|---|---|
| LCD VCC | MB-102 **5 V** rail |
| LCD GND | GND |
| Level shifter **HV** | MB-102 **5 V** rail |
| Level shifter **LV** | ESP32 **3V3** |
| Level shifter GND (both sides) | GND |
| LCD **SDA** | level shifter **HV1** |
| LCD **SCL** | level shifter **HV2** |
| level shifter **LV1** | ESP32 **GPIO 21** (SDA) |
| level shifter **LV2** | ESP32 **GPIO 22** (SCL) |

The firmware uses I2C address `0x27`. If the screen lights up but stays blank, try `0x3F` in `esp32_firmware.ino`, then adjust the contrast trimmer on the backpack.

### Passive buzzer
| Buzzer | Connect to |
|---|---|
| `+` | **220 Ω** ➔ ESP32 **GPIO 18** |
| `−` | GND |

The firmware drives it with a 2.7 kHz `tone()`. The 220 Ω keeps the pin current around 14 mA even if the buzzer is a low-resistance magnetic type; never connect it to the pin without the resistor. If the buzzer board has more than two pins, pins with the same mark are connected together.

### Status LEDs
| From | Through | To |
|---|---|---|
| ESP32 **GPIO 19** | 220 Ω | **green** LED anode (long leg) |
| ESP32 **GPIO 23** | 220 Ω | **red** LED anode (long leg) |
| both LED cathodes (short leg) | | GND |

Green = connected and normal. Red = alarm, or not connected to the broker.

---

## 4. Pin summary

| ESP32 pin | Used for | | Pico pin | Used for |
|---|---|---|---|---|
| GPIO 4 | DHT22 DATA | | GP0 | UART TX → ESP32 GPIO 16 |
| GPIO 5 | Sharp LED pulse | | GP1 | UART RX ← ESP32 GPIO 17 |
| GPIO 16 | UART RX ← Pico GP0 | | GP13 | INMP441 SD |
| GPIO 17 | UART TX → Pico GP1 | | GP14 | INMP441 SCK |
| GPIO 18 | Buzzer (via 220 Ω) | | GP15 | INMP441 WS |
| GPIO 19 | Green LED | | 3V3 (OUT) | INMP441 VDD |
| GPIO 21 / 22 | LCD SDA / SCL (via level shifter) | | | |
| GPIO 23 | Red LED | | | |
| GPIO 34 | MQ-135 (via divider) | | | |
| GPIO 35 | Sharp Vo (via divider) | | | |

---

## 5. Bring-up order

Test each part on its own before adding the next; it makes faults easy to find.

1. **Pico + INMP441** on USB. Flash `pico_cough_ai.ino` with `DEBUG_LEVELS 1`, open the Serial Plotter, clap or cough, and set `RMS_THRESHOLD` above your room's background level.
2. **ESP32 alone** on USB. Fill `secrets.h`, flash with *Erase All Flash Before Sketch Upload* enabled, pair it from the dashboard, and check that it appears online.
3. Add the **DHT22**, **LEDs**, **buzzer** and **LCD** (with the level shifter and the MB-102 5 V rail). Temperature and humidity should appear on the LCD and the dashboard.
4. Add the **MQ-135** and the **Sharp sensor**. Readings should appear within a few seconds; expect the MQ-135 to drift during its first day.
5. Connect the **Pico ↔ ESP32 UART** and the common GND. A cough should show "Cough Detected!" on the LCD, chirp, and appear in the dashboard's event timeline.
