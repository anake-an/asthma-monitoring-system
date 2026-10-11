# RespiroSync Hardware Wiring Guide

Wiring for the dual-processor build: an **ESP32** (sensors, display, alarm, Wi-Fi/MQTT) and a **Raspberry Pi Pico**, the **Edge AI** module (microphone, cough detection), linked over UART.

> [!WARNING]
> **Hardware liability disclaimer.** This is a reference build. Modules vary by manufacturer: check pin labels against your own parts and their datasheets before powering on. The authors are not responsible for damaged components. Proceed at your own risk.

---

## 0. Parts list (reference build)

| Part | Notes |
|---|---|
| 2 full-size breadboards, joined side by side | one wide board (section 6) |
| Raspberry Pi Pico (Edge AI module) | arduino-pico core, Flash Size "2MB (Sketch: 1MB, FS: 1MB)" |
| ESP32 dev board (ESP32-**WROOM**-32) | arduino-esp32 core 2.0.x or 3.x, Partition Scheme "Minimal SPIFFS (1.9MB APP with OTA)". Not a WROVER board: there GPIO 16/17 belong to the PSRAM |
| USB cable (data, not charge-only) + 5 V 1-2 A USB charger | powers the ESP32 (and the Pico through it) |
| INMP441 I2S microphone | 3.3 V only |
| DHT22, 3-pin module (or bare 4-pin sensor) | the module has its pull-up, a bare sensor needs 10 kΩ |
| MQ-135 gas sensor module | 5 V heater, about 150 mA |
| Sharp GP2Y1010AU0F dust sensor, bare, 6-wire cable | + about 150 Ω (**240 Ω ∥ 470 Ω** = 159 Ω) and **220 µF 16 V** |
| 16×2 LCD with PCF8574T I2C backpack | 5 V |
| BSS138 4-channel logic level shifter | channels 1-2 for the LCD's I2C lines, 3-4 spare |
| Passive buzzer (2 terminals, `+` / `−`) | + **240 Ω** series resistor |
| Red and green LED | + **240 Ω** each |
| MB-102 breadboard power module + 9 V DC adapter (barrel plug, 1 A) | 5 V rail for the sensors and LCD |
| Resistors (from the kit) | see **Resistor and capacitor list** below |
| Capacitors: 1000 µF 25 V ×1, 220 µF 16 V ×1, 220 µF 25 V ×2 (electrolytic) | long leg = `+`, striped side = `−` |

### Resistor and capacitor list (what each one does)

| Part | Used | Where | Why |
|---|---|---|---|
| 10 kΩ | 2 (3 with a bare DHT22) | top of the MQ-135 and Sharp dividers; DHT22 pull-up if bare | 5 V signals → 3.33 V for the ESP32 |
| 20 kΩ | 2 | bottom of the MQ-135 and Sharp dividers (to GND) | same dividers (ratio 1.5, as the firmware expects) |
| 240 Ω | 4 | Sharp LED (with the 470 Ω), green LED, red LED, buzzer | current limits |
| 470 Ω | 1 | in parallel with a 240 Ω for the Sharp LED | 240 ∥ 470 = 159 Ω, close to the datasheet's 150 Ω |
| 100 Ω | 0 (spare) | replaces the green LED's 240 Ω if that LED looks dim | some green LEDs need 3 V |
| 22 Ω | 0 (spare) | optional, in series in the INMP441 SCK wire | only if the microphone crackles on long wires |
| 1000 µF 25 V | 1 | MB-102 5 V rail ↔ GND, next to the module | bulk supply for the MQ-135 heater, LCD and Sharp |
| 220 µF 16 V | 1 | Sharp pin 1 (V-LED) ↔ GND | supplies the LED's pulse (datasheet C1) |
| 220 µF 25 V | 1 | ESP32 3V3 ↔ GND, next to the ESP32 | rides through Wi-Fi current peaks (no brown-out resets) |
| 220 µF 25 V | 1 | Pico VSYS (pin 39) ↔ GND, next to the Pico | keeps Wi-Fi noise out of the microphone's supply |

Left over: 10 kΩ ×7-8, 20 kΩ ×3, 240 Ω ×1, 470 Ω ×4, 100 Ω ×5, 22 Ω ×5.

