"use client";
// Account Settings > Children & rooms: the children (patients) of this account and its devices
// (one per room). A room belongs to one child, or is a shared room (coughs there are shown but
// not counted for any child). Only a name is stored for a child (PDPA data minimisation).

import { useState } from "react";
import { authHeaders, useRooms, type Device } from "@/lib/rooms";

type Confirm = { title: string; message: string; isDanger: boolean; onConfirm: () => void };

const STATUS: Record<string, { text: string; className: string }> = {
  online: { text: "Online", className: "text-emerald-600 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-500/10" },
  offline: { text: "Offline", className: "text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-500/10" },
  pending: { text: "Pending setup", className: "text-zinc-500 dark:text-zinc-400 bg-zinc-100 dark:bg-white/5" },
};

const input = "w-full bg-white dark:bg-black/40 border border-zinc-200 dark:border-white/10 rounded-lg px-3 py-1.5 text-sm text-zinc-900 dark:text-white focus:outline-none focus:border-blue-500";

function lastSeen(device: Device): string {
  if (!device.last_seen_at) return "never connected";
  const minutes = Math.round((Date.now() - new Date(device.last_seen_at).getTime()) / 60000);
  if (minutes < 1) return "seen just now";
  if (minutes < 60) return `seen ${minutes} min ago`;
  if (minutes < 48 * 60) return `seen ${Math.round(minutes / 60)} h ago`;
  return `seen ${new Date(device.last_seen_at).toLocaleDateString()}`;
}

