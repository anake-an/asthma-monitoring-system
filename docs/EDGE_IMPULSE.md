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
- Core 0 classifies the last second every 0.5 s. A cough is reported when the cough score, **averaged over two windows in a row**, reaches 0.6. That cuts single-window false alarms.
- One report per 1.5 s at most, nothing in the first 3 s after power-on. The report (`COUGH:<level>,<score>`) carries the loudness level 1-4 (loudest 16 ms of the window) and the averaged cough score.

## Next: train on the real microphone

**First test on the device (2026-10-13):** the INMP441 works (loudest 16 ms ≈ 600-1,000 in a quiet room, 5,000-6,000 for a cough at arm's length; 132 ms per window on the Pico), but the cough score stayed near 0.35 for coughs. Two causes were measured and fixed in Edge AI 3.0.2: **50 Hz mains hum** (with harmonics up to ~800 Hz) about 10x stronger than everything above 1 kHz, now cut by a 200 Hz high-pass filter; and **too much gain**: at 8x a cough reached ~10,000 and scored 0.00, at 4x coughs sit near the training level and score up to 0.6. The rest of the gap (this microphone, the room, the remaining hum harmonics) needs recordings from the device.

Recording with the device itself (Edge AI 3.0.2 or newer, Windows PowerShell, no extra software):

1. Pull the Pico's VSYS wire, plug its USB into the PC, close the Arduino Serial Monitor.
2. Record, from `hardware/tools`:
   - coughs: `.\record_audio.ps1 -Port COM4 -Seconds 20 -Out coughs-01.wav`, coughing every 2-3 s at different distances (0.5-2 m) and loudness; several people if possible. About 10 files.
   - everything else: `.\record_audio.ps1 -Port COM4 -Seconds 60 -Out room-01.wav` with talking (Malay and English), TV, fan, children playing, a quiet room, doors, laughing, throat clearing. About 10 files.
3. Cut: `.\cut_clips.ps1 -In coughs-01.wav -Label cough -OutDir clips` (one clip per cough) and `.\cut_clips.ps1 -In room-01.wav -Label noise -OutDir clips` (every second). Listen to a few cough clips; delete any without a cough.
4. Edge Impulse, Data acquisition, Upload data: the `clips` folder, "Automatically split between training and test", "Infer from filename".
5. Generate features, retrain, Model testing, then deploy as below.

## Updating the model on the devices

1. Edge Impulse: **Deployment**, Arduino library, Quantized (int8), EON Compiler, **Build**.
2. Replace `hardware/libraries/RespiroSync_Cough_inferencing` with the folder from the new zip (leave out `examples/`). Then add `#include <cstdint>` after `#include <memory>` in `src/edge-impulse-sdk/third_party/flatbuffers/include/flatbuffers/fb_base.h`: without it the library does not compile with arduino-pico 6.x (GCC 16), unless Edge Impulse has fixed it by then.
3. Raise `EDGE_AI_VERSION` in `pico_cough_ai.ino`, write the change in `WHATS_NEW.txt` and `hardware/FIRMWARE_HISTORY.md`.
4. Merge to main. CI builds and publishes the Edge AI update; owners install it from the dashboard (Rooms, Edge AI, Update).

For the Arduino IDE (a USB flash), copy the folder `hardware/libraries/RespiroSync_Cough_inferencing` from this repo into `Documents/Arduino/libraries` (it carries the fix above; the zip straight from Edge Impulse does not).