---

## 1. Power (read first)

Two supplies: a **DC adapter** for the breadboard (sensors and LCD) and **USB** for the ESP32, which also powers the Pico.
```
9 V DC adapter ──► MB-102 barrel jack (rail jumper on 5 V)
                      └──► 5 V rail: MQ-135, Sharp sensor, LCD, level shifter HV

USB (5 V 1-2 A charger, or the PC) ──► ESP32
                      ├──► its 3V3 pin ──► DHT22, level shifter LV
                      └──► its 5V/VIN pin ──► Pico VSYS (pin 39) ──► Pico 3V3 (OUT) ──► INMP441

GND: MB-102 GND, ESP32 GND and Pico GND all tied together
```
(The Pico can also run from its own USB instead of the VSYS wire: section 2.)

Rules:
1. **One common ground.** Every GND (MB-102, ESP32, Pico, every sensor) must be connected, or the UART link and the analog readings fail.
2. **Never connect the MB-102's 5 V to the ESP32's 5 V/VIN pin or to the Pico's VSYS/VBUS.** The boards run from USB; two 5 V sources fighting each other can damage either. Only the grounds are shared. (The ESP32's own 5V/VIN pin may feed the Pico's VSYS: section 2.)
3. **MB-102 jumper:** the rail that feeds the sensors must be set to **5 V**, not 3.3 V. Leave the other rail off or unused. Feed the MB-102 from its **DC barrel jack** (6.5-12 V, 9 V recommended) **or** its own USB socket, never both at once.
4. **Why split:** the MB-102 uses linear regulators. At 9 V in, every 100 mA it supplies turns into ~0.4 W of heat. The sensors and LCD (~200 mA) are fine. Adding the ESP32's Wi-Fi peaks and the Pico would overheat it.
5. **Capacitors:** 1000 µF across the MB-102's 5 V rail and GND, close to the module; 220 µF across the ESP32's 3V3 and GND; 220 µF across the Pico's VSYS (pin 39) and GND. `+` (long leg) always to the supply, `−` (stripe) to GND. A reversed electrolytic heats up and can burst.
6. **Power-on order: DC adapter (MB-102) first, then the ESP32's USB** (or press the ESP32's EN/RST button after switching the MB-102 on). If the ESP32 boots while the LCD is unpowered, the LCD misses its initialisation and shows garbage characters until the ESP32 is reset. The Pico starts with the ESP32.
7. **Never feed 5 V into an ESP32 or Pico pin.** The two 5 V analog sensors go through 10 kΩ / 20 kΩ dividers (5 V → 3.33 V), and the LCD's I2C lines go through the level shifter.

*(An LM2596 adjustable buck converter can replace the MB-102 only after its output has been set to 5.0 V with a multimeter, with nothing connected. Unadjusted, it can output close to its input voltage, which destroys the Pico and the 5 V sensors.)*

---

## 2. Raspberry Pi Pico (Edge AI module: cough detection)

Flash it once over USB with **Tools → Flash Size → "2MB (Sketch: 1MB, FS: 1MB)"**. The file-system half receives its cloud updates. After that, updates come from the dashboard through the ESP32.

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

TX goes to RX, and RX goes to TX. Swapping them is the most common reason no cough arrives.

The link runs at **115200 baud** (ESP32 firmware 3.3.0+ and Edge AI 2.0.0+). Both sides must be on those versions: older builds used 9600 and cannot talk to the new ones.

### Layout: Pico pins and the connections to the ESP32
The Pico's pin numbers count down its left side from the USB end (pin 1 = GP0), then up its right side (pin 21 = GP16 at the bottom, pin 40 = VBUS at the top).
```
                 USB (only to flash it: pull the VSYS wire off first)
              ┌────┴────┐
   GP0  pin 1 │●       ●│ pin 40 VBUS
   GP1  pin 2 │●       ●│ pin 39 VSYS  ◄── ESP32 5V/VIN (its power)
   GND  pin 3 │●       ●│ pin 38 GND
              │   ...   │ pin 36 3V3 (OUT) ──► INMP441 VDD
              │         │
   GP13 pin 17│●        │  ◄── INMP441 SD
   GP14 pin 19│●        │  ──► INMP441 SCK
   GP15 pin 20│●        │  ──► INMP441 WS
              └─────────┘

   Pico pin 1 (GP0) ───────────────► ESP32 GPIO 16 (RX2)
   Pico pin 2 (GP1) ◄─────────────── ESP32 GPIO 17 (TX2)
   Pico pin 3 (GND) ───────────────► ESP32 GND   (shared ground: required)
```
Power the Pico from the ESP32 as below (the reference build), or from its own USB. Never connect it to the MB-102's 5 V.

