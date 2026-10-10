"use client";
import { useState, useEffect } from "react";
import LiveMonitor from "@/components/LiveMonitor";
import EventTimeline from "@/components/EventTimeline";
import CommandCenter from "@/components/CommandCenter";
import LogoutButton from "@/components/LogoutButton";
import ThemeToggle from "@/components/ThemeToggle";
import StandByMode from "@/components/StandByMode";
import AiRiskAssessment from "@/components/AiRiskAssessment";
import RoomPicker from "@/components/RoomPicker";
import { RoomsProvider } from "@/lib/rooms";
import { APP_VERSION } from "@/lib/version";

export default function Home() {
  const [sleepMode, setSleepMode] = useState(false);
  const [init, setInit] = useState(false);

  useEffect(() => {
    const saved = localStorage.getItem("respirosync_sleep_mode");
    if (saved === "true") {
      setSleepMode(true);
    }
    setInit(true);
  }, []);

  const handleSleepToggle = (status: boolean) => {
    setSleepMode(status);
    localStorage.setItem("respirosync_sleep_mode", status ? "true" : "false");
  };

  if (!init) {
    return <div className="min-h-screen bg-gray-50 dark:bg-zinc-950"></div>;
  }

  if (sleepMode) {
    return <RoomsProvider><StandByMode onWake={() => handleSleepToggle(false)} /></RoomsProvider>;
  }

  return (
    <RoomsProvider>
    <main className="min-h-screen p-4 md:p-6 lg:p-8 max-w-[1600px] mx-auto transition-all duration-500">

      {/* Header */}
      <header className="relative z-30 bg-white/80 dark:bg-[#12121e]/60 backdrop-blur-xl border border-zinc-200 dark:border-white/5 rounded-3xl px-4 sm:px-6 py-5 mb-6 sm:mb-8 flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-4 shadow-xl">
        <div className="flex items-center gap-4 w-full sm:w-auto">
          <div className="w-12 h-12 shrink-0 drop-shadow-lg">
            <img src="/logo.jpg?v=3" alt="Logo" className="w-full h-full object-contain rounded-xl" />
          </div>
          <div>
            <div className="flex items-center gap-2 mb-1">
              <h1 className="text-xl font-semibold tracking-tight text-zinc-900 dark:text-zinc-100">RespiroSync Dashboard</h1>
            </div>
            <p className="text-sm text-zinc-600 dark:text-zinc-400 font-light">
              Live Environment & Cough Monitor
            </p>
          </div>
        </div>
        {/* Phone: the room picker on its own row, then the four buttons sharing one row.
            From sm up: everything on one line next to the title. */}
        <div className="flex flex-col sm:flex-row sm:items-center gap-3 w-full sm:w-auto">
          <RoomPicker />
          <div className="flex items-center gap-2 sm:gap-3">
            <button
              onClick={() => handleSleepToggle(true)}
              aria-label="Sleep Mode"
              className="flex flex-1 sm:flex-none items-center justify-center gap-2 h-10 bg-indigo-500/10 hover:bg-indigo-500/20 transition-colors border border-indigo-500/20 rounded-full px-4 text-indigo-400 text-xs font-medium"
            >
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
              </svg>
              <span className="hidden sm:inline">Sleep Mode</span>
            </button>
            <a href="/report" aria-label="Activity Log" className="flex flex-1 sm:flex-none items-center justify-center gap-2 h-10 bg-blue-500/10 hover:bg-blue-500/20 transition-colors border border-blue-500/20 rounded-full px-4 text-blue-400 text-xs font-medium">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                <polyline points="14 2 14 8 20 8"></polyline>
                <line x1="16" y1="13" x2="8" y2="13"></line>
                <line x1="16" y1="17" x2="8" y2="17"></line>
                <polyline points="10 9 9 9 8 9"></polyline>
              </svg>
              <span className="hidden sm:inline">Activity Log</span>
            </a>
            <ThemeToggle className="flex-1 sm:flex-none sm:w-10" />
            <LogoutButton className="flex-1 sm:flex-none" />
          </div>
        </div>
      </header>

      {/* Dashboard Grid */}
      <div className="grid grid-cols-1 xl:grid-cols-3 gap-6 items-stretch mb-6">
        <div className="xl:col-span-2">
          <LiveMonitor />
        </div>
        <div className="xl:col-span-1 flex flex-col gap-6">
          <AiRiskAssessment />
          <CommandCenter />
        </div>
      </div>

      {/* Full Width Bottom Section */}
      <div className="w-full">
        <EventTimeline />
      </div>

      {/* Footer */}
      <footer className="mt-8 pt-4 border-t border-zinc-200 dark:border-[#1a1a2e] flex flex-col md:flex-row justify-between items-center text-[11px] text-zinc-600">
        <p>© 2026 RespiroSync</p>
        <p className="mt-1 md:mt-0 font-mono">
          Build {APP_VERSION}
        </p>
      </footer>
    </main>
    </RoomsProvider>
  );
}
