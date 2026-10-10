// The weekly Activity Log's data (GET /api/report) and what the page and the PDF (lib/reportPdf)
// both derive from it, so the two always show the same numbers.

export type DailyData = {
  date: string;
  count: number;
};

export type LimitChange = {
  limit_name: "pm25" | "temperature" | "humidity" | "mq135";
  old_value: number | null;
  new_value: number;
  source: "ai" | "user" | "rule"; // rule: missed daily dose (15 % lower until a dose is logged)
  reason: string | null;
  created_at: string;
  device_id?: number | null;
  device_name?: string | null; // the room
};

export type ReportData = {
  patient?: { id: number; name: string; color?: string | null; emoji?: string | null }; // the child this report is about
  start_date: string;
  end_date: string;
  total_events: number;
  false_alarms?: number; // marked as false alarm in the cough history: not counted anywhere else
  high_severity_events: number;
  inhaler_doses: number;
  rescue_doses: number;
  controller_doses: number;
  daily_breakdown: DailyData[];
  daily_inhalers: { date: string; type: string; count: number }[];
  limit_changes?: LimitChange[];
};

export const LIMIT_LABELS: Record<LimitChange["limit_name"], { name: string; unit: string }> = {
  pm25: { name: "PM2.5 dust", unit: " µg/m³" },
  temperature: { name: "Temperature", unit: "°C" },
  humidity: { name: "Humidity", unit: "%" },
  mq135: { name: "Gas", unit: " ppm" },
};

export const timesText = (n: number) => (n === 0 ? "0 times" : n === 1 ? "once" : `${n} times`);

export type ReportDay = {
  date: string;
  display_day: string;
  display_date: string;
  cough_count: number;
  rescue_count: number;
  controller_count: number;
  inhaler_total: number;
};

/** The last 7 days, oldest first, with zeros for days without events. */
export function lastSevenDays(data: ReportData): ReportDay[] {
  const days: ReportDay[] = [];
  for (let i = 6; i >= 0; i--) {
    const d = new Date();
    d.setDate(d.getDate() - i);
    // Local calendar date (the API groups by the server's local date, not UTC)
    const dateStr = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    const foundCough = data.daily_breakdown.find(b => b.date === dateStr);
    const rescueCount = data.daily_inhalers?.find(b => b.date === dateStr && b.type === 'rescue')?.count || 0;
    const controllerCount = data.daily_inhalers?.find(b => b.date === dateStr && b.type === 'controller')?.count || 0;

    days.push({
      date: dateStr,
      display_day: d.toLocaleDateString([], { weekday: 'short' }),
      display_date: d.toLocaleDateString([], { month: 'short', day: 'numeric' }),
      cough_count: foundCough ? foundCough.count : 0,
      rescue_count: rescueCount,
      controller_count: controllerCount,
      inhaler_total: rescueCount + controllerCount
    });
  }
  return days;
}

/** The chart's scale: the highest bar, at least 10. */
export const chartMax = (days: ReportDay[]) => Math.max(...days.flatMap(d => [d.cough_count, d.inhaler_total]), 10);

export type LimitUpdate = { created_at: string; device_name: string | null; source: LimitChange["source"]; reason: string | null; changes: LimitChange[] };

/** One line per update: the rows of one AI run or one Smart Alerts save share room, time, source and reason. */
export function limitUpdates(data: ReportData): { updates: LimitUpdate[]; severalRooms: boolean } {
  const limitOrder = Object.keys(LIMIT_LABELS);
  const updates: LimitUpdate[] = [];
  const severalRooms = new Set((data.limit_changes ?? []).map(c => c.device_id)).size > 1;
  for (const c of data.limit_changes ?? []) {
    const last = updates[updates.length - 1];
    if (last && last.created_at === c.created_at && last.device_name === (c.device_name ?? null) && last.source === c.source && last.reason === c.reason) {
      last.changes.push(c);
    } else {
      updates.push({ created_at: c.created_at, device_name: c.device_name ?? null, source: c.source, reason: c.reason, changes: [c] });
    }
  }
  updates.forEach(u => u.changes.sort((a, b) => limitOrder.indexOf(a.limit_name) - limitOrder.indexOf(b.limit_name)));
  return { updates, severalRooms };
}

/** "PM2.5 dust 35 µg/m³ → 32 µg/m³" (or just the new value for the first one). */
export function changeText(c: LimitChange): { name: string; value: string } {
  const label = LIMIT_LABELS[c.limit_name] ?? { name: c.limit_name, unit: "" };
  return { name: label.name, value: `${c.old_value === null ? "" : `${c.old_value}${label.unit} → `}${c.new_value}${label.unit}` };
}

export const whenText = (iso: string) =>
  new Date(iso).toLocaleString([], { month: "short", day: "numeric", hour: "2-digit", minute: "2-digit" });

// The texts of the summary box, shared by the page and the PDF.
export const observationText = (data: ReportData) =>
  `During this reporting period, the device recorded ${data.total_events} cough-like sounds (a loudness detector, not a diagnosis), of which ${data.high_severity_events} met the alert rule (3 within 10 minutes, or 2 with a strong detection).`
  + (data.false_alarms ? ` ${data.false_alarms} other ${data.false_alarms === 1 ? "sound was" : "sounds were"} marked as false alarms and are not counted.` : "");
// Facts only: the app does not judge asthma control or suggest treatment changes.
export const medicationText = (data: ReportData) =>
  `The emergency (blue) inhaler was logged ${timesText(data.rescue_doses)} and the daily (brown) inhaler ${timesText(data.controller_doses)} in these 7 days. This log records usage only and is not a medical assessment; show it to your doctor, especially if usage has changed.`;
export const LIMITS_NOTE =
  "The AI may lower a limit below your own value, never raise it above, and changes each limit at most once a day (by up to 10 %). Rule: when a daily dose is missed, limits are 15 % lower until one is logged.";