### Powering the Pico from the ESP32 (one USB cable for both, the reference build)
| ESP32 | Pico |
|---|---|
| **5V** (some boards: **VIN**) | **VSYS** (pin 39: right-hand side, second pin from the USB end) |
| GND | GND (already connected for the UART) |
| | **220 µF 25 V** from VSYS (pin 39, `+`) to GND (pin 38), right next to the Pico |

The Pico needs about 25 mA (about 27 mA with the microphone), which the ESP32's USB supply easily gives. Rules:
- **VSYS only.** Not VBUS (pin 40), and never the Pico's **3V3** (pin 36): that pin is an output.
- **Never both supplies at once.** Pull the VSYS wire off before plugging the Pico's own USB into the PC (to flash it or open its Serial Monitor), so two 5 V supplies never meet. Optional: a 1N5817/1N5819 Schottky diode in the VSYS wire (stripe towards the Pico) makes it safe to keep both connected.
- **A good supply for the ESP32:** a PC port, or a 5 V 1-2 A charger. A weak one makes the ESP32 restart when Wi-Fi peaks.

Wire the INMP441 as in the table above (GP13, GP14, GP15, 3V3 (OUT) on pin 36, GND). No change to the firmware is needed.

---

## 3. ESP32 (sensors, display, alarm)

### DHT22
**3-pin module (sensor on a small board).** Pin order differs between makers, so follow the labels printed on the board. These modules usually include the 10 kΩ pull-up (a small resistor marked `103`). If yours has none, add one from DATA to 3V3.

| Module label | Connect to |
|---|---|
| `+` / `VCC` | ESP32 **3V3** |
| `out` / `DAT` / `S` | ESP32 **GPIO 4** |
| `−` / `GND` | GND |

**Bare 4-pin sensor** (pins numbered left to right, grille facing you):

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

**Estimated ppm:** the firmware converts the reading to the sensor's resistance and applies the MQ-135 datasheet curve, calibrating against the cleanest air seen since power-on (taken as fresh air, 420 ppm CO₂). For the best estimate, power the device up in a well-ventilated room. The value is a CO₂-equivalent estimate: the sensor also reacts to other gases and drifts with temperature and humidity. The module's load resistor cancels out of the calculation, so its value does not matter.

### Sharp GP2Y1010AU0F dust sensor (bare, 6-wire cable)
Pin 1 is marked on the sensor's connector. Cable colours vary between suppliers, so count from the connector, not the colours.

| Sharp pin | Connect to |
|---|---|
| 1 V-LED | **~150 Ω** to the MB-102 **5 V** rail (a **240 Ω and a 470 Ω in parallel** = 159 Ω), and the **220 µF 16 V** capacitor from pin 1 to GND (`+` on pin 1) |
| 2 LED-GND | GND |
| 3 LED | ESP32 **GPIO 5** (the firmware pulses it, LOW = LED on) |
| 4 S-GND | GND |
| 5 Vo | **10 kΩ** ➔ ESP32 **GPIO 35**, and **20 kΩ** from GPIO 35 to GND |
| 6 Vcc | MB-102 **5 V** rail |

The datasheet's circuit uses 150 Ω and 220 µF: the capacitor supplies the LED's short current pulse (0.32 ms every 10 ms). 159 Ω gives about 6 % less LED current, which the firmware's clean-air calibration absorbs. The firmware multiplies the reading by 1.5 to undo the divider (`DUST_DIVIDER_RATIO`). The GP2Y1014AU0F has the same pinout and wiring.

