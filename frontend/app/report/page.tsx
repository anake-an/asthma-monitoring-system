"use client";

import { useEffect, useState } from "react";
import Head from "next/head";

type DailyData = {
  date: string;
  count: number;
};

type ReportData = {
  start_date: string;
  end_date: string;
  total_events: number;
  high_severity_events: number;
  inhaler_doses: number;
  rescue_doses: number;
  controller_doses: number;
  daily_breakdown: DailyData[];
  daily_inhalers: { date: string; type: string; count: number }[];
};

export default function ReportPage() {
  const [data, setData] = useState<ReportData | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const fetchReport = async () => {
      try {
        const token = localStorage.getItem("auth_token");
        const res = await fetch("/api/report", {
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
          const report = await res.json();
          setData(report);
        }
      } finally {
        setLoading(false);
      }
    };
    
    fetchReport();
  }, []);

  if (loading) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gray-50 dark:bg-[#09090b] text-zinc-600 dark:text-zinc-400">
        <div className="flex flex-col items-center gap-4">
          <div className="w-8 h-8 border-4 border-blue-500/20 border-t-blue-500 rounded-full animate-spin"></div>
          <p className="text-sm tracking-widest uppercase font-medium">Generating Activity Log...</p>
        </div>
      </div>
    );
  }

  if (!data) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gray-50 dark:bg-[#09090b] text-red-500">
        Failed to load report data. Please check your connection.
      </div>
    );
  }

  // Ensure we have 7 days of data for the chart even if some days are missing
  const last7Days = [];
  for (let i = 6; i >= 0; i--) {
    const d = new Date();
    d.setDate(d.getDate() - i);
    // Local calendar date (the API groups by the server's local date, not UTC)
    const dateStr = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    const foundCough = data.daily_breakdown.find(b => b.date === dateStr);
    const rescueCount = data.daily_inhalers?.find(b => b.date === dateStr && b.type === 'rescue')?.count || 0;
    const controllerCount = data.daily_inhalers?.find(b => b.date === dateStr && b.type === 'controller')?.count || 0;
    
    last7Days.push({
      date: dateStr,
      display_day: d.toLocaleDateString([], { weekday: 'short' }),
      display_date: d.toLocaleDateString([], { month: 'short', day: 'numeric' }),
      cough_count: foundCough ? foundCough.count : 0,
      rescue_count: rescueCount,
      controller_count: controllerCount,
      inhaler_total: rescueCount + controllerCount
    });
  }

  const maxCount = Math.max(...last7Days.flatMap(d => [d.cough_count, d.inhaler_total]), 10); // at least 10 for scale

  return (
    <div className="bg-gray-50 dark:bg-[#09090b] print:bg-white min-h-screen text-zinc-900 dark:text-zinc-100 print:text-black font-sans transition-all selection:bg-blue-500/30 print:min-h-0 print:h-auto print:overflow-visible">
      <Head>
        <title>RespiroSync - Activity Log</title>
      </Head>

      {/* Screen-only Dashboard Navigation */}
      <div className="print:hidden sticky top-0 z-50 bg-gray-50 dark:bg-[#09090b]/80 backdrop-blur-xl border-b border-zinc-200 dark:border-white/5 py-4 px-6 md:px-12 flex justify-between items-center shadow-2xl">
        <div className="flex items-center gap-4">
          <a href="/" className="p-2 bg-zinc-100 dark:bg-white/5 hover:bg-zinc-200 dark:bg-white/10 rounded-full transition-colors group">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="text-zinc-600 dark:text-zinc-400 group-hover:text-zinc-900 dark:text-white transition-colors">
              <line x1="19" y1="12" x2="5" y2="12"></line>
              <polyline points="12 19 5 12 12 5"></polyline>
            </svg>
          </a>
          <h2 className="text-sm font-semibold tracking-wide">Activity Log View</h2>
        </div>
        <div className="flex items-center gap-4">
          <p className="text-xs text-zinc-600 dark:text-zinc-400 hidden sm:block">Press <kbd className="font-mono bg-zinc-200 dark:bg-white/10 px-1.5 py-0.5 rounded text-zinc-700 dark:text-zinc-300">Ctrl + P</kbd> to save as PDF</p>
          <button onClick={() => setTimeout(() => window.print(), 300)} className="px-5 py-2 bg-blue-600 hover:bg-blue-500 text-zinc-900 dark:text-white rounded-full text-xs font-semibold tracking-wide transition-colors shadow-lg shadow-blue-500/20 flex items-center gap-2">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
            Print PDF
          </button>
        </div>
      </div>

      {/* Report Document */}
      <div className="max-w-5xl mx-auto p-6 md:p-12 print:p-0 print:max-w-full">
        
        {/* Document Container */}
        <div className="bg-white dark:bg-[#12121e] print:bg-transparent border border-zinc-200 dark:border-white/5 print:border-none rounded-3xl print:rounded-none p-8 md:p-12 shadow-2xl print:shadow-none relative overflow-hidden">
          
          {/* Decorative Screen Gradients (Hidden on print) */}
          <div className="absolute -top-40 -right-40 w-96 h-96 bg-blue-500/10 blur-[100px] rounded-full print:hidden pointer-events-none"></div>
          
          {/* Header */}
          <div className="flex flex-col md:flex-row justify-between items-start md:items-end border-b border-zinc-300 dark:border-white/10 print:border-black/20 pb-8 mb-10 relative z-10">
            <div>
              <div className="flex items-center gap-3 mb-4">
                <div className="w-8 h-8 rounded-lg bg-blue-500/20 print:bg-blue-100 flex items-center justify-center">
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" className="text-blue-400 print:text-blue-700">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                    <polyline points="14 2 14 8 20 8"></polyline>
                  </svg>
                </div>
                <span className="text-xs font-bold tracking-widest text-blue-400 print:text-blue-700 uppercase">RespiroSync System</span>
              </div>
              <h1 className="text-3xl md:text-5xl font-semibold tracking-tight text-zinc-900 dark:text-white print:text-black mb-2">Weekly Activity Log</h1>
              <p className="text-sm text-zinc-600 dark:text-zinc-400 print:text-zinc-600 font-light">Environment & Cough Monitoring</p>
            </div>
            <div className="mt-6 md:mt-0 text-left md:text-right bg-zinc-100 dark:bg-white/5 print:bg-transparent px-5 py-4 rounded-2xl border border-zinc-200 dark:border-white/5 print:border-none print:p-0">
              <p className="text-sm text-zinc-600 dark:text-zinc-400 print:text-zinc-600 dark:text-zinc-400 uppercase tracking-widest mb-1 font-medium text-[10px]">Account Profile</p>
              <p className="text-lg font-medium text-zinc-900 dark:text-zinc-100 print:text-black">Primary User</p>
              <p className="text-xs text-zinc-600 dark:text-zinc-400 mt-1 font-mono">{data.start_date} — {data.end_date}</p>
            </div>
          </div>

          {/* KPI Summary Cards */}
          <div className="grid grid-cols-1 md:grid-cols-3 gap-5 mb-12 relative z-10 print:break-inside-avoid">
            <div className="bg-zinc-100 dark:bg-white/5 print:bg-gray-50 border border-zinc-300 dark:border-white/10 print:border-gray-200 rounded-2xl p-6 relative overflow-hidden group">
              <div className="absolute top-0 right-0 p-4 opacity-10 group-hover:opacity-20 transition-opacity print:hidden">
                <svg width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>
              </div>
              <p className="text-xs font-semibold text-zinc-600 dark:text-zinc-400 print:text-gray-500 uppercase tracking-widest mb-2">Total Cough Events</p>
              <div className="flex items-end gap-3">
                <p className="text-5xl font-semibold text-zinc-900 dark:text-white print:text-black tracking-tighter">{data.total_events}</p>
                <p className="text-sm text-zinc-600 dark:text-zinc-400 mb-1">recorded</p>
              </div>
            </div>
            
            <div className="bg-red-500/5 print:bg-red-50 border border-red-500/20 print:border-red-200 rounded-2xl p-6 relative overflow-hidden">
              <p className="text-xs font-semibold text-red-400 print:text-red-600 uppercase tracking-widest mb-2">High Severity Flags</p>
              <div className="flex items-end gap-3">
                <p className="text-5xl font-semibold text-red-400 print:text-red-700 tracking-tighter">{data.high_severity_events}</p>
                <p className="text-sm text-red-500/60 mb-1">critical events</p>
              </div>
            </div>

            <div className="bg-blue-500/5 print:bg-blue-50 border border-blue-500/20 print:border-blue-200 rounded-2xl p-6 relative overflow-hidden">
              <p className="text-xs font-semibold text-blue-400 print:text-blue-600 uppercase tracking-widest mb-2">Inhaler Administered</p>
              <div className="flex items-end gap-3">
                <p className="text-5xl font-semibold text-blue-400 print:text-blue-700 tracking-tighter">{data.inhaler_doses}</p>
                <p className="text-sm text-blue-500/60 mb-1">doses used</p>
              </div>
            </div>
          </div>

          {/* Chart Section */}
          <div className="mb-12 bg-zinc-100 dark:bg-white/5 print:bg-transparent border border-zinc-300 dark:border-white/10 print:border-none rounded-2xl p-8 print:p-0 relative z-10 print:break-inside-avoid">
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-8">
              <h2 className="text-lg font-semibold text-zinc-900 dark:text-white print:text-black">7-Day Incident Frequency</h2>
              <div className="flex items-center gap-4 text-xs font-medium">
                <div className="flex items-center gap-2">
                  <div className="w-3 h-3 rounded-full bg-blue-500/80 print:bg-blue-600"></div>
                  <span className="text-zinc-600 dark:text-zinc-400 print:text-gray-600">Cough Events</span>
                </div>
                <div className="flex items-center gap-2">
                  <div className="w-3 h-3 rounded-full bg-red-500/80 print:bg-red-600"></div>
                  <span className="text-zinc-600 dark:text-zinc-400 print:text-gray-600">Emergency Dose</span>
                </div>
                <div className="flex items-center gap-2">
                  <div className="w-3 h-3 rounded-full bg-orange-500/80 print:bg-orange-600"></div>
                  <span className="text-zinc-600 dark:text-zinc-400 print:text-gray-600">Daily Dose</span>
                </div>
              </div>
            </div>
            
            <div className="flex items-end h-64 gap-3 print:gap-1">
              {last7Days.map((day, i) => {
                const coughHeightPct = (day.cough_count / maxCount) * 100;
                const rescueHeightPct = (day.rescue_count / maxCount) * 100;
                const controllerHeightPct = (day.controller_count / maxCount) * 100;
                
                return (
                  <div key={i} className="flex-1 flex flex-col items-center gap-3 group">
                    
                    {/* Dual Bars Container */}
                    <div className="w-full flex justify-center gap-1 sm:gap-2 h-full items-end">
                      
                      {/* Cough Bar */}
                      <div className="w-full max-w-[24px] flex flex-col items-center justify-end h-full">
                        <div className="text-[10px] font-semibold text-blue-400 opacity-0 group-hover:opacity-100 transition-opacity print:opacity-100 mb-1">
                          {day.cough_count > 0 ? day.cough_count : ''}
                        </div>
                        <div className="w-full bg-zinc-100 dark:bg-white/5 print:bg-gray-100 rounded-t-lg relative flex items-end justify-center overflow-hidden h-full">
                          <div 
                            className="w-full bg-blue-500/80 print:bg-blue-600 rounded-t-lg transition-all duration-1000 ease-out"
                            style={{ height: `${coughHeightPct}%`, minHeight: day.cough_count > 0 ? '4px' : '0' }}
                          ></div>
                        </div>
                      </div>

                      {/* Stacked Inhaler Bar */}
                      <div className="w-full max-w-[24px] flex flex-col items-center justify-end h-full">
                        <div className="text-[10px] font-semibold opacity-0 group-hover:opacity-100 transition-opacity print:opacity-100 mb-1 flex flex-col items-center leading-none gap-0.5">
                          {day.rescue_count > 0 && <span className="text-red-400">{day.rescue_count}</span>}
                          {day.controller_count > 0 && <span className="text-orange-400">{day.controller_count}</span>}
                        </div>
                        <div className="w-full bg-zinc-100 dark:bg-white/5 print:bg-gray-100 rounded-t-lg relative flex flex-col justify-end overflow-hidden h-full">
                          
                          {/* Rescue Bar (Top of Stack) */}
                          {day.rescue_count > 0 && (
                            <div 
                              className="w-full bg-red-500/80 print:bg-red-600 transition-all duration-1000 ease-out"
                              style={{ height: `${rescueHeightPct}%`, minHeight: '4px' }}
                            ></div>
                          )}

                          {/* Controller Bar (Bottom of Stack) */}
                          {day.controller_count > 0 && (
                            <div 
                              className="w-full bg-orange-500/80 print:bg-orange-600 transition-all duration-1000 ease-out"
                              style={{ height: `${controllerHeightPct}%`, minHeight: '4px' }}
                            ></div>
                          )}

                        </div>
                      </div>

                    </div>
                    <div className="text-[10px] sm:text-[11px] text-zinc-600 dark:text-zinc-400 print:text-gray-600 font-medium text-center uppercase tracking-wider leading-tight mt-2">
                      <span className="block">{day.display_day}</span>
                      <span className="block">{day.display_date}</span>
                    </div>
                  </div>
                );
              })}
            </div>
          </div>

          {/* AI Analysis / Doctor Notes */}
          <div className="grid grid-cols-1 md:grid-cols-2 gap-8 relative z-10 print:break-inside-avoid">
            
            {/* AI Insights */}
            <div className="bg-blue-500/5 print:bg-white border border-blue-500/20 print:border-gray-200 rounded-2xl p-8">
              <div className="flex items-center gap-2 mb-5">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" className="text-blue-400"><path d="m12 3-1.912 5.813a2 2 0 0 1-1.275 1.275L3 12l5.813 1.912a2 2 0 0 1 1.275 1.275L12 21l1.912-5.813a2 2 0 0 1 1.275-1.275L21 12l-5.813-1.912a2 2 0 0 1-1.275-1.275L12 3Z"></path></svg>
                <h2 className="text-sm font-semibold text-blue-400 print:text-black uppercase tracking-widest">Summary of Recorded Events</h2>
              </div>
              <div className="text-sm leading-relaxed text-zinc-700 dark:text-zinc-300 print:text-gray-700 space-y-4">
                <p>
                  <strong className="text-zinc-900 dark:text-white print:text-black font-semibold block mb-1">Observation:</strong> 
                  During this reporting period, the device recorded {data.total_events} cough-like sounds (a loudness detector, not a diagnosis), of which {data.high_severity_events} met the alert rule (3 within 10 minutes, or 2 with a strong detection).
                </p>
                <p>
                  <strong className="text-zinc-900 dark:text-white print:text-black font-semibold block mb-1">Medication Analysis:</strong> 
                  The emergency blue inhaler was administered {data.rescue_doses} times, and the daily brown inhaler {data.controller_doses} times. 
                  {data.rescue_doses > 3 ? " The high frequency of emergency doses indicates potential asthma instability and suggests a review of the daily controller medication plan." : " This usage pattern falls within an acceptable range for maintenance."}
                </p>
              </div>
            </div>

            {/* Doctor's Notes */}
            <div className="bg-zinc-100 dark:bg-white/5 print:bg-white border border-zinc-300 dark:border-white/10 print:border-gray-200 rounded-2xl p-8">
              <div className="flex items-center gap-2 mb-5">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" className="text-zinc-600 dark:text-zinc-400"><path d="M12 20h9"></path><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path></svg>
                <h2 className="text-sm font-semibold text-zinc-600 dark:text-zinc-400 print:text-black uppercase tracking-widest">Activity Notes</h2>
              </div>
              <div className="space-y-8 mt-6 opacity-30 print:opacity-100">
                <div className="w-full border-b border-zinc-500 print:border-gray-400"></div>
                <div className="w-full border-b border-zinc-500 print:border-gray-400"></div>
                <div className="w-full border-b border-zinc-500 print:border-gray-400"></div>
                <div className="w-full border-b border-zinc-500 print:border-gray-400"></div>
              </div>
            </div>

          </div>

          {/* Footer */}
          <div className="mt-16 pt-8 border-t border-zinc-300 dark:border-white/10 print:border-gray-200 flex flex-col md:flex-row justify-between items-center text-xs text-zinc-600 dark:text-zinc-400 print:text-gray-400 relative z-10">
            <p className="font-mono">Generated on {new Date().toLocaleString()}</p>
            <p className="mt-2 md:mt-0 tracking-wider">RESPIROSYNC SYSTEM v{process.env.NEXT_PUBLIC_VERSION || '5.0.0'}</p>
          </div>

        </div>
      </div>
    </div>
  );
}
