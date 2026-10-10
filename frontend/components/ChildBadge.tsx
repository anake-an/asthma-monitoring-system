// A child's badge: a coloured circle with their emoji, or the first letter of their name.
// Children get a badge instead of a photo (a child's face next to their health data is more
// sensitive than a parent's photo). The lists match the backend's Patient::COLORS and ::EMOJIS.

export type BadgeChild = { id: number; name: string; color?: string | null; emoji?: string | null };

export const BADGE_COLORS = ["blue", "emerald", "amber", "rose", "violet", "cyan", "orange", "pink"] as const;
export type BadgeColor = (typeof BADGE_COLORS)[number];

export const BADGE_EMOJIS = ["🦁", "🐼", "🐰", "🦊", "🐻", "🐱", "🐶", "🐸", "🦄", "🐧", "🐢", "🐝", "⭐", "🌈", "🚀", "⚽"];

// Full class names (Tailwind only keeps classes it finds written out), and the same colours in hex
// for the PDF report, which is drawn without CSS.
export const BADGE_STYLES: Record<BadgeColor, { badge: string; swatch: string; bg: string; fg: string }> = {
  blue: { badge: "bg-blue-100 text-blue-700 dark:bg-blue-500/20 dark:text-blue-300", swatch: "bg-blue-500", bg: "#dbeafe", fg: "#1d4ed8" },
  emerald: { badge: "bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300", swatch: "bg-emerald-500", bg: "#d1fae5", fg: "#047857" },
  amber: { badge: "bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300", swatch: "bg-amber-500", bg: "#fef3c7", fg: "#b45309" },
  rose: { badge: "bg-rose-100 text-rose-700 dark:bg-rose-500/20 dark:text-rose-300", swatch: "bg-rose-500", bg: "#ffe4e6", fg: "#be123c" },
  violet: { badge: "bg-violet-100 text-violet-700 dark:bg-violet-500/20 dark:text-violet-300", swatch: "bg-violet-500", bg: "#ede9fe", fg: "#6d28d9" },
  cyan: { badge: "bg-cyan-100 text-cyan-700 dark:bg-cyan-500/20 dark:text-cyan-300", swatch: "bg-cyan-500", bg: "#cffafe", fg: "#0e7490" },
  orange: { badge: "bg-orange-100 text-orange-700 dark:bg-orange-500/20 dark:text-orange-300", swatch: "bg-orange-500", bg: "#ffedd5", fg: "#c2410c" },
  pink: { badge: "bg-pink-100 text-pink-700 dark:bg-pink-500/20 dark:text-pink-300", swatch: "bg-pink-500", bg: "#fce7f3", fg: "#be185d" },
};

/** The chosen colour, or one picked from the id so that children differ without choosing. */
export function badgeColor(child: BadgeChild): BadgeColor {
  return BADGE_COLORS.includes(child.color as BadgeColor) ? (child.color as BadgeColor) : BADGE_COLORS[child.id % BADGE_COLORS.length];
}

/** The emoji, or the first letter of the name (a whole character, also for non-Latin names). */
export function badgeText(child: BadgeChild): string {
  return child.emoji || Array.from(child.name.trim())[0]?.toUpperCase() || "?";
}

const SIZES = {
  xs: "w-4 h-4 text-[9px]",
  sm: "w-6 h-6 text-xs",
  md: "w-8 h-8 text-sm",
  lg: "w-10 h-10 text-lg",
};

export default function ChildBadge({ child, size = "sm", className = "" }: { child: BadgeChild; size?: keyof typeof SIZES; className?: string }) {
  return (
    <span
      aria-hidden="true"
      className={`inline-flex items-center justify-center shrink-0 rounded-full font-bold leading-none select-none ${SIZES[size]} ${BADGE_STYLES[badgeColor(child)].badge} ${className}`}
    >
      {badgeText(child)}
    </span>
  );
}