**Wire colours are not standardised** between cable suppliers. Identify pins by position (pin 6, Vcc, is the red wire at one end of the connector). **Calibration:** each sensor has its own clean-air output (0-1.5 V). The firmware learns it as the lowest reading since power-on and converts the rise above it with the datasheet's typical sensitivity (0.5 V per 100 µg/m³). Power the device up in clean air. The Serial Monitor shows the learned baseline. The value is an estimated dust density, not a size-selective PM2.5 measurement.

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
| `+` | **240 Ω** ➔ ESP32 **GPIO 18** |
| `−` | GND |

The firmware drives it with a 2.7 kHz `tone()`. The 240 Ω keeps the pin current around 13 mA even if the buzzer is a low-resistance magnetic type (about 16 Ω). Never connect it to the pin without the resistor. If the buzzer board has more than two pins, pins with the same mark are connected together. A **3-pin buzzer module** (`VCC`, `I/O`, `GND`, with its own transistor) goes VCC → ESP32 3V3, I/O → GPIO 18 directly, GND → GND.

### Status LEDs
| From | Through | To |
|---|---|---|
| ESP32 **GPIO 19** | 240 Ω | **green** LED anode (long leg) |
| ESP32 **GPIO 23** | 240 Ω | **red** LED anode (long leg) |
| both LED cathodes (short leg) | | GND |

Green = connected and normal. Red = alarm, or not connected to the broker. With 240 Ω each LED gets about 5 mA, bright enough for a status light. If the green one is dim (some green LEDs need about 3 V), use a 100 Ω for it instead.

---

## 4. Pin summary

| ESP32 pin | Used for | | Pico pin | Used for |
|---|---|---|---|---|
| GPIO 4 | DHT22 DATA | | GP0 | UART TX → ESP32 GPIO 16 |
| GPIO 5 | Sharp LED pulse | | GP1 | UART RX ← ESP32 GPIO 17 |
| GPIO 0 | BOOT button: wake the LCD, next page | | | |
| GPIO 16 | UART RX ← Pico GP0 | | GP13 | INMP441 SD |
| GPIO 17 | UART TX → Pico GP1 | | GP14 | INMP441 SCK |
| GPIO 18 | Buzzer (via 220 Ω) | | GP15 | INMP441 WS |
| GPIO 19 | Green LED | | 3V3 (OUT), pin 36 | INMP441 VDD |
| GPIO 21 / 22 | LCD SDA / SCL (via level shifter) | | VSYS, pin 39 | power ← ESP32 5V/VIN |
| 5V / VIN | Pico VSYS (power out) | | GND, pin 3 | shared ground with the ESP32 |
| GPIO 23 | Red LED | | | |
| GPIO 34 | MQ-135 (via divider) | | | |
| GPIO 35 | Sharp Vo (via divider) | | | |

---

## 5. Bring-up order

Test each part on its own before adding the next. It makes faults easy to find.

1. **Pico + INMP441** on the Pico's own USB. Copy `hardware/libraries/RespiroSync_Cough_inferencing` into `Documents/Arduino/libraries`, then flash `pico_cough_ai.ino` with **Flash Size "2MB (Sketch: 1MB, FS: 1MB)"**. To check the microphone, set `DEBUG_SCORES 1`, open the Serial Monitor (115200) and cough: the cough score rises towards 1, and stays low for talking. Then set `DEBUG_SCORES` back to 0 and flash again.
2. **ESP32 alone** on USB. Fill `secrets.h`, choose **Partition Scheme "Minimal SPIFFS (1.9MB APP with OTA)"**, flash with *Erase All Flash Before Sketch Upload* enabled (the first time only: it also erases the token and Wi-Fi), pair it from the dashboard, and check that it appears online.
3. Plug in the **DC adapter** (MB-102 on 5 V) and add the **DHT22**, **LEDs**, **buzzer** and **LCD** (with the level shifter). Reset the ESP32 after the MB-102 is on. Temperature and humidity should appear on the LCD and the dashboard.
4. Add the **MQ-135** and the **Sharp sensor**. Readings should appear within a few seconds. Gas shows a 3-minute warm-up countdown, and the MQ-135 drifts during its first day.
5. Unplug the Pico's USB, then connect the **Pico ↔ ESP32 UART** (pins 1, 2, 3) and the **VSYS power wire** (ESP32 5V → Pico pin 39). In the dashboard, Rooms shows "Edge AI 3.0.x". Cough near the microphone (a clap or talking should not count): the Pico's LED blinks, the LCD shows "Cough heard" and chirps, and the cough appears in the dashboard's cough history.

