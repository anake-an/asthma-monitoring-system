"use client";
// Account Settings > Notifications on this device: alert pushes (coughs, readings over a limit) for this browser/phone.
// Each device you use has to be switched on separately; email alerts are unaffected.

import { useEffect, useState } from "react";
import { disablePush, enablePush, pushState, type PushState } from "@/lib/push";

const TEXT: Record<PushState, string> = {
  on: "On: alerts (repeated coughing, a reading over its limit for 5 minutes) arrive on this device, even with the dashboard closed.",
  off: "Off on this device. Switch it on to get alerts here, not only by email.",
  blocked: "Blocked in this browser's settings. Allow notifications for this site there, then come back.",
  "needs-home-screen": "On iPhone/iPad: tap Share, then \"Add to Home Screen\", open RespiroSync from the home screen and switch this on there (iOS 16.4 or newer).",
  unsupported: "This browser cannot receive push notifications. Alerts still come by email.",
};

export default function PushSettings({ onToast }: { onToast: (message: string, type: "success" | "error") => void }) {
  const [state, setState] = useState<PushState | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    pushState().then(setState).catch(() => setState("unsupported"));
  }, []);

  const toggle = async () => {
    setBusy(true);
    try {
      const next = state === "on" ? await disablePush() : await enablePush();
      setState(next);
      if (next === "on") onToast("Notifications on for this device", "success");
      else if (next === "blocked") onToast("Notifications are blocked in this browser", "error");
    } catch (e) {
      onToast(e instanceof Error ? e.message : "Could not change notifications", "error");
    } finally {
      setBusy(false);
    }
  };

  const canToggle = state === "on" || state === "off";

  return (
    <div>
      <h4 className="text-sm font-semibold text-zinc-900 dark:text-zinc-100 mb-3 uppercase tracking-wider">Notifications on this device</h4>
      <div className="bg-zinc-50 dark:bg-black/40 border border-zinc-200 dark:border-white/5 rounded-xl p-4 space-y-3">
        <p className="text-xs text-zinc-600 dark:text-zinc-400">{state ? TEXT[state] : "Checking..."}</p>
        {canToggle && (
          <div className="flex gap-2">
            <button
              onClick={toggle}
              disabled={busy}
              className={`flex-1 py-2 rounded-lg text-sm font-medium transition-colors disabled:opacity-50 ${
                state === "on"
                  ? "bg-zinc-200 dark:bg-white/10 hover:bg-zinc-300 dark:hover:bg-white/20 text-zinc-800 dark:text-zinc-200"
                  : "bg-blue-600 hover:bg-blue-500 text-white"
              }`}
            >
              {busy ? "Working..." : state === "on" ? "Turn off" : "Turn on"}
            </button>
          </div>
        )}
        <p className="text-[11px] text-zinc-500 dark:text-zinc-400">Which children alert you is set per child under Children &amp; rooms → Share.</p>
      </div>
    </div>
  );
}
