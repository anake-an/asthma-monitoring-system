# Cough model (Edge Impulse)

The Edge AI module (Raspberry Pi Pico + INMP441) runs a small neural network that tells a cough apart from other sounds. It was trained in Edge Impulse Studio (project "RespiroSync Cough") and exported as an Arduino library, kept in [`hardware/libraries/RespiroSync_Cough_inferencing`](../hardware/libraries/RespiroSync_Cough_inferencing). The firmware that uses it is [`hardware/pico_cough_ai/pico_cough_ai.ino`](../hardware/pico_cough_ai/pico_cough_ai.ino) (Edge AI 3.0.0 and newer).

## Training data

About 1 h 18 min of 16 kHz audio, two labels: **cough** and **noise** (everything else). 80 % for training, 20 % held back for testing.

| Source | What was used | Label |
|---|---|---|
| Edge Impulse public project *cough-monitor-audio* (no. 105885) | 7 min of coughs and room noise, one speaker, 16 kHz | cough, noise |
| Edge Impulse *Keyword spotting* dataset (noise from the Microsoft Scalable Noisy Speech Dataset, words from the Google Speech Commands Dataset) | All "noise" and "unknown" samples, 1 s each, relabelled as noise | noise |
| EPFL *Multimodal dataset for automatic edge-AI cough detection* (Zenodo 7562331, CC BY 4.0) | Outward-facing microphone. 1 s clips starting 150 ms before each annotated cough (up to 4 per recording, all backgrounds); 2 clips per recording of laughing, throat clearing and deep breathing (music, traffic and quiet backgrounds) | cough, noise |

The EPFL recordings are 32-bit; they were converted to 16-bit and cut into clips named `cough.*.wav` / `noise.*.wav` before upload. Noise recorded with "someone else coughing" in the background was left out, so other people's coughs never count as noise.

## Impulse

| Block | Setting |
|---|---|
| Time series | 1000 ms window, 500 ms increase, 16000 Hz, zero-pad on |
| Audio (MFE) | frame length 0.016 s, frame stride 0.032 s, 32 filters, FFT 256, low 0 Hz, noise floor -52 dB |
| Classifier | Edge Impulse default 1D CNN (2 conv/pool layers of 8 and 16 filters, dropout 0.25), 100 cycles, learning rate 0.005, data augmentation (noise high, time masking low), auto-weight classes |
| Target | Raspberry Pi RP2040 (Cortex-M0+ 133 MHz), budget 200 KB RAM, 1 MB flash, 500 ms latency |

The MFE frame settings were made coarser than the defaults (0.02 / 0.01 / 40 / 256) because the default needed 817 ms per window on the RP2040, more than the 500 ms between windows. With these settings it needs 250 ms; a cough (about 300 ms) still spans about 10 frames.

## Results

| | Validation (int8) | Test set (held back) |
|---|---|---|
| Accuracy | 93.3 % | 86.2 % |
| Coughs recognised | 92.6 % | 84.1 % (6.4 % uncertain) |
| Noise recognised | 93.7 % | 87.2 % (6.8 % uncertain) |
| F1 cough / noise | 0.91 / 0.95 | 0.86 / 0.91 |

On the Pico (int8, EON Compiler): **258 ms** per window (MFE 250 ms + network 8 ms), 7.6 KB RAM, 31 KB flash.

## On the device

- Core 1 records the microphone without a break into a 1 s ring buffer (24-bit samples to 16-bit with 4x gain, through a 200 Hz high-pass filter that removes mains hum and the DC offset; Edge AI 3.0.2).
- Core 0 classifies the last second every **0.25 s** (Edge AI 3.2.0; about 140 ms of work each). A cough is reported when **at least 2 of the last 3 windows** reach the room's threshold (0.6 until the ESP32 sends the room's value). One odd window is not enough, and a short cough that one window catches only partly is not averaged away (the earlier rule, the average of 2 windows at 0.5 s, turned single windows of 0.80-0.87 into 0.48-0.55).
- **Level guard** (3.2.0): a window with a clear burst whose loudest 16 ms is outside 2,500-12,000 (where the training coughs were) is scaled into that band (x0.33 to x6) before the model sees it. Distance and microphone placement matter less; windows without a burst are untouched. The loudness level sent with a cough is measured before the guard.
- One report per 1.5 s at most, nothing in the first 3 s after power-on. The report (`COUGH:<level>,<score>`) carries the loudness level 1-4 (loudest 16 ms of the window) and the mean of the two highest of the last three scores.
- A watchdog (8 s) restarts the Pico if its loop hangs or recording stops for 3 s. The ESP32 (3.5.0) reports the module as not connected after 25 minutes without a word from it.
- **Possible coughs** (Edge AI 3.1.0): a score up to 0.15 under the threshold, in a window whose loudest 16 ms is at least 4x its typical level, is sent as `MAYBE:<level>,<score>`, at most one per 20 s. The dashboard shows it for review; it never alerts and counts only once confirmed.

## Learning from the reviews

