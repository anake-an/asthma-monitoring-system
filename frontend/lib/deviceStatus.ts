// When a room's device counts as offline, and how long a cough keeps the banner up. Shared by
// the dashboard (LiveMonitor) and StandBy so the two screens always agree.

// The ESP32 sends a reading every 3 s, so none for 20 s means it is offline.
export const OFFLINE_AFTER_SECONDS = 20;

// The newest unreviewed cough shows for this long (and goes once it is reviewed).
export const COUGH_BANNER_HOURS = 2;

// True when the newest reading is too old. Measured against the server's clock (X-Server-Time,
// else the Date header) so a wrong clock on the viewer's computer cannot make the device flicker
// between online and offline.
export function readingIsStale(recordedAt: string, res: Response): boolean {
  const serverNow = Number(res.headers.get("X-Server-Time")) || Date.parse(res.headers.get("Date") ?? "") || Date.now();
  const recordedAtUtc = recordedAt.endsWith("Z") ? recordedAt : recordedAt + "Z";
  return (serverNow - new Date(recordedAtUtc).getTime()) / 1000 > OFFLINE_AFTER_SECONDS;
}

// True when the newest unreviewed cough is recent enough to show.
export function coughIsRecent(recordedAt: string): boolean {
  return (Date.now() - new Date(recordedAt).getTime()) / (1000 * 60 * 60) < COUGH_BANNER_HOURS;
}
