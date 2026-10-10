"use client";
// Which room (device) the dashboard shows. A themed list instead of the browser's own <select>
// (white on Windows, unstyleable): rooms grouped by child, each with its status.
// Keyboard: Enter/Space/ArrowDown opens, arrows move, Enter picks, Escape closes.

import { useEffect, useRef, useState, type KeyboardEvent } from "react";
import { useRooms, type Device } from "@/lib/rooms";
import ChildBadge from "@/components/ChildBadge";

const STATUS: Record<string, { dot: string; text: string }> = {
  online: { dot: "bg-emerald-500", text: "Online" },
  offline: { dot: "bg-amber-500", text: "Offline" },
  pending: { dot: "bg-zinc-400", text: "Waiting for setup" },
};
const statusOf = (d: Device) => STATUS[d.status] ?? STATUS.pending;

export default function RoomPicker() {
  const { devices, device, selectDevice } = useRooms();
  const [open, setOpen] = useState(false);
  const [active, setActive] = useState(0); // keyboard position in `devices`
  const wrapper = useRef<HTMLDivElement>(null);
  const list = useRef<HTMLUListElement>(null);

  // Close on a click or tap outside.
  useEffect(() => {
    if (!open) return;
    const outside = (e: MouseEvent | TouchEvent) => {
      if (wrapper.current && !wrapper.current.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener("mousedown", outside);
    document.addEventListener("touchstart", outside);
    return () => {
      document.removeEventListener("mousedown", outside);
      document.removeEventListener("touchstart", outside);
    };
  }, [open]);

  useEffect(() => {
    if (open) list.current?.focus();
  }, [open]);

  if (!device) return null;
  const several = devices.length > 1;

  const show = () => {
    setActive(Math.max(0, ordered.findIndex(d => d.id === device.id)));
    setOpen(true);
  };
  const pick = (d: Device) => {
    selectDevice(d.id);
    setOpen(false);
  };

  // Rooms grouped by child, in the order the API gives them (most recently seen first).
  const groups: { child: string; patient: Device["patient"]; rooms: Device[] }[] = [];
  for (const d of devices) {
    const child = d.patient ? d.patient.name : "Shared rooms";
    const group = groups.find(g => g.child === child);
    if (group) group.rooms.push(d);
    else groups.push({ child, patient: d.patient, rooms: [d] });
  }
  const childNames = groups.length > 1;
  const ordered = groups.flatMap(g => g.rooms); // the order shown, which the arrow keys follow

  const onListKey = (e: KeyboardEvent) => {
    if (e.key === "Escape") { setOpen(false); return; }
    if (e.key === "ArrowDown") { e.preventDefault(); setActive(i => Math.min(i + 1, ordered.length - 1)); }
    if (e.key === "ArrowUp") { e.preventDefault(); setActive(i => Math.max(i - 1, 0)); }
    if (e.key === "Enter" || e.key === " ") { e.preventDefault(); pick(ordered[active]); }
  };

  return (
    <div ref={wrapper} className="relative w-full sm:w-auto">
      <button
        type="button"
        onClick={() => (open ? setOpen(false) : several && show())}
        onKeyDown={e => { if (several && (e.key === "ArrowDown" || e.key === "Enter" || e.key === " ")) { e.preventDefault(); show(); } }}
        aria-haspopup="listbox"
        aria-expanded={open}
        aria-label={`Room: ${device.name}`}
        className={`flex items-center gap-2 w-full sm:w-auto sm:max-w-[18rem] h-10 bg-zinc-100 dark:bg-white/5 border border-zinc-200 dark:border-white/10 rounded-full pl-3 pr-3 text-xs font-medium text-zinc-700 dark:text-zinc-300 transition-colors ${several ? "hover:bg-zinc-200 dark:hover:bg-white/10 cursor-pointer" : "cursor-default"}`}
      >
        <span className={`w-2 h-2 rounded-full shrink-0 ${statusOf(device).dot}`} title={statusOf(device).text} />
        {/* Room · badge child, on one centred line; long names end in "…" (each keeps a part). */}
        <span className="flex-1 min-w-0 flex items-center gap-1.5 text-left">
          <span className="min-w-0 truncate">{device.name}</span>
          {device.patient && (
            <>
              <span className="shrink-0 text-zinc-400 dark:text-zinc-500" aria-hidden="true">·</span>
              <ChildBadge child={device.patient} size="xs" />
              <span className="min-w-0 truncate text-zinc-500 dark:text-zinc-400">{device.patient.name}</span>
            </>
          )}
        </span>
        {several && (
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" className={`shrink-0 opacity-60 transition-transform ${open ? "rotate-180" : ""}`} aria-hidden="true">
            <polyline points="6 9 12 15 18 9"></polyline>
          </svg>
        )}
      </button>

      {open && (
        <ul
          ref={list}
          role="listbox"
          tabIndex={-1}
          aria-label="Rooms"
          aria-activedescendant={`room-${ordered[active]?.id}`}
          onKeyDown={onListKey}
          className="absolute right-0 left-0 sm:left-auto mt-2 sm:w-80 max-h-[60vh] overflow-y-auto z-50 rounded-2xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-[#16162a] shadow-2xl p-1.5 outline-none animate-in fade-in zoom-in-95 duration-150"
        >
          {groups.map(g => (
            <li key={g.child} role="presentation">
              {childNames && (
                <p className="flex items-center gap-1.5 px-3 pt-2 pb-1 text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                  {g.patient && <ChildBadge child={g.patient} size="xs" />}
                  <span className="min-w-0 truncate">{g.child}</span>
                </p>
              )}
              <ul role="presentation">
                {g.rooms.map(d => {
                  const index = ordered.indexOf(d);
                  const selected = d.id === device.id;
                  const status = statusOf(d);
                  return (
                    <li
                      key={d.id}
                      id={`room-${d.id}`}
                      role="option"
                      aria-selected={selected}
                      onClick={() => pick(d)}
                      onMouseEnter={() => setActive(index)}
                      className={`flex items-center gap-3 px-3 py-2.5 rounded-xl cursor-pointer text-sm ${
                        index === active ? "bg-zinc-100 dark:bg-white/10" : ""
                      } ${selected ? "text-zinc-900 dark:text-white font-medium" : "text-zinc-700 dark:text-zinc-300"}`}
                    >
                      <span className={`w-2 h-2 rounded-full shrink-0 ${status.dot}`} />
                      <span className="flex-1 min-w-0">
                        <span className="block truncate">{d.name}</span>
                        <span className="block truncate text-[11px] text-zinc-500 dark:text-zinc-400">
                          {status.text}{!childNames && d.patient ? ` · ${d.patient.name}` : ""}
                        </span>
                      </span>
                      {selected && (
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" className="shrink-0 text-blue-500" aria-hidden="true">
                          <polyline points="20 6 9 17 4 12"></polyline>
                        </svg>
                      )}
                    </li>
                  );
                })}
              </ul>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