export default function ChildrenAndRooms({ onToast, onConfirm }: {
  onToast: (message: string, type: "success" | "error") => void;
  onConfirm: (confirm: Confirm) => void;
}) {
  const { devices, patients, patientId, refresh } = useRooms();
  const owned = patients.filter(p => p.role === "owner");
  const [names, setNames] = useState<Record<string, string>>({}); // drafts: "p<id>" / "d<id>"
  const [newChild, setNewChild] = useState("");
  const [pairFor, setPairFor] = useState<number | null>(null);
  const [pairingToken, setPairingToken] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const send = async (method: string, url: string, body?: object) => {
    const res = await fetch(url, {
      method,
      headers: { ...authHeaders(), "Content-Type": "application/json" },
      body: body ? JSON.stringify(body) : undefined,
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.message || "Request failed");
    return data;
  };

  const act = async (work: () => Promise<unknown>, success: string) => {
    setBusy(true);
    try {
      await work();
      await refresh();
      onToast(success, "success");
    } catch (e) {
      onToast(e instanceof Error ? e.message : "Error connecting to server", "error");
    } finally {
      setBusy(false);
    }
  };

  // Rename on blur / Enter when the draft differs from the saved name.
  const rename = (key: string, saved: string, url: string) => {
    const draft = names[key]?.trim();
    setNames(n => { const { [key]: _, ...rest } = n; return rest; });
    if (!draft || draft === saved) return;
    act(() => send("PATCH", url, { name: draft }), "Name saved");
  };

  const nameField = (key: string, saved: string, url: string, max: number, disabled = false) => (
    <input
      className={input}
      value={names[key] ?? saved}
      maxLength={max}
      disabled={disabled || busy}
      onChange={e => setNames(n => ({ ...n, [key]: e.target.value }))}
      onBlur={() => rename(key, saved, url)}
      onKeyDown={e => { if (e.key === "Enter") (e.target as HTMLInputElement).blur(); }}
    />
  );

  const pair = async () => {
    setBusy(true);
    try {
      const data = await send("POST", "/api/devices/generate-token", { patient_id: pairFor ?? patientId });
      setPairingToken(data.token);
    } catch (e) {
      onToast(e instanceof Error ? e.message : "Failed to generate setup token", "error");
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="space-y-6">
      {/* Children */}
      <div>
        <h4 className="text-sm font-semibold text-zinc-900 dark:text-zinc-100 mb-1 uppercase tracking-wider">Children</h4>
        <p className="text-[11px] text-zinc-500 dark:text-zinc-400 mb-3">Each child has their own rooms, doses and report. Only a name is stored.</p>
        <div className="bg-zinc-50 dark:bg-black/40 border border-zinc-200 dark:border-white/5 rounded-xl p-4 space-y-3">
          {owned.map(p => (
            <div key={p.id} className="flex items-center gap-2">
              {nameField(`p${p.id}`, p.name, `/api/patients/${p.id}`, 60)}
              <span className="text-[11px] text-zinc-500 dark:text-zinc-400 shrink-0 w-16 text-right">
                {p.devices.length} room{p.devices.length === 1 ? "" : "s"}
              </span>
              <button
                disabled={busy || owned.length < 2}
                title={owned.length < 2 ? "Keep at least one child: rename instead" : "Delete this child"}
                onClick={() => onConfirm({
                  title: `Delete ${p.name}`,
                  message: `This permanently deletes ${p.name} and their dose history. Their rooms stay, as shared rooms.`,
                  isDanger: true,
                  onConfirm: () => act(() => send("DELETE", `/api/patients/${p.id}`), "Child deleted"),
                })}
                className="text-[10px] text-zinc-400 hover:text-red-500 disabled:opacity-30 disabled:hover:text-zinc-400 uppercase tracking-wider font-semibold shrink-0"
              >
                Delete
              </button>
            </div>
          ))}
          <form
            className="flex items-center gap-2 pt-1"
            onSubmit={e => {
              e.preventDefault();
              const name = newChild.trim();
              if (!name) return;
              act(() => send("POST", "/api/patients", { name }), `${name} added`).then(() => setNewChild(""));
            }}
          >
            <input className={input} placeholder="Add a child (name)" value={newChild} maxLength={60} onChange={e => setNewChild(e.target.value)} disabled={busy} />
            <button type="submit" disabled={busy || !newChild.trim()} className="px-3 py-1.5 bg-blue-500 hover:bg-blue-600 text-white rounded-lg text-sm font-medium disabled:opacity-50 shrink-0">Add</button>
          </form>
        </div>
      </div>

      {/* Rooms */}
      <div>
        <h4 className="text-sm font-semibold text-zinc-900 dark:text-zinc-100 mb-1 uppercase tracking-wider">Rooms</h4>
        <p className="text-[11px] text-zinc-500 dark:text-zinc-400 mb-3">One RespiroSync device per room, each with its own alert limits. A shared room is shown but not counted for any child.</p>
        <div className="bg-zinc-50 dark:bg-black/40 border border-zinc-200 dark:border-white/5 rounded-xl p-4">
          {pairingToken ? (
            <div className="animate-in fade-in slide-in-from-bottom-2 duration-300">
              <div className="bg-blue-50 dark:bg-blue-500/10 border border-blue-200 dark:border-blue-500/20 rounded-xl p-4 mb-4">
                <h5 className="font-semibold text-blue-800 dark:text-blue-300 mb-2">Connect Your Device</h5>
                <ol className="list-decimal pl-4 text-xs text-blue-700 dark:text-blue-400 space-y-2 mb-4">
                  <li>Turn on your RespiroSync physical device.</li>
                  <li>On your phone, connect to the WiFi network called <strong className="font-semibold">RespiroSync-Setup</strong>.</li>
                  <li>When the setup page appears, enter your home WiFi password and the secret token below.</li>
                </ol>
                <div className="bg-white dark:bg-black/40 border border-blue-200 dark:border-blue-500/20 rounded-lg p-3 text-center shadow-inner">
                  <span className="text-3xl font-bold tracking-[0.2em] text-zinc-900 dark:text-white">{pairingToken}</span>
                </div>
              </div>
              <button
                onClick={() => { setPairingToken(null); refresh(); }}
                className="w-full py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition-colors shadow-sm"
              >
                Done
              </button>
            </div>
          ) : (
            <>
              {devices.length === 0 ? (
                <p className="text-sm text-zinc-500 dark:text-zinc-400 text-center py-4 mb-4">No devices connected yet.</p>
              ) : (
                <div className="space-y-3 mb-4">
                  {devices.map(d => {
                    const status = STATUS[d.status] ?? STATUS.pending;
                    return (
                      <div key={d.id} className="p-3 bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-white/10 rounded-lg space-y-2">
                        <div className="flex items-center gap-2">
                          {nameField(`d${d.id}`, d.name, `/api/devices/${d.id}`, 40, !d.can_configure)}
                          <span className={`text-[11px] font-medium px-2 py-1 rounded-full shrink-0 ${status.className}`}>{status.text}</span>
                        </div>
                        <div className="flex items-center gap-2">
                          <select
                            className={input}
                            value={d.patient_id ?? ""}
                            disabled={busy || !d.can_configure}
                            onChange={e => act(
                              () => send("PATCH", `/api/devices/${d.id}`, { patient_id: e.target.value === "" ? null : Number(e.target.value) }),
                              "Room moved",
                            )}
                          >
                            {owned.map(p => <option key={p.id} value={p.id}>{p.name}&apos;s room</option>)}
                            <option value="">Shared room (not counted for a child)</option>
                          </select>
                          {d.can_configure && (
                            <button
                              disabled={busy}
                              onClick={() => onConfirm({
                                title: "Remove Device",
                                message: `Are you sure you want to disconnect and remove "${d.name}"? You will need to pair it again to use it.`,
                                isDanger: true,
                                onConfirm: () => act(() => send("DELETE", `/api/devices/${d.id}`), "Device removed successfully"),
                              })}
                              className="text-[10px] text-zinc-400 hover:text-red-500 uppercase tracking-wider font-semibold shrink-0"
                            >
                              Remove
                            </button>
                          )}
                        </div>
                        <p className="text-[11px] text-zinc-500 dark:text-zinc-400">Token {d.device_token} · {lastSeen(d)}</p>
                      </div>
                    );
                  })}
                </div>
              )}
              <div className="flex items-center gap-2">
                {owned.length > 1 && (
                  <select className={input} value={pairFor ?? patientId ?? ""} onChange={e => setPairFor(Number(e.target.value))} disabled={busy}>
                    {owned.map(p => <option key={p.id} value={p.id}>For {p.name}</option>)}
                  </select>
                )}
                <button
                  onClick={pair}
                  disabled={busy}
                  className="w-full py-2.5 border-2 border-dashed border-zinc-300 dark:border-zinc-700 hover:border-blue-500 dark:hover:border-blue-500 hover:bg-blue-50 dark:hover:bg-blue-500/10 text-zinc-600 dark:text-zinc-400 rounded-lg text-sm font-medium transition-colors disabled:opacity-50"
                >
                  {busy ? "Working..." : "+ Pair New ESP32 Device"}
                </button>
              </div>
            </>
          )}
        </div>
      </div>
    </div>
  );
}
