"use client";
import { useEffect, useState, useCallback } from "react";
import { useRooms, withDevice } from "@/lib/rooms";

type CoughEvent = {
  id: number;
  severity: number;
  confidence: number | null;
  recorded_at: string;
  is_verified: boolean | null;
  inhaler_used: boolean;
};

type PaginationData = {
  current_page: number;
  last_page: number;
  total: number;
};

export default function EventTimeline() {
  const { device, deviceId, canMarkCoughs } = useRooms(); // the room on screen; viewers only look
  const [events, setEvents] = useState<CoughEvent[]>([]);
  const [page, setPage] = useState(1);
  const [pagination, setPagination] = useState<PaginationData | null>(null);

  const fetchEvents = useCallback(async () => {
    try {
      const token = localStorage.getItem("auth_token");
      const res = await fetch(withDevice(`/api/cough-events?page=${page}&per_page=10`, deviceId), {
        headers: { 
          "Authorization": `Bearer ${token}`,
          "Accept": "application/json"
        }
      });
      
      if (res.status === 401) {
        window.location.href = "/login";
        return;
      }
      
      if (res.ok) {
        const data = await res.json();
        // Laravel paginator returns data in the 'data' key
        if (data.data) {
          setEvents(data.data);
          setPagination({
            current_page: data.current_page,
            last_page: data.last_page,
            total: data.total
          });
        } else {
          // Fallback just in case backend hasn't updated yet
          setEvents(Array.isArray(data) ? data : []);
        }
      }
    } catch (err) {}
  }, [page, deviceId]);

  // Another room: back to its first page.
  useEffect(() => setPage(1), [deviceId]);

  // isVerified: true = a real cough, false = false alarm, null = undo the review.
  const handleVerify = async (id: number, isVerified: boolean | null, inhalerUsed: boolean) => {
    try {
      const token = localStorage.getItem("auth_token");
      const res = await fetch(`/api/cough-events/${id}/verify`, {
        method: "POST",
        headers: { 
          "Authorization": `Bearer ${token}`,
          "Content-Type": "application/json"
        },
        body: JSON.stringify({
          is_verified: isVerified,
          inhaler_used: inhalerUsed
        })
      });
      if (res.ok) {
        // Update local state immediately
        setEvents(prev => prev.map(ev => 
          ev.id === id ? { ...ev, is_verified: isVerified, inhaler_used: inhalerUsed } : ev
        ));

        // Option 1: Trigger-Based AI Training
        // If a cough is verified, tell AI to instantly re-evaluate thresholds
        if (isVerified) {
          fetch(withDevice("/api/ai/train", deviceId), {
            headers: { "Authorization": `Bearer ${token}` }
          }).catch(e => console.error("AI Training Trigger Failed", e));
        }
      }
    } catch (err) {}
  };

  useEffect(() => {
    fetchEvents();
    const interval = setInterval(fetchEvents, 5000);
    return () => clearInterval(interval);
  }, [fetchEvents]);

  // Backend severity: 1 = logged only, 3 = met the alert rule (cough cluster)
  const getSeverityInfo = (severity: number) => {
    if (severity >= 3) return { label: "Alert", bg: "bg-red-500/10", border: "border-red-500/20", text: "text-red-400", dot: "bg-red-400" };
    return { label: "Logged", bg: "bg-zinc-500/10", border: "border-zinc-500/20", text: "text-zinc-500 dark:text-zinc-400", dot: "bg-zinc-400" };
  };

  return (
    <section className="bg-white dark:bg-[#12121e] border border-zinc-200 dark:border-[#1e1e30] rounded-md p-6 flex-1 flex flex-col">
      <div className="flex items-center justify-between mb-5">
        <div>
          <h2 className="text-base font-semibold text-zinc-900 dark:text-zinc-100">Cough History</h2>
          <p className="text-xs text-zinc-600 dark:text-zinc-400 mt-0.5">Recent cough activity detected in {device ? device.name : "the room"}</p>
        </div>
        <span className="text-xs text-zinc-600 dark:text-zinc-400 bg-zinc-100 dark:bg-[#1a1a2e] px-2.5 py-1 rounded border border-zinc-300 dark:border-[#2a2a40]">
          {pagination ? `${pagination.total} total events` : `${events.length} events`}
        </span>
      </div>

      <div className="flex-1 overflow-x-auto overflow-y-auto border border-zinc-200 dark:border-[#1e1e30] rounded-md">
        <div className="min-w-[600px]">
          {/* Table Header */}
          <div className="grid grid-cols-[1fr_80px_80px_230px] gap-3 px-4 py-2.5 text-[11px] font-medium text-zinc-600 dark:text-zinc-400 uppercase tracking-wider bg-gray-50 dark:bg-[#0e0e18] border-b border-zinc-200 dark:border-[#1e1e30]">
            <span>Timestamp</span>
            <span title="Sound-level heuristic from the Pico, not a probability">Strength</span>
            <span>Status</span>
            <span className="text-right" title="Your review teaches the AI: false alarms are left out of training and the report">Your review</span>
          </div>

          <div className="flex flex-col">
        {events.length > 0 ? (
          events.map((ev) => {
            const sev = getSeverityInfo(ev.severity);
            const isVerified = ev.is_verified;
            return (
              <div
                key={ev.id}
                className="grid grid-cols-[1fr_80px_80px_230px] gap-3 px-4 py-3 border-b border-zinc-200 dark:border-[#1a1a2e] hover:bg-zinc-50 dark:hover:bg-[#16162a] transition-colors items-center"
              >
                <div className="flex items-center gap-3">
                  <span className={`inline-block w-1.5 h-1.5 rounded-full ${sev.dot}`}></span>
                  <div>
                    <span className="text-sm text-zinc-800 dark:text-zinc-200" style={{ fontFamily: "'JetBrains Mono', monospace" }}>
                      {new Date(ev.recorded_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' })}
                    </span>
                    <span className="text-[11px] text-zinc-600 ml-2">
                      {new Date(ev.recorded_at).toLocaleDateString()}
                    </span>
                  </div>
                </div>
                <span className="text-sm text-zinc-700 dark:text-zinc-300" style={{ fontFamily: "'JetBrains Mono', monospace" }}>
                  {ev.confidence !== null && ev.confidence !== undefined ? `${Math.round(ev.confidence * 100)}%` : "n/a"}
                </span>
                <div>
                  <span className={`text-[11px] font-medium px-2 py-0.5 rounded ${sev.bg} ${sev.text} border ${sev.border} inline-block`}>
                    {sev.label}
                  </span>
                </div>
                <div className="flex items-center justify-end gap-1.5">
                  {isVerified === null && !canMarkCoughs ? (
                    <span className="text-[10px] px-2 py-1 rounded border bg-zinc-100 dark:bg-zinc-800/50 text-zinc-500 border-zinc-200 dark:border-zinc-800">Not reviewed</span>
                  ) : isVerified === null ? (
                    <>
                      <button
                        onClick={() => handleVerify(ev.id, true, true)}
                        className="px-2 py-1 bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-500 dark:text-emerald-400 border border-emerald-500/20 rounded text-[10px] transition-colors"
                        title="A real cough, and the emergency (blue) inhaler was used: logs a dose at the cough's time"
                      >
                        Inhaler
                      </button>
                      <button
                        onClick={() => handleVerify(ev.id, true, false)}
                        className="px-2 py-1 bg-blue-500/10 hover:bg-blue-500/20 text-blue-500 dark:text-blue-400 border border-blue-500/20 rounded text-[10px] transition-colors"
                        title="A real cough, no inhaler needed"
                      >
                        Real cough
                      </button>
                      <button
                        onClick={() => handleVerify(ev.id, false, false)}
                        className="px-2 py-1 bg-zinc-100 dark:bg-zinc-800 hover:bg-zinc-200 dark:hover:bg-zinc-700 text-zinc-600 dark:text-zinc-400 border border-zinc-300 dark:border-zinc-700 rounded text-[10px] transition-colors"
                        title="Not a cough (a door, a clap...): left out of the report and the AI's training"
                      >
                        False alarm
                      </button>
                    </>
                  ) : (
                    <>
                      <span className={`text-[10px] px-2 py-1 rounded border ${isVerified ? 'bg-emerald-500/10 text-emerald-500 dark:text-emerald-400 border-emerald-500/20' : 'bg-zinc-100 dark:bg-zinc-800/50 text-zinc-600 dark:text-zinc-400 border-zinc-200 dark:border-zinc-800'}`}>
                        {isVerified ? (ev.inhaler_used ? 'Inhaler used' : 'Real cough') : 'False alarm'}
                      </span>
                      {canMarkCoughs && (
                        <button
                          onClick={() => handleVerify(ev.id, null, false)}
                          className="text-[10px] text-zinc-400 hover:text-blue-500 underline-offset-2 hover:underline"
                          title={ev.inhaler_used ? "Undo the review (also removes the inhaler dose it logged)" : "Undo the review"}
                        >
                          Change
                        </button>
                      )}
                    </>
                  )}
                </div>
              </div>
            );
          })
        ) : (
          <div className="flex flex-col items-center justify-center py-12 text-zinc-600">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" className="mb-3 opacity-40">
              <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
            </svg>
            <p className="text-sm">No events recorded</p>
          </div>
            )}
          </div>
        </div>
      </div>

      {/* Pagination Controls */}
      {pagination && pagination.last_page > 1 && (
        <div className="mt-4 flex items-center justify-between text-sm">
          <span className="text-zinc-600 dark:text-zinc-400 text-xs">
            Page {pagination.current_page} of {pagination.last_page}
          </span>
          <div className="flex items-center gap-2">
            <button
              disabled={page === 1}
              onClick={() => setPage(p => Math.max(1, p - 1))}
              className="px-3 py-1.5 rounded bg-zinc-100 dark:bg-[#1a1a2e] border border-zinc-300 dark:border-[#2a2a40] text-zinc-700 dark:text-zinc-300 hover:bg-zinc-200 dark:hover:bg-[#2a2a40] disabled:opacity-50 disabled:hover:bg-zinc-100 dark:bg-[#1a1a2e] transition-colors"
            >
              Previous
            </button>
            <button
              disabled={page === pagination.last_page}
              onClick={() => setPage(p => Math.min(pagination.last_page, p + 1))}
              className="px-3 py-1.5 rounded bg-zinc-100 dark:bg-[#1a1a2e] border border-zinc-300 dark:border-[#2a2a40] text-zinc-700 dark:text-zinc-300 hover:bg-zinc-200 dark:hover:bg-[#2a2a40] disabled:opacity-50 disabled:hover:bg-zinc-100 dark:bg-[#1a1a2e] transition-colors"
            >
              Next
            </button>
          </div>
        </div>
      )}
    </section>
  );
}
