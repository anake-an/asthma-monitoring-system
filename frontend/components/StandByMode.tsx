"use client";
import { useEffect, useState } from "react";

type Telemetry = {
  pm25_level: number;
  temperature: number | null;
  humidity: number | null;
  recorded_at: string;
};

export default function StandByMode({ onWake }: { onWake: () => void }) {
  const [time, setTime] = useState<Date | null>(null);
  const [data, setData] = useState<Telemetry | null>(null);
  const [config, setConfig] = useState({ temperature_threshold: 35, pm25_threshold: 35, humidity_threshold: 60 });
  const [coughDetected, setCoughDetected] = useState(false);
  const [isPortrait, setIsPortrait] = useState(false);
  const [dims, setDims] = useState({ w: 0, h: 0 });

  useEffect(() => {
    const checkOrientation = () => {
      setIsPortrait(window.innerHeight > window.innerWidth);
      setDims({ w: window.innerWidth, h: window.innerHeight });
    };
    
    checkOrientation();
    window.addEventListener('resize', checkOrientation);
    window.addEventListener('orientationchange', checkOrientation);
    
    try {
      if (screen.orientation && 'lock' in screen.orientation) {
        (screen.orientation as any).lock('landscape').catch(() => {});
      }
    } catch (e) {}

    return () => {
      window.removeEventListener('resize', checkOrientation);
      window.removeEventListener('orientationchange', checkOrientation);
      try {
        if (screen.orientation && 'unlock' in screen.orientation) {
          screen.orientation.unlock();
        }
      } catch (e) {}
    };
  }, []);

  useEffect(() => {
    // Clock initialization and interval
    setTime(new Date());
    const clockInterval = setInterval(() => setTime(new Date()), 1000);

    // Data fetching logic
    const fetchData = async () => {
      try {
        const token = localStorage.getItem("auth_token");
        const res = await fetch("/api/telemetry", {
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
          const logs = await res.json();
          if (logs.length > 0) setData(logs[0]);
        }

        const confRes = await fetch("/api/config", {
          headers: { "Authorization": `Bearer ${token}`, "Accept": "application/json" }
        });
        if (confRes.ok) {
          const confData = await confRes.json();
          setConfig({ 
            temperature_threshold: Math.round(confData.temperature_threshold || 35),
            pm25_threshold: Math.round(confData.pm25_threshold || 35),
            humidity_threshold: Math.round(confData.humidity_threshold || 60)
          });
        }

        const coughRes = await fetch("/api/cough-events?per_page=1", {
          headers: { "Authorization": `Bearer ${token}`, "Accept": "application/json" }
        });
        if (coughRes.ok) {
          const coughData = await coughRes.json();
          if (coughData.data && coughData.data.length > 0) {
            const diff = (new Date().getTime() - new Date(coughData.data[0].recorded_at).getTime()) / (1000 * 60 * 60);
            setCoughDetected(diff < 12);
          }
        }
      } catch (err) {}
    };

    fetchData();
    const dataInterval = setInterval(fetchData, 3000);

    return () => {
      clearInterval(clockInterval);
      clearInterval(dataInterval);
    };
  }, []);

  const formatTime = (date: Date) => {
    return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: false });
  };

  const formatDate = (date: Date) => {
    return date.toLocaleDateString([], { weekday: 'long', month: 'long', day: 'numeric' });
  };

  const getAqiInfo = (pm25: number) => {
    if (pm25 <= config.pm25_threshold * 0.3) return { color: "text-emerald-400", bg: "bg-emerald-500/10", border: "border-emerald-500/20", glow: "shadow-emerald-500/10" };
    if (pm25 <= config.pm25_threshold * 0.8) return { color: "text-yellow-400", bg: "bg-yellow-500/10", border: "border-yellow-500/20", glow: "shadow-yellow-500/10" };
    if (pm25 <= config.pm25_threshold) return { color: "text-orange-400", bg: "bg-orange-500/10", border: "border-orange-500/20", glow: "shadow-orange-500/10" };
    return { color: "text-red-400", bg: "bg-red-500/10", border: "border-red-500/20", glow: "shadow-red-500/20" };
  };

  const getTempInfo = (temp: number | null) => {
    if (temp === null) return { color: "text-zinc-500", bg: "bg-zinc-500/10", border: "border-zinc-500/20", glow: "shadow-zinc-500/10" };
    if (temp > config.temperature_threshold) return { color: "text-red-400", bg: "bg-red-500/10", border: "border-red-500/20", glow: "shadow-red-500/10" };
    if (temp > config.temperature_threshold - 2) return { color: "text-orange-400", bg: "bg-orange-500/10", border: "border-orange-500/20", glow: "shadow-orange-500/10" };
    if (temp < 18) return { color: "text-cyan-400", bg: "bg-cyan-500/10", border: "border-cyan-500/20", glow: "shadow-cyan-500/10" };
    return { color: "text-emerald-400", bg: "bg-emerald-500/10", border: "border-emerald-500/20", glow: "shadow-emerald-500/10" };
  };

  return (
    <div 
      className="bg-black flex items-center justify-center overflow-hidden text-zinc-900 dark:text-zinc-100 font-sans fixed z-50 transition-transform duration-300"
      style={isPortrait ? {
        width: `${dims.h}px`,
        height: `${dims.w}px`,
        transform: 'rotate(-90deg)',
        transformOrigin: 'top left',
        top: `${dims.h}px`,
        left: 0,
      } : {
        width: '100%',
        height: '100%',
        top: 0,
        left: 0,
      }}
    >
      {/* Wake Up Overlay Button */}
      <button 
        onClick={onWake}
        className="absolute top-8 right-8 px-8 py-3 bg-zinc-200 dark:bg-white/10 hover:bg-white/20 border border-white/20 text-zinc-900 dark:text-white rounded-full text-sm font-medium transition-colors z-50 backdrop-blur-xl shadow-2xl"
      >
        Wake Up
      </button>

      {/* Main Content Layout */}
      <div className="w-full h-full px-8 flex flex-row items-center justify-center gap-16 lg:gap-24 animate-in fade-in duration-1000 box-border relative">
        
        {/* Subtle Ambient Background Glows */}
        <div className="absolute top-1/2 left-1/4 w-96 h-96 bg-blue-500/10 blur-[100px] rounded-full -translate-y-1/2 -translate-x-1/2 pointer-events-none"></div>
        <div className="absolute top-1/2 right-1/4 w-96 h-96 bg-emerald-500/10 blur-[100px] rounded-full -translate-y-1/2 translate-x-1/2 pointer-events-none"></div>

        {/* Left Side: Massive Sleek Clock */}
        <div className="flex flex-col items-start select-none shrink-0 z-10">
          {time ? (
            <>
              <h1 className="text-[120px] lg:text-[170px] leading-none font-semibold tracking-tighter text-transparent bg-clip-text bg-gradient-to-br from-white to-zinc-500 drop-shadow-2xl">
                {formatTime(time)}
              </h1>
              <p className="text-2xl lg:text-3xl text-zinc-600 dark:text-zinc-400 font-light mt-2 tracking-wider uppercase ml-3">
                {formatDate(time)}
              </p>
            </>
          ) : (
            <div className="h-[200px] w-[400px] bg-zinc-100 dark:bg-white/5 animate-pulse rounded-3xl backdrop-blur-md"></div>
          )}
        </div>

        {/* Right Side: HomeKit Style Vitals Grid */}
        <div className="w-auto flex flex-col gap-6 shrink-0 z-10">
          <div className="flex flex-row gap-6 justify-end">
            
            {/* PM2.5 Widget */}
            <div className={`backdrop-blur-2xl border rounded-[32px] p-8 flex flex-col items-center justify-center min-w-[170px] transition-all duration-700 shadow-2xl ${data ? getAqiInfo(data.pm25_level).bg + ' ' + getAqiInfo(data.pm25_level).border + ' ' + getAqiInfo(data.pm25_level).glow : 'bg-zinc-100 dark:bg-white/5 border-zinc-300 dark:border-white/10'}`}>
              <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className={`mb-3 ${data ? getAqiInfo(data.pm25_level).color : 'text-zinc-600 dark:text-zinc-400'}`}>
                <path d="M9.59 4.59A2 2 0 1 1 11 8H2m10.59 11.41A2 2 0 1 0 14 16H2m15.73-8.27A2.5 2.5 0 1 1 19.5 12H2"/>
              </svg>
              {data ? (
                <span className={`text-6xl lg:text-7xl font-semibold tracking-tight ${getAqiInfo(data.pm25_level).color}`}>
                  {Number(data.pm25_level).toFixed(1)}
                </span>
              ) : (
                <span className="text-6xl text-zinc-600">--</span>
              )}
              <span className="text-zinc-600 dark:text-zinc-400 text-xs font-medium uppercase tracking-widest mt-2">PM 2.5</span>
            </div>

            {/* Temperature Widget */}
            <div className={`backdrop-blur-2xl border rounded-[32px] p-8 flex flex-col items-center justify-center min-w-[170px] transition-all duration-700 shadow-2xl ${data ? getTempInfo(data.temperature).bg + ' ' + getTempInfo(data.temperature).border + ' ' + getTempInfo(data.temperature).glow : 'bg-zinc-100 dark:bg-white/5 border-zinc-300 dark:border-white/10'}`}>
              <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className={`mb-3 ${data ? getTempInfo(data.temperature).color : 'text-zinc-600 dark:text-zinc-400'}`}>
                <path d="M14 4v10.54a4 4 0 1 1-4 0V4a2 2 0 0 1 4 0Z"></path>
              </svg>
              {data ? (
                <span className={`text-6xl lg:text-7xl font-semibold tracking-tight ${getTempInfo(data.temperature).color}`}>
                  {data.temperature === null ? "--" : Math.round(data.temperature)}
                </span>
              ) : (
                <span className="text-6xl text-zinc-600">--</span>
              )}
              <span className="text-zinc-600 dark:text-zinc-400 text-xs font-medium uppercase tracking-widest mt-2">Temp °C</span>
            </div>
          </div>

          {/* AI Status Pill */}
          <div className={`rounded-full px-6 py-4 border backdrop-blur-2xl flex items-center justify-center gap-3 transition-colors duration-700 shadow-2xl ${
            coughDetected 
            ? 'bg-red-500/10 border-red-500/30 shadow-red-500/20' 
            : 'bg-zinc-100 dark:bg-white/5 border-zinc-300 dark:border-white/10 shadow-white/5'
          }`}>
            <span className="relative flex h-3 w-3">
              <span className={`animate-ping absolute inline-flex h-full w-full rounded-full opacity-75 ${coughDetected ? 'bg-red-400' : !data ? 'bg-orange-400' : 'bg-emerald-400'}`}></span>
              <span className={`relative inline-flex rounded-full h-3 w-3 ${coughDetected ? 'bg-red-500' : !data ? 'bg-orange-500' : 'bg-emerald-500'}`}></span>
            </span>
            <p className="text-sm font-medium tracking-wide text-zinc-800 dark:text-zinc-200">
              {coughDetected 
                ? "AI Alert: Cough Detected" 
                : !data 
                  ? "Awaiting Sensor Data"
                  : "Monitoring Active"}
            </p>
          </div>
        </div>
      </div>
    </div>
  );
}