The device sends no audio, so the reviews cannot retrain the model. They move the room's **cough threshold** instead (`App\Services\CoughSensitivity`, a documented rule): possible coughs confirmed as real make the room more sensitive (−0.05), mostly false alarms less sensitive (+0.05), only confirmed coughs a little more (−0.02). Between 0.40 and 0.85, one change per room per 24 h, each needing new reviews; logged in the Activity Log. Improving the model itself still means recording and retraining (below).

## Model 1.0.4 (Edge AI 3.1.0): retrained with the device's microphone

- Added: 33 coughs (5 rounds of 20 s at different distances and loudness) and 6 minutes of room sound (talking, TV, fan, laughing and throat clearing, footsteps and doors, quiet), recorded through the INMP441 with `record_audio.ps1`. 6 faint bursts (breaths) were dropped by `cut_clips.ps1 -MinPeak`.
- Each cough cut at 6 positions (`-PreRoll 0.05,0.1,0.15,0.22,0.3,0.4`) and, at 3 of them, at 50 % and 70 % volume (`-Gain`): 396 cough clips, 360 noise clips.
- Network: 1D conv 16 filters, dropout, 1D conv 32 filters, dropout (200 cycles). Validation 89.5 % (cough 89.5 %, noise 89.6 %), test set 86 % (cough 82 %, noise 88 %). On the Pico: about 140 ms per window, 15 KB RAM.
- The first model (1.0.1) recognised 3 of 19 test coughs recorded through the INMP441; the public recordings dominated it. Shifted and quieter copies give the device's coughs more weight.
- **Microphone placement matters as much as the model:** in a later check, claps at 30 cm reached only ~930 (loudest 16 ms) where the recorded coughs had reached 20,000+, the sound hole being partly covered. The INMP441's sound hole is on the flat side of its board (opposite the metal can) and must face out through an opening; record training audio with the microphone in its final position.

## Next: train on the real microphone

**First test on the device (2026-10-13):** the INMP441 works (loudest 16 ms ≈ 600-1,000 in a quiet room, 5,000-6,000 for a cough at arm's length; 132 ms per window on the Pico), but the cough score stayed near 0.35 for coughs. Two causes were measured and fixed in Edge AI 3.0.2: **50 Hz mains hum** (with harmonics up to ~800 Hz) about 10x stronger than everything above 1 kHz, now cut by a 200 Hz high-pass filter; and **too much gain**: at 8x a cough reached ~10,000 and scored 0.00, at 4x coughs sit near the training level and score up to 0.6. The rest of the gap (this microphone, the room, the remaining hum harmonics) needs recordings from the device.

Recording with the device itself (Edge AI 3.0.2 or newer, Windows PowerShell, no extra software):

1. Pull the Pico's VSYS wire, plug its USB into the PC, close the Arduino Serial Monitor.
2. Record, from `hardware/tools`:
   - coughs: `.\record_audio.ps1 -Port COM4 -Seconds 20 -Out coughs-01.wav`, coughing every 2-3 s at different distances (0.5-2 m) and loudness; several people if possible. About 10 files.
   - everything else: `.\record_audio.ps1 -Port COM4 -Seconds 60 -Out room-01.wav` with talking (Malay and English), TV, fan, children playing, a quiet room, doors, laughing, throat clearing. About 10 files.
3. Cut: `.\cut_clips.ps1 -In coughs-01.wav -Label cough -OutDir clips` (one clip per cough) and `.\cut_clips.ps1 -In room-01.wav -Label noise -OutDir clips` (every second). Listen to a few cough clips; delete any without a cough. `-PreRoll` and `-Gain` add shifted and quieter copies.
   - **Keep test clips honest:** the cutter writes `clips/info.labels`, where every clip carries its recording (metadata `recording`) and all clips of one recording share a category. With few recordings, choose the test ones yourself: e.g. `-Category testing` for one cough recording and one room recording, `-Category training` for the rest.
4. Edge Impulse, Data acquisition, Upload data: the `clips` folder **including `info.labels`** (it sets the label and the training/test category of each clip). In the Classifier's advanced settings, set **Split train/validation set on metadata key** to `recording`, so no copy of a cough is used to validate itself.
5. Generate features, retrain, Model testing, then deploy as below.

## Updating the model on the devices

1. Edge Impulse: **Deployment**, Arduino library, Quantized (int8), EON Compiler, **Build**.
2. Replace `hardware/libraries/RespiroSync_Cough_inferencing` with the folder from the new zip (leave out `examples/`). Then add `#include <cstdint>` after `#include <memory>` in `src/edge-impulse-sdk/third_party/flatbuffers/include/flatbuffers/fb_base.h`: without it the library does not compile with arduino-pico 6.x (GCC 16), unless Edge Impulse has fixed it by then.
3. Raise `EDGE_AI_VERSION` in `pico_cough_ai.ino`, write the change in `WHATS_NEW.txt` and `hardware/FIRMWARE_HISTORY.md`.
4. Merge to main. CI builds and publishes the Edge AI update; owners install it from the dashboard (Rooms, Edge AI, Update).

For the Arduino IDE (a USB flash), copy the folder `hardware/libraries/RespiroSync_Cough_inferencing` from this repo into `Documents/Arduino/libraries` (it carries the fix above; the zip straight from Edge Impulse does not).
