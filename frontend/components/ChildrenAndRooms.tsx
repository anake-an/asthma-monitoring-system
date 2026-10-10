"use client";
// Account Settings > Children & rooms: the children (patients) of this account and its devices
// (one per room). A room belongs to one child, or is a shared room (coughs there are shown but
// not counted for any child). Only a name is stored for a child (PDPA data minimisation).

import { useState } from "react";
import { authHeaders, useRooms, type Device } from "@/lib/rooms";
import FirmwareUpdate from "@/components/FirmwareUpdate";
import SharePanel from "@/components/SharePanel";
import ThemedSelect from "@/components/ThemedSelect";
import ChildBadge, { BADGE_COLORS, BADGE_EMOJIS, BADGE_STYLES, badgeColor, type BadgeColor } from "@/components/ChildBadge";

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
  const [openShare, setOpenShare] = useState<number | null>(null); // child whose sharing panel is open
  const [openBadge, setOpenBadge] = useState<number | null>(null); // child whose badge picker is open
  const sharedWithMe = patients.filter(p => p.role !== "owner");
  // Pairing is for your own children: shown when you own one, or have no children at all yet
  // (pairing then creates "My child"). Someone who only sees shared children cannot pair for them.
  const canPair = owned.length > 0 || patients.length === 0;
  // New devices go to a child I own: the chosen one, the room on screen's child, or the first.
  const pairTarget = pairFor ?? (owned.some(p => p.id === patientId) ? patientId : owned[0]?.id ?? null);

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
      const data = await send("POST", "/api/devices/generate-token", { patient_id: pairTarget });
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
        <p className="text-[11px] text-zinc-500 dark:text-zinc-400 mb-3">Each child has their own rooms, doses and report. Only a name and a badge are stored, never a photo; tap the badge to change it.</p>
        <div className="bg-zinc-50 dark:bg-black/40 border border-zinc-200 dark:border-white/5 rounded-xl p-4 space-y-3">
          {owned.length === 0 && (
            <p className="text-xs text-zinc-500 dark:text-zinc-400">
              {sharedWithMe.length > 0
                ? "You have no children of your own here; the ones shared with you are below. Add a child only to monitor your own child with your own device."
                : "Add your child to start, or pair a device below (it creates one for you)."}
            </p>
          )}
          {owned.map(p => (
            <div key={p.id}>
              <div className="flex items-center gap-2">
                <button
                  type="button"
                  onClick={() => setOpenBadge(openBadge === p.id ? null : p.id)}
                  aria-label={`Badge of ${p.name}`}
                  aria-expanded={openBadge === p.id}
                  title="Colour and emoji"
                  className="rounded-full focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500"
                >
                  <ChildBadge child={p} size="md" />
                </button>
                {nameField(`p${p.id}`, p.name, `/api/patients/${p.id}`, 60)}
                <span className="text-[11px] text-zinc-500 dark:text-zinc-400 shrink-0 w-16 text-right">
                  {p.devices.length} room{p.devices.length === 1 ? "" : "s"}
                </span>
                <button
                  onClick={() => setOpenShare(openShare === p.id ? null : p.id)}
                  className="text-[10px] text-blue-500 hover:text-blue-600 uppercase tracking-wider font-semibold shrink-0"
                >
                  {openShare === p.id ? "Close" : "Share"}
                </button>
                <button
                  disabled={busy || owned.length < 2}
                  title={owned.length < 2 ? "Keep at least one child: rename instead" : "Delete this child"}
                  onClick={() => onConfirm({
                    title: `Delete ${p.name}`,
                    message: `This permanently deletes ${p.name} and their dose history, for everyone ${p.name} is shared with. Their rooms stay, as shared rooms.`,
                    isDanger: true,
                    onConfirm: () => act(() => send("DELETE", `/api/patients/${p.id}`), "Child deleted"),
                  })}
                  className="text-[10px] text-zinc-400 hover:text-red-500 disabled:opacity-30 disabled:hover:text-zinc-400 uppercase tracking-wider font-semibold shrink-0"
                >
                  Delete
                </button>
              </div>
              {openBadge === p.id && (
                <div className="mt-2 p-3 rounded-lg border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 space-y-3">
                  <div className="flex flex-wrap gap-2" role="radiogroup" aria-label={`Colour for ${p.name}`}>
                    {BADGE_COLORS.map(c => (
                      <button
                        key={c}
                        type="button"
                        role="radio"
                        aria-checked={badgeColor(p) === c}
                        aria-label={c}
                        disabled={busy}
                        onClick={() => act(() => send("PATCH", `/api/patients/${p.id}`, { color: c as BadgeColor }), "Badge saved")}
                        className={`w-7 h-7 rounded-full ${BADGE_STYLES[c].swatch} disabled:opacity-50 ${badgeColor(p) === c ? "ring-2 ring-offset-2 ring-zinc-900 dark:ring-white ring-offset-white dark:ring-offset-zinc-900" : ""}`}
                      />
                    ))}
                  </div>
                  <div className="grid grid-cols-6 sm:grid-cols-9 gap-1.5" role="radiogroup" aria-label={`Emoji for ${p.name}`}>
                    {[null, ...BADGE_EMOJIS].map(e => {
                      const chosen = (p.emoji ?? null) === e;
                      return (
                        <button
                          key={e ?? "initial"}
                          type="button"
                          role="radio"
                          aria-checked={chosen}
                          aria-label={e ?? "First letter of the name"}
                          title={e ? undefined : "First letter of the name"}
                          disabled={busy}
                          onClick={() => act(() => send("PATCH", `/api/patients/${p.id}`, { emoji: e }), "Badge saved")}
                          className={`h-9 rounded-lg flex items-center justify-center text-lg disabled:opacity-50 ${chosen ? "bg-blue-100 dark:bg-blue-500/20 ring-1 ring-blue-500" : "hover:bg-zinc-100 dark:hover:bg-white/10"}`}
                        >
                          {e ?? <ChildBadge child={{ ...p, emoji: null }} size="sm" />}
                        </button>
                      );
                    })}
                  </div>
                </div>
              )}
              {openShare === p.id && <SharePanel patient={p} onToast={onToast} />}
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

      {/* Children other accounts shared with me */}
      {sharedWithMe.length > 0 && (
        <div>
          <h4 className="text-sm font-semibold text-zinc-900 dark:text-zinc-100 mb-1 uppercase tracking-wider">Shared with you</h4>
          <p className="text-[11px] text-zinc-500 dark:text-zinc-400 mb-3">Children another account shared with you. Their rooms appear in the room picker.</p>
          <div className="bg-zinc-50 dark:bg-black/40 border border-zinc-200 dark:border-white/5 rounded-xl p-4 space-y-3">
            {sharedWithMe.map(p => (
              <div key={p.id}>
                <div className="flex items-center gap-2">
                  <ChildBadge child={p} />
                  <span className="flex-1 text-sm text-zinc-800 dark:text-zinc-200 truncate">{p.name}</span>
                  <span className="text-[11px] capitalize text-zinc-500 dark:text-zinc-400 shrink-0">{p.role}</span>
                  <button
                    onClick={() => setOpenShare(openShare === p.id ? null : p.id)}
                    className="text-[10px] text-blue-500 hover:text-blue-600 uppercase tracking-wider font-semibold shrink-0"
                  >
                    {openShare === p.id ? "Close" : "Details"}
                  </button>
                </div>
                {openShare === p.id && <SharePanel patient={p} onToast={onToast} />}
              </div>
            ))}
          </div>
        </div>
      )}

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
                          <ThemedSelect<number | "shared">
                            ariaLabel={`Child of ${d.name}`}
                            className="flex-1"
                            value={d.patient_id ?? "shared"}
                            disabled={busy || !d.can_configure}
                            onChange={v => act(
                              () => send("PATCH", `/api/devices/${d.id}`, { patient_id: v === "shared" ? null : v }),
                              "Room moved",
                            )}
                            options={[
                              ...owned.map(p => ({ value: p.id as number | "shared", label: `${p.name}'s room` })),
                              ...(d.patient && !owned.some(p => p.id === d.patient_id)
                                ? [{ value: d.patient.id as number | "shared", label: `${d.patient.name}'s room`, hint: "Shared with you" }]
                                : []),
                              { value: "shared", label: "Shared room", hint: "Not counted for any child" },
                            ]}
                          />
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
                        <FirmwareUpdate device={d} onToast={onToast} />
                        <FirmwareUpdate device={d} part="pico" onToast={onToast} />
                      </div>
                    );
                  })}
                </div>
              )}
              {canPair ? (
              <div className="flex items-center gap-2">
                {owned.length > 1 && (
                  <ThemedSelect
                    ariaLabel="Pair the new device for"
                    className="shrink-0 min-w-[8rem]"
                    value={pairTarget}
                    onChange={id => setPairFor(id)}
                    disabled={busy}
                    options={owned.map(p => ({ value: p.id, label: `For ${p.name}` }))}
                  />
                )}
                <button
                  onClick={pair}
                  disabled={busy}
                  className="w-full py-2.5 border-2 border-dashed border-zinc-300 dark:border-zinc-700 hover:border-blue-500 dark:hover:border-blue-500 hover:bg-blue-50 dark:hover:bg-blue-500/10 text-zinc-600 dark:text-zinc-400 rounded-lg text-sm font-medium transition-colors disabled:opacity-50"
                >
                  {busy ? "Working..." : "+ Pair New ESP32 Device"}
                </button>
              </div>
              ) : (
                <p className="text-[11px] text-zinc-500 dark:text-zinc-400">
                  Devices are paired by a child&apos;s owner. To use a device of your own for your own child, add that child under Children first.
                </p>
              )}
            </>
          )}
        </div>
      </div>
    </div>
  );
}
