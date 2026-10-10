"use client";
// A room's firmware in Account Settings > Rooms: its version, one short status (up to date,
// update available, updating, updated, didn't finish), and the "Update available" dialog,
// written the way device makers word it. The server checks every update again.

import { useEffect, useRef, useState, type ReactNode } from "react";
import { createPortal } from "react-dom";
import { authHeaders, firmwareUpdateFor, useRooms, type Device } from "@/lib/rooms";

export default function FirmwareUpdate({ device, onToast }: {
  device: Device;
  onToast: (message: string, type: "success" | "error") => void;
}) {
  const { latestFirmware, refresh } = useRooms();
  const [open, setOpen] = useState(false);
  const [starting, setStarting] = useState(false);
  const update = firmwareUpdateFor(device, latestFirmware);
  const ota = device.ota ?? { status: null, target: null, error: null };

  // "Ali Bedroom is up to date" once an update this page saw running has finished.
  const lastStatus = useRef(ota.status);
  useEffect(() => {
    if (lastStatus.current === "updating" && ota.status === "updated") onToast(`${device.name} is up to date`, "success");
    lastStatus.current = ota.status;
  }, [ota.status, device.name, onToast]);

  const start = async () => {
    setStarting(true);
    try {
      const res = await fetch(`/api/devices/${device.id}/firmware-update`, {
        method: "POST",
        headers: { ...authHeaders(), "Content-Type": "application/json" },
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(data.message || "The update could not start");
      setOpen(false);
      await refresh();
    } catch (e) {
      onToast(e instanceof Error ? e.message : "Error connecting to server", "error");
    } finally {
      setStarting(false);
    }
  };

  const offline = device.status !== "online";
  const notes = (latestFirmware?.notes ?? "").split("\n").filter(Boolean);
  const version = device.firmware_version ?? "not known";

  let status: ReactNode;
  if (ota.status === "updating") {
    status = (
      <span className="inline-flex items-center gap-1 text-blue-500 dark:text-blue-400">
        <span className="w-3 h-3 rounded-full border-2 border-blue-500/30 border-t-blue-500 animate-spin" aria-hidden="true" />
        Updating... don&apos;t unplug
      </span>
    );
  } else if (ota.status === "failed" && update) {
    status = <span className="text-amber-600 dark:text-amber-400">Update didn&apos;t finish</span>;
  } else if (update) {
    status = <span className="text-blue-500 dark:text-blue-400">● Update available</span>;
  } else if (device.firmware_version) {
    status = <span className="text-emerald-600 dark:text-emerald-400">✓ Up to date</span>;
  } else {
    status = <span>Waiting for the device</span>;
  }

  return (
    <div className="space-y-1">
      <div className="flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] text-zinc-500 dark:text-zinc-400">
        <span title={device.firmware_label ?? undefined}>Firmware {version}</span>
        <span aria-hidden="true">·</span>
        {status}
        {update && (
          <button
            onClick={() => setOpen(true)}
            disabled={offline}
            title={offline ? "The device is offline" : undefined}
            className="ml-auto px-2.5 py-0.5 rounded-md bg-blue-500 hover:bg-blue-600 text-white text-[11px] font-medium disabled:opacity-40"
          >
            {ota.status === "failed" ? "Try again" : "Update"}
          </button>
        )}
      </div>
      {ota.status === "failed" && update && (
        <p className="text-[11px] text-zinc-500 dark:text-zinc-400">Your device is still working normally.</p>
      )}

      {open && latestFirmware && createPortal(
        <div className="fixed inset-0 z-[120] flex items-center justify-center p-4 bg-zinc-900/60 backdrop-blur-sm" role="dialog" aria-modal="true" aria-labelledby={`fw-title-${device.id}`}>
          <div className="bg-white dark:bg-[#12121e] border border-zinc-200 dark:border-white/10 w-full max-w-sm rounded-3xl shadow-2xl p-6">
            <div className="flex items-center gap-3 mb-4">
              <div className="w-10 h-10 rounded-full bg-blue-100 dark:bg-blue-500/20 flex items-center justify-center text-blue-600 dark:text-blue-400 shrink-0">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="M12 19V5"></path><polyline points="5 12 12 5 19 12"></polyline></svg>
              </div>
              <div>
                <h3 id={`fw-title-${device.id}`} className="font-semibold text-lg text-zinc-900 dark:text-white leading-tight">Update available</h3>
                <p className="text-[11px] text-zinc-500 dark:text-zinc-400">Firmware {latestFirmware.label}</p>
              </div>
            </div>

            <p className="text-sm text-zinc-700 dark:text-zinc-300 mb-4">New firmware for <span className="font-medium">{device.name}</span>.</p>

            {notes.length > 0 && (
              <div className="mb-4">
                <p className="text-xs font-semibold text-zinc-900 dark:text-white mb-1.5">What&apos;s new</p>
                <ul className="text-sm text-zinc-600 dark:text-zinc-400 space-y-1 list-disc pl-5">
                  {notes.map(n => <li key={n}>{n}</li>)}
                </ul>
              </div>
            )}

            <p className="text-xs text-zinc-500 dark:text-zinc-400 mb-6">Takes about 1 minute. Keep the device plugged in.</p>

            <div className="flex gap-3 justify-end">
              <button
                onClick={() => setOpen(false)}
                className="px-4 py-2.5 rounded-xl text-sm font-medium bg-zinc-100 dark:bg-white/5 hover:bg-zinc-200 dark:hover:bg-white/10 text-zinc-700 dark:text-zinc-300"
              >
                Later
              </button>
              <button
                onClick={start}
                disabled={starting}
                className="px-4 py-2.5 rounded-xl text-sm font-medium bg-blue-600 hover:bg-blue-700 text-white shadow-sm disabled:opacity-60"
              >
                {starting ? "Starting..." : "Update now"}
              </button>
            </div>
          </div>
        </div>,
        document.body,
      )}
    </div>
  );
}