After this, both chips are updated from the dashboard (Account Settings → Rooms): no more USB flashing.

---

## 6. Breadboard layout (two full-size boards joined)

Lay the two boards one above the other and snap them together along their long edges. Board A (top) carries the 5 V side and the Pico; board B (bottom) carries the 3.3 V side. The **ESP32 sits across the join**, one pin row in each board, so both rows keep free holes beside them. (On a single board an ESP32 covers almost every hole.)

```
  BOARD A  ┌────────────────────────────────────────────────────────────────────────┐
  outer    │ ═══ +5V ═══════════════ GND ═══  ◄ MB-102 output 1, jumper on 5 V        │
  rail     │┌──────┐ 1000µF                                    Sharp: 159Ω + 220µF 16V│
           ││MB-102│        INMP441   [ Pico across the channel ] 220µF on VSYS       │
           │└──────┘ DC in                                      5 V wires out to:   │
  inner    │ ─ ─ ─ unused (MB-102 output 2 = OFF) ─ ─ ─ ─          MQ-135, Sharp, LCD │
  rail     └──────────────────────────┬──────────────┬──────────────────────────────┘
                                      │   ESP32      │  ◄ across the join, USB outward
  BOARD B  ┌──────────────────────────┴──────────────┴──────────────────────────────┐
  inner    │ ─ ─ ─ unused ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ │
  rail     │  220µF on 3V3   dividers 10k/20k ×2   level shifter   240Ω+LEDs  240Ω+buzzer│
  outer    │ ═══ +3V3 ═════════════ GND ═══  ◄ wires from the ESP32's 3V3 and GND      │
  rail     └────────────────────────────────────────────────────────────────────────┘
  Off the board, on wires: LCD (4), MQ-135 (3), DHT22 (3), Sharp sensor (6-wire cable)
```

| Rail | Feeds |
|---|---|
| Board A outer **+5V** (MB-102 output 1 on 5 V) | MQ-135 VCC, Sharp pin 6 and its 159 Ω, LCD VCC, level shifter HV |
| Board B outer **+3V3** (ESP32 3V3 pin) | DHT22 VCC, level shifter LV |
| **GND** of both outer rails | everything: join the two GND rails with a jumper wire (and to the ESP32 GND) |
| The two inner rails, at the join | **nothing**: set the MB-102's second output jumper to **OFF**. If the ESP32 needs the room, slide those rail strips off |

Not on any rail: the Pico takes its power from the ESP32's **5V** pin (to VSYS), and the INMP441 takes 3.3 V from the Pico's **3V3 (OUT)** (pin 36).

Checks before powering on:
- **ESP32 pins only in the terminal holes**, never in a rail hole: a rail would connect several of its pins together.
- **Split rails:** many full-size boards break each rail in the middle (a gap in the red/blue line). Bridge the gap with a short jumper, or the far half has no power.
- **Never join a +5V rail and a +3V3 rail.** Only the GND rails are joined.
- **Placement:** keep the **DHT22 at least 10 cm away** from the MQ-135 (its heater runs hot) and from the ESP32, or it reads the board's heat instead of the room. Mount the **Sharp sensor upright** with its hole facing the room and out of direct light. Keep the **microphone away from the buzzer**.

### Power budget

| Supply | Load | Current |
|---|---|---|
| MB-102 5 V (from the 9 V adapter) | MQ-135 heater ~150 mA, LCD with backlight ~25 mA, Sharp ~11 mA (20 mA max), level shifter ~1 mA | **~190 mA**, within the MB-102 (it warms by about 0.8 W at 9 V in) |
| USB 5 V → ESP32 | ESP32 80-120 mA (Wi-Fi peaks ~400 mA), Pico + INMP441 ~27 mA, DHT22 ~1.5 mA, LEDs and buzzer ~25 mA | **~250 mA average, ~500 mA peaks**: use a 5 V 1-2 A charger |
| ESP32 3V3 regulator | ESP32, DHT22, level shifter LV | well under its 1 A |
| Pico 3V3 (OUT) | INMP441 1.4 mA | well under its 300 mA |
