"use client";
import { useEffect, useState } from "react";

export default function InhalerTracker() {
  const [lastUsed, setLastUsed] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [inhalerType, setInhalerType] = useState<'rescue' | 'controller'>('rescue');
  const [showConfirmModal, setShowConfirmModal] = useState(false);
  const [recentRescueCount, setRecentRescueCount] = useState(0);

  const fetchStatus = async () => {
    try {
      const token = localStorage.getItem("auth_token");
      const res = await fetch("/api/inhaler-status", {
        headers: { "Authorization": `Bearer ${token}` }
      });
      if (res.ok) {
        const data = await res.json();
        setLastUsed(data.last_administered_at);
        setRecentRescueCount(data.recent_rescue_count || 0);
      }
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchStatus();
    const interval = setInterval(fetchStatus, 30000);
    return () => clearInterval(interval);
  }, []);

  const handleManualLogClick = () => {
    setShowConfirmModal(true);
  };

  const handleConfirmLog = async () => {
    setShowConfirmModal(false);
    try {
      const token = localStorage.getItem("auth_token");
      await fetch("/api/inhaler-logs", {
        method: "POST",
        headers: { 
          "Authorization": `Bearer ${token}`,
          "Content-Type": "application/json"
        },
        body: JSON.stringify({ type: inhalerType })
      });
      fetchStatus();

      // Option 1: Trigger-Based AI Training
      // Asynchronously trigger the AI to re-evaluate the room conditions immediately
      fetch("/api/ai/train", {
        headers: { "Authorization": `Bearer ${token}` }
      }).catch(e => console.error("AI Training Trigger Failed", e));
    } catch (e) { }
  };

  const getUsageInfo = () => {
    if (!lastUsed) return { warning: false, timeText: "No recent doses", aiNotice: null };

    const last = new Date(lastUsed).getTime();
    const now = new Date().getTime();
    const hoursDiff = (now - last) / (1000 * 60 * 60);

    const h = Math.floor(hoursDiff);
    const m = Math.floor((hoursDiff - h) * 60);
    const timeText = `${h}h ${m}m`;

    // Rule (not AI):
    // If the user takes MULTIPLE rescue doses within 4 hours, trigger warning.
    if (inhalerType === 'rescue' && recentRescueCount >= 2) {
      return {
        warning: true,
        timeText,
        aiNotice: "Warning: 2 or more rescue doses in the last 4 hours. Please monitor symptoms closely."
      };
    }

    return { warning: false, timeText, aiNotice: null };
  };

  const info = getUsageInfo();

  return (
    <div className="mt-4 flex flex-col gap-3">
      {/* Tracker Main Bar */}
      <div className="px-4 py-3 rounded-md border flex flex-col sm:flex-row sm:items-center justify-between gap-3 transition-colors bg-gray-50 dark:bg-[#0e0e18] border-zinc-200 dark:border-[#1e1e30]">
        <div className="flex items-center gap-3">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" className="text-blue-400">
            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
            <line x1="16" y1="2" x2="16" y2="6"></line>
            <line x1="8" y1="2" x2="8" y2="6"></line>
            <line x1="12" y1="10" x2="12" y2="16"></line>
            <line x1="9" y1="13" x2="15" y2="13"></line>
          </svg>
          <div>
            <div className="flex items-center gap-3">
              <p className="text-sm font-medium text-zinc-800 dark:text-zinc-200">
                Medication
              </p>
              <div className="flex bg-zinc-200 dark:bg-black/40 p-0.5 rounded-lg border border-zinc-200 dark:border-white/5">
                <button
                  onClick={() => setInhalerType('rescue')}
                  className={`px-2 py-1 text-[10px] font-medium rounded-md transition-all ${inhalerType === 'rescue' ? 'bg-blue-500/20 text-blue-400 shadow-sm' : 'text-zinc-600 dark:text-zinc-400 hover:text-zinc-700 dark:text-zinc-300'}`}
                >
                  Emergency
                </button>
                <button
                  onClick={() => setInhalerType('controller')}
                  className={`px-2 py-1 text-[10px] font-medium rounded-md transition-all ${inhalerType === 'controller' ? 'bg-orange-500/20 text-orange-400 shadow-sm' : 'text-zinc-600 dark:text-zinc-400 hover:text-zinc-700 dark:text-zinc-300'}`}
                >
                  Daily
                </button>
              </div>
            </div>
            <p className="text-[11px] text-zinc-600 dark:text-zinc-400 mt-0.5">
              Time since last dose: {info.timeText}
              {lastUsed && !loading && <span className="ml-1 text-zinc-600 dark:text-zinc-400 font-mono">(Last: {new Date(lastUsed).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })})</span>}
            </p>
          </div>
        </div>
        <button
          onClick={handleManualLogClick}
          className="w-full sm:w-auto px-3 py-1.5 bg-blue-600/10 hover:bg-blue-600/20 text-blue-400 border border-blue-500/20 text-[11px] font-medium rounded transition-colors"
        >
          Log Dose
        </button>
      </div>

      {/* AI Insight Box (Shows up dynamically based on usage) */}
      {info.warning && (
        <div className="px-4 py-3 rounded-md bg-yellow-500/5 border border-yellow-500/20 flex items-start gap-3 animate-in fade-in slide-in-from-top-2 duration-300">
          <div className="mt-0.5">
            <span className="relative flex h-3 w-3">
              <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-yellow-400 opacity-75"></span>
              <span className="relative inline-flex rounded-full h-3 w-3 bg-yellow-500"></span>
            </span>
          </div>
          <div>
            <p className="text-[12px] font-medium text-yellow-500 mb-1">{info.aiNotice}</p>
            <p className="text-[11px] text-zinc-600 dark:text-zinc-400">
              <span className="text-yellow-500/70 font-medium">Suggestion:</span> Frequent use of the rescue inhaler may indicate worsening asthma control. Consider consulting your physician.
            </p>
          </div>
        </div>
      )}

      {/* Custom Confirmation Modal */}
      {showConfirmModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-zinc-800/40 dark:bg-black/60 backdrop-blur-sm animate-in fade-in duration-200">
          <div className="bg-white dark:bg-[#12121e] border border-zinc-200 dark:border-[#1e1e30] rounded-xl shadow-2xl p-6 max-w-sm w-full animate-in zoom-in-95 duration-200">
            <h3 className="text-lg font-semibold text-zinc-900 dark:text-zinc-100 mb-2">Confirm Medication</h3>
            <p className="text-sm text-zinc-600 dark:text-zinc-400 mb-6">
              Are you sure you want to log an <span className="font-medium text-zinc-800 dark:text-zinc-200">{inhalerType === 'rescue' ? 'Emergency (Blue)' : 'Daily (Brown)'}</span> inhaler dose for right now?
            </p>
            <div className="flex gap-3 justify-end">
              <button
                onClick={() => setShowConfirmModal(false)}
                className="px-4 py-2 text-sm font-medium text-zinc-600 dark:text-zinc-400 hover:text-zinc-800 dark:text-zinc-200 transition-colors"
              >
                Cancel
              </button>
              <button
                onClick={handleConfirmLog}
                className="px-4 py-2 text-sm font-medium bg-blue-600 hover:bg-blue-500 text-zinc-900 dark:text-white rounded-md transition-colors"
              >
                Yes, Log Dose
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
