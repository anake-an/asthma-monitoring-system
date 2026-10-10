// Dust level bands for the PM2.5 estimate, anchored on published 24-hour limits:
//   WHO Air Quality Guidelines 2021: 15 ug/m3 (24-hour)
//   Malaysia Ambient Air Quality Standard (MAAQS) 2020: 35 ug/m3 (24-hour)
// Malaysia's DOE does not publish its PM2.5 API breakpoints, so we use these two limits.
// The standards apply to 24-hour averages from calibrated instruments; the dashboard shows
// an instant reading from an indicative optical sensor, so the band is a guide, not a verdict.
// They pick the word on the dashboard (Clean Air / Fair Air / Dusty) while dust is below the alert limit;
// the colour follows the alert limit (lib/readingStatus.ts), which also decides when the alarm sounds.

export const DUST_WHO_24H = 15;
export const DUST_MAAQS_24H = 35;

export type DustLevel = "low" | "moderate" | "high";

export function dustLevel(pm25: number): DustLevel {
  if (pm25 <= DUST_WHO_24H) return "low";
  if (pm25 <= DUST_MAAQS_24H) return "moderate";
  return "high";
}

export const DUST_BANDS_NOTE =
  `Dust bands: Clean Air ≤ ${DUST_WHO_24H} (WHO 2021), Fair Air ≤ ${DUST_MAAQS_24H}, Dusty > ${DUST_MAAQS_24H} µg/m³ (MAAQS 2020). ` +
  "Those limits are 24-hour averages. This is an instant, indicative reading.";
