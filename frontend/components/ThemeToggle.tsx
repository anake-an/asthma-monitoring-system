"use client";
import { useTheme } from "next-themes";
import { Moon, Sun } from "lucide-react";
import { useEffect, useState } from "react";

export default function ThemeToggle({ className = "" }: { className?: string }) {
  const { theme, setTheme } = useTheme();
  const [mounted, setMounted] = useState(false);

  useEffect(() => {
    setMounted(true);
  }, []);

  // Same-size placeholder until mounted, so the header row does not jump.
  if (!mounted) return <div className={`h-10 w-10 ${className}`} />;

  return (
    <button
      onClick={() => setTheme(theme === "dark" ? "light" : "dark")}
      className={`flex items-center justify-center h-10 w-10 rounded-full bg-zinc-200 dark:bg-zinc-800 text-zinc-800 dark:text-zinc-200 hover:bg-zinc-300 dark:hover:bg-zinc-700 transition-colors ${className}`}
      aria-label="Toggle Theme"
    >
      {theme === "dark" ? <Sun size={18} /> : <Moon size={18} />}
    </button>
  );
}
