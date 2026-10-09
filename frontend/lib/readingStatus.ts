// One rule per reading, used by both the bar colour and the card headline, so they always agree:
//   ok    green  comfortably below the limit
//   near  amber  close to the limit (within NEAR_MARGIN below it)
//   over  red    above the limit (the device alarms)

export type Reading = "pm25" | "mq135" | "temperature" | "humidity";
export type Level = "ok" | "near" | "over";

// How close below the limit counts as "near".
const NEAR_MARGIN: Record<Reading, (limit: number) => number> = {
  pm25: limit => limit * 0.2,
  mq135: limit => limit * 0.2,
  temperature: () => 2, // °C
  humidity: () => 5, // percentage points
};

// Where each bar starts (empty): nothing to worry about at or below this. A full bar is the limit.
//   gas 400 ppm: fresh outdoor air; temperature 20 °C and humidity 40 %: a cool, dry room.
const BAR_FLOOR: Record<Reading, number> = { pm25: 0, mq135: 400, temperature: 20, humidity: 40 };

export function readingLevel(name: Reading, value: number, limit: number): Level {
  if (value > limit) return "over";
  if (value >= limit - NEAR_MARGIN[name](limit)) return "near";
  return "ok";
}

/** Bar fill in percent: empty at the floor, full at the limit. */
export function barPercent(name: Reading, value: number, limit: number): number {
  const floor = Math.min(BAR_FLOOR[name], limit / 2); // a very low limit still gets a usable bar
  if (limit <= floor) return value > limit ? 100 : 0;
  return Math.max(0, Math.min(((value - floor) / (limit - floor)) * 100, 100));
}

export const LEVEL_STYLE: Record<Level, { color: string; bg: string; bar: string }> = {
  ok: { color: "text-emerald-400", bg: "bg-emerald-400/10", bar: "bg-emerald-400" },
  near: { color: "text-amber-400", bg: "bg-amber-400/10", bar: "bg-amber-400" },
  over: { color: "text-red-400", bg: "bg-red-400/10", bar: "bg-red-400" },
};
