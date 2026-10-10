"use client";
// A dropdown in the dashboard's style (light and dark) instead of the browser's own <select>,
// whose list is white on Windows whatever the theme. The list is positioned on screen (fixed), so
// it is not cut off inside the scrolling Account Settings window; it opens upward near the bottom.
// Keyboard: Enter/Space/ArrowDown opens, arrows move, Enter picks, Escape closes.

import { useEffect, useId, useLayoutEffect, useRef, useState, type KeyboardEvent } from "react";
import { createPortal } from "react-dom";

export type SelectOption<T extends string | number> = { value: T; label: string; hint?: string };

export default function ThemedSelect<T extends string | number>({
  value, options, onChange, disabled = false, ariaLabel, title, className = "",
}: {
  value: T | null;
  options: SelectOption<T>[];
  onChange: (value: T) => void;
  disabled?: boolean;
  ariaLabel: string;
  title?: string;
  className?: string;
}) {
  const [open, setOpen] = useState(false);
  const [active, setActive] = useState(0);
  const [place, setPlace] = useState<{ top?: number; bottom?: number; left: number; width: number }>({ left: 0, width: 0 });
  const button = useRef<HTMLButtonElement>(null);
  const list = useRef<HTMLUListElement>(null);
  const current = options.find(o => o.value === value);
  const idPrefix = useId(); // option ids must be unique when several selects are on screen

  // Place the list under the button (or above it when there is no room below).
  useLayoutEffect(() => {
    if (!open || !button.current) return;
    const r = button.current.getBoundingClientRect();
    const width = Math.max(r.width, 160);
    const left = Math.min(r.left, window.innerWidth - width - 8);
    const below = window.innerHeight - r.bottom;
    setPlace(below < 220 && r.top > below ? { bottom: window.innerHeight - r.top + 4, left, width } : { top: r.bottom + 4, left, width });
    list.current?.focus();
  }, [open]);

  // Close on an outside click/tap, and when the page or a container scrolls or resizes.
  useEffect(() => {
    if (!open) return;
    const outside = (e: Event) => {
      const t = e.target as Node;
      if (!button.current?.contains(t) && !list.current?.contains(t)) setOpen(false);
    };
    const close = (e: Event) => { if (!list.current?.contains(e.target as Node)) setOpen(false); };
    document.addEventListener("mousedown", outside);
    document.addEventListener("touchstart", outside);
    window.addEventListener("scroll", close, true);
    window.addEventListener("resize", close);
    return () => {
      document.removeEventListener("mousedown", outside);
      document.removeEventListener("touchstart", outside);
      window.removeEventListener("scroll", close, true);
      window.removeEventListener("resize", close);
    };
  }, [open]);

  const show = () => {
    if (disabled || options.length === 0) return;
    setActive(Math.max(0, options.findIndex(o => o.value === value)));
    setOpen(true);
  };
  const pick = (o: SelectOption<T>) => {
    setOpen(false);
    button.current?.focus();
    if (o.value !== value) onChange(o.value);
  };
  const onListKey = (e: KeyboardEvent) => {
    if (e.key === "Escape" || e.key === "Tab") { setOpen(false); button.current?.focus(); return; }
    if (e.key === "ArrowDown") { e.preventDefault(); setActive(i => Math.min(i + 1, options.length - 1)); }
    if (e.key === "ArrowUp") { e.preventDefault(); setActive(i => Math.max(i - 1, 0)); }
    if (e.key === "Enter" || e.key === " ") { e.preventDefault(); pick(options[active]); }
  };

  return (
    <>
      <button
        ref={button}
        type="button"
        disabled={disabled}
        title={title}
        aria-label={ariaLabel}
        aria-haspopup="listbox"
        aria-expanded={open}
        onClick={() => (open ? setOpen(false) : show())}
        onKeyDown={e => { if (!open && (e.key === "ArrowDown" || e.key === "Enter" || e.key === " ")) { e.preventDefault(); show(); } }}
        className={`flex items-center justify-between gap-2 min-w-0 bg-white dark:bg-black/40 border border-zinc-200 dark:border-white/10 rounded-lg px-3 py-1.5 text-sm text-zinc-900 dark:text-white text-left focus:outline-none focus:border-blue-500 disabled:opacity-50 disabled:cursor-default ${className}`}
      >
        <span className="truncate">{current?.label ?? "Choose…"}</span>
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" className={`shrink-0 opacity-60 transition-transform ${open ? "rotate-180" : ""}`} aria-hidden="true">
          <polyline points="6 9 12 15 18 9"></polyline>
        </svg>
      </button>

      {/* Rendered into <body>: inside the blurred Account Settings window a fixed list would be
          positioned relative to that window, not the screen. */}
      {open && createPortal(
        <ul
          ref={list}
          role="listbox"
          tabIndex={-1}
          aria-label={ariaLabel}
          aria-activedescendant={`${idPrefix}-${active}`}
          onKeyDown={onListKey}
          style={{ position: "fixed", top: place.top, bottom: place.bottom, left: place.left, width: place.width }}
          className="z-[200] max-h-64 overflow-y-auto rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-[#16162a] shadow-2xl p-1 outline-none print:hidden"
        >
          {options.map((o, i) => {
            const selected = o.value === value;
            return (
              <li
                key={String(o.value)}
                id={`${idPrefix}-${i}`}
                role="option"
                aria-selected={selected}
                onClick={() => pick(o)}
                onMouseEnter={() => setActive(i)}
                className={`flex items-center gap-2 px-3 py-2 rounded-lg cursor-pointer text-sm ${i === active ? "bg-zinc-100 dark:bg-white/10" : ""} ${selected ? "text-zinc-900 dark:text-white font-medium" : "text-zinc-700 dark:text-zinc-300"}`}
              >
                <span className="flex-1 min-w-0">
                  <span className="block truncate">{o.label}</span>
                  {o.hint && <span className="block text-[11px] text-zinc-500 dark:text-zinc-400">{o.hint}</span>}
                </span>
                {selected && (
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" className="shrink-0 text-blue-500" aria-hidden="true">
                    <polyline points="20 6 9 17 4 12"></polyline>
                  </svg>
                )}
              </li>
            );
          })}
        </ul>,
        document.body,
      )}
    </>
  );
}
