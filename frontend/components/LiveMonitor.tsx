"use client";
import { useEffect, useState } from "react";
import InhalerTracker from "./InhalerTracker";

type Telemetry = {
  pm25_level: number;
  temperature: number | null; // null when the DHT22 read failed
  humidity: number | null;
  mq135_level?: number;
  recorded_at: string;
};

export default function LiveMonitor() {
  const [data, setData] = useState<Telemetry | null>(null);
  const [coughDetected, setCoughDetected] = useState(false);
  const [isOffline, setIsOffline] = useState(true);
  const [config, setConfig] = useState({ pm25_threshold: 35, temperature_threshold: 35, humidity_threshold: 60, mq135_threshold: 300, ai_optimization_enabled: true });

  useEffect(() => {
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
          if (logs.length > 0) {
            const latestLog = logs[0];
            setData(latestLog);
            
            // Offline Detection: If the data is older than 90 seconds, mark as offline
            // We append 'Z' to tell JavaScript the timestamp is UTC (not local time)
            const recordedAtUtc = latestLog.recorded_at.endsWith('Z') ? latestLog.recorded_at : latestLog.recorded_at + 'Z';
            const diffSeconds = (new Date().getTime() - new Date(recordedAtUtc).getTime()) / 1000;
            setIsOffline(diffSeconds > 90);
          } else {
            setData(null);
            setIsOffline(true);
          }
        }

        // Fetch Cough Events to set AI Status
        const coughRes = await fetch("/api/cough-events?per_page=1", {
          headers: { 
            "Authorization": `Bearer ${token}`,
            "Accept": "application/json"
          }
        });
        if (coughRes.ok) {
          const coughData = await coughRes.json();
          if (coughData.data && coughData.data.length > 0) {
            const latestEvent = coughData.data[0];
            const diffHours = (new Date().getTime() - new Date(latestEvent.recorded_at).getTime()) / (1000 * 60 * 60);
            setCoughDetected(diffHours < 12); // Alert if cough detected in last 12 hours
          }
        }

        // Fetch thresholds from config API
        const confRes = await fetch("/api/config", {
          headers: { 
            "Authorization": `Bearer ${token}`,
            "Accept": "application/json"
          }
        });
        if (confRes.ok) {
          const confData = await confRes.json();
          setConfig({
            pm25_threshold: Math.round(confData.pm25_threshold || 35),
            temperature_threshold: Math.round(confData.temperature_threshold || 35),
            humidity_threshold: Math.round(confData.humidity_threshold || 60),
            mq135_threshold: Math.round(confData.mq135_threshold || 300),
            ai_optimization_enabled: confData.ai_optimization_enabled !== undefined ? confData.ai_optimization_enabled : true
          });
        }

      } catch (err) {}
    };
    fetchData();
    const interval = setInterval(fetchData, 3000);
    return () => clearInterval(interval);
  }, []);

  const getAqiInfo = (pm25: number) => {
    if (pm25 <= config.pm25_threshold * 0.3) return { text: "Excellent", color: "text-emerald-400", bg: "bg-emerald-400/10", border: "border-emerald-400/20", bar: "bg-emerald-400" };
    if (pm25 <= config.pm25_threshold * 0.8) return { text: "Fair", color: "text-yellow-400", bg: "bg-yellow-400/10", border: "border-yellow-400/20", bar: "bg-yellow-400" };
    if (pm25 <= config.pm25_threshold) return { text: "Poor", color: "text-orange-400", bg: "bg-orange-400/10", border: "border-orange-400/20", bar: "bg-orange-400" };
    return { text: "Hazardous", color: "text-red-400", bg: "bg-red-400/10", border: "border-red-400/20", bar: "bg-red-400" };
  };

  const getClimateInfo = (temp: number | null, hum: number | null) => {
    if (temp === null || hum === null) return { text: "Sensor Error", color: "text-zinc-500", bg: "bg-zinc-500/10", border: "border-zinc-500/20", bar: "bg-zinc-400" };
    if (temp > config.temperature_threshold || hum > config.humidity_threshold) return { text: "Action Needed", color: "text-red-400", bg: "bg-red-400/10", border: "border-red-400/20", bar: "bg-red-400" };
    if (temp > config.temperature_threshold - 2) return { text: "Slightly Warm", color: "text-orange-400", bg: "bg-orange-400/10", border: "border-orange-400/20", bar: "bg-orange-400" };
    if (hum > config.humidity_threshold - 5) return { text: "Slightly Humid", color: "text-blue-400", bg: "bg-blue-400/10", border: "border-blue-400/20", bar: "bg-blue-400" };
    if (temp < 18 || hum < 30) return { text: "Cool & Dry", color: "text-cyan-400", bg: "bg-cyan-400/10", border: "border-cyan-400/20", bar: "bg-cyan-400" };
    return { text: "Comfortable", color: "text-emerald-400", bg: "bg-emerald-400/10", border: "border-emerald-400/20", bar: "bg-emerald-400" };
  };

  const isAqiBreached = data ? data.pm25_level > config.pm25_threshold || (data.mq135_level !== undefined && data.mq135_level > config.mq135_threshold) : false;
  const isClimateBreached = data ? (data.temperature ?? -Infinity) > config.temperature_threshold || (data.humidity ?? -Infinity) > config.humidity_threshold : false;
  const isEnvironmentUnsafe = isAqiBreached || isClimateBreached;

  return (
    <section className="flex flex-col gap-6">
      {/* Vitals Hero Cards */}
      <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
        
        {/* Air Quality Card */}
        <div className="bg-white dark:bg-[#12121e]/80 backdrop-blur-xl border border-zinc-200 dark:border-white/5 rounded-3xl p-6 sm:p-8 shadow-xl relative overflow-hidden">
          <div className="absolute top-0 right-0 w-32 h-32 bg-emerald-500/10 blur-[50px] rounded-full pointer-events-none -mt-10 -mr-10 transition-colors duration-1000"></div>
          
          <div className="flex justify-between items-start mb-6">
            <div>
              <p className="text-sm text-zinc-600 dark:text-zinc-400 font-medium tracking-wide uppercase mb-1">Air Quality</p>
              <h3 className="text-2xl sm:text-3xl font-semibold text-zinc-900 dark:text-zinc-100">
                {isOffline ? "Device Offline" : (data ? getAqiInfo(data.pm25_level).text : "Awaiting Data")}
              </h3>
            </div>
            <div className={`w-12 h-12 rounded-full flex items-center justify-center transition-colors duration-500 ${isOffline ? 'bg-zinc-100 dark:bg-zinc-800/50' : (data ? getAqiInfo(data.pm25_level).bg : 'bg-zinc-100 dark:bg-zinc-800/50')}`}>
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className={isOffline ? 'text-zinc-600 dark:text-zinc-400' : (data ? getAqiInfo(data.pm25_level).color : 'text-zinc-600 dark:text-zinc-400')}>
                <path d="M9.59 4.59A2 2 0 1 1 11 8H2m10.59 11.41A2 2 0 1 0 14 16H2m15.73-8.27A2.5 2.5 0 1 1 19.5 12H2"/>
              </svg>
            </div>
          </div>

          <div className={`mt-8 grid grid-cols-2 gap-4 transition-opacity duration-1000 ${isOffline ? 'opacity-30' : 'opacity-100'}`}>
            <div>
              <div className="flex items-end gap-1 mb-2">
                <span className="text-3xl sm:text-4xl font-semibold tracking-tight text-zinc-900 dark:text-white">
                  {isOffline ? "--" : (data ? data.pm25_level.toFixed(1) : "--")}
                </span>
                <span className="text-zinc-600 dark:text-zinc-400 font-medium mb-1">µg/m³</span>
              </div>
              <div className="w-full h-1.5 bg-zinc-200 dark:bg-white/10 rounded-full overflow-hidden mb-2">
                <div 
                  className={`h-full transition-all duration-1000 ${isOffline ? 'bg-transparent' : (data ? getAqiInfo(data.pm25_level).bar : 'bg-transparent')}`} 
                  style={{ width: `${isOffline ? 0 : Math.min(((data?.pm25_level || 0) / 60) * 100, 100)}%` }}
                ></div>
              </div>
              <div className="flex justify-between items-center mt-1">
                <p className="text-[10px] sm:text-xs text-zinc-600 dark:text-zinc-400 font-medium">PM2.5</p>
                <p className="text-[10px] sm:text-xs text-zinc-600 font-medium flex items-center whitespace-nowrap">
                  Limit: {config.pm25_threshold} {config.ai_optimization_enabled && <span className="bg-blue-500/20 text-blue-400 text-[9px] px-1.5 py-0.5 rounded ml-1.5 font-bold tracking-wider">AI</span>}
                </p>
              </div>
            </div>

            <div>
              <div className="flex items-end gap-1 mb-2">
                <span className="text-3xl sm:text-4xl font-semibold tracking-tight text-zinc-900 dark:text-white">
                  {isOffline ? "--" : (data ? (data.mq135_level !== undefined ? data.mq135_level : "--") : "--")}
                </span>
                <span className="text-zinc-600 dark:text-zinc-400 font-medium mb-1">ppm</span>
              </div>
              <div className="w-full h-1.5 bg-zinc-200 dark:bg-white/10 rounded-full overflow-hidden mb-2">
                <div 
                  className={`h-full bg-indigo-400 rounded-full transition-all duration-1000 ${isOffline ? 'opacity-0' : 'opacity-100'}`}
                  style={{ width: `${isOffline ? 0 : Math.min(((data?.mq135_level || 0) / 4095) * 100, 100)}%` }}
                ></div>
              </div>
              <div className="flex justify-between items-center mt-1">
                <p className="text-[10px] sm:text-xs text-zinc-600 dark:text-zinc-400 font-medium">Gas / VOCs</p>
                <p className="text-[10px] sm:text-xs text-zinc-600 font-medium flex items-center whitespace-nowrap">
                  Limit: {config.mq135_threshold} {config.ai_optimization_enabled && <span className="bg-blue-500/20 text-blue-400 text-[9px] px-1.5 py-0.5 rounded ml-1.5 font-bold tracking-wider">AI</span>}
                </p>
              </div>
            </div>
          </div>
        </div>

        {/* Room Climate Card */}
        <div className="bg-white dark:bg-[#12121e]/80 backdrop-blur-xl border border-zinc-200 dark:border-white/5 rounded-3xl p-6 sm:p-8 shadow-xl relative overflow-hidden">
          <div className="absolute top-0 right-0 w-32 h-32 bg-blue-500/10 blur-[50px] rounded-full pointer-events-none -mt-10 -mr-10 transition-colors duration-1000"></div>
          
          <div className="flex justify-between items-start mb-6">
            <div>
              <p className="text-sm text-zinc-600 dark:text-zinc-400 font-medium tracking-wide uppercase mb-1">Room Climate</p>
              <h3 className="text-2xl sm:text-3xl font-semibold text-zinc-900 dark:text-zinc-100">
                {isOffline ? "Device Offline" : (data ? getClimateInfo(data.temperature, data.humidity).text : "Awaiting Data")}
              </h3>
            </div>
            <div className={`w-12 h-12 rounded-full flex items-center justify-center transition-colors duration-500 ${isOffline ? 'bg-zinc-100 dark:bg-zinc-800/50' : (data ? getClimateInfo(data.temperature, data.humidity).bg : 'bg-zinc-100 dark:bg-zinc-800/50')}`}>
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className={isOffline ? 'text-zinc-600 dark:text-zinc-400' : (data ? getClimateInfo(data.temperature, data.humidity).color : 'text-zinc-600 dark:text-zinc-400')}>
                <path d="M14 4v10.54a4 4 0 1 1-4 0V4a2 2 0 0 1 4 0Z"></path>
              </svg>
            </div>
          </div>

          <div className={`mt-8 grid grid-cols-2 gap-4 transition-opacity duration-1000 ${isOffline ? 'opacity-30' : 'opacity-100'}`}>
            <div>
              <div className="flex items-end gap-1 mb-2">
                <span className="text-3xl sm:text-4xl font-semibold tracking-tight text-zinc-900 dark:text-white">
                  {isOffline || !data || data.temperature === null ? "--" : Math.round(data.temperature)}
                </span>
                <span className="text-zinc-600 dark:text-zinc-400 font-medium mb-1">°C</span>
              </div>
              <div className="w-full h-1.5 bg-zinc-200 dark:bg-white/10 rounded-full overflow-hidden mb-2">
                <div className={`h-full bg-blue-400 rounded-full transition-all duration-1000 ${isOffline ? 'opacity-0' : 'opacity-100'}`} style={{ width: `${isOffline ? 0 : Math.min(((data?.temperature || 0) / 40) * 100, 100)}%` }}></div>
              </div>
              <div className="flex justify-between items-center mt-1">
                <p className="text-[10px] sm:text-xs text-zinc-600 dark:text-zinc-400 font-medium">Temperature</p>
                <p className="text-[10px] sm:text-xs text-zinc-600 dark:text-zinc-400 font-medium flex items-center whitespace-nowrap">
                  Limit: {config.temperature_threshold}°C {config.ai_optimization_enabled && <span className="bg-blue-500/20 text-blue-400 text-[9px] px-1.5 py-0.5 rounded ml-1.5 font-bold tracking-wider">AI</span>}
                </p>
              </div>
            </div>
            <div>
              <div className="flex items-end gap-1 mb-2">
                <span className="text-3xl sm:text-4xl font-semibold tracking-tight text-zinc-900 dark:text-white">
                  {isOffline || !data || data.humidity === null ? "--" : Math.round(data.humidity)}
                </span>
                <span className="text-zinc-600 dark:text-zinc-400 font-medium mb-1">%</span>
              </div>
              <div className="w-full h-1.5 bg-zinc-200 dark:bg-white/10 rounded-full overflow-hidden mb-2">
                <div className={`h-full bg-blue-400 rounded-full transition-all duration-1000 ${isOffline ? 'opacity-0' : 'opacity-100'}`} style={{ width: `${isOffline ? 0 : Math.min(((data?.humidity || 0) / 100) * 100, 100)}%` }}></div>
              </div>
              <div className="flex justify-between items-center mt-1">
                <p className="text-[10px] sm:text-xs text-zinc-600 dark:text-zinc-400 font-medium">Humidity</p>
                <p className="text-[10px] sm:text-xs text-zinc-600 dark:text-zinc-400 font-medium flex items-center whitespace-nowrap">
                  Limit: {config.humidity_threshold}% {config.ai_optimization_enabled && <span className="bg-blue-500/20 text-blue-400 text-[9px] px-1.5 py-0.5 rounded ml-1.5 font-bold tracking-wider">AI</span>}
                </p>
              </div>
            </div>
          </div>
        </div>

      </div>

      {/* Breathing / AI Status Card */}
      <div className={`p-5 sm:p-6 rounded-3xl border backdrop-blur-xl flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 transition-all duration-500 shadow-xl ${
        isOffline
          ? 'bg-white dark:bg-zinc-900/50 border-zinc-200 dark:border-white/5'
          : coughDetected
            ? 'bg-red-500/5 border-red-500/20 shadow-red-500/5'
            : isEnvironmentUnsafe
              ? 'bg-orange-500/5 border-orange-500/20 shadow-orange-500/5'
              : !data 
                ? 'bg-blue-500/5 border-blue-500/20'
                : 'bg-white dark:bg-[#12121e]/80 border-zinc-200 dark:border-white/5'
      }`}>
        <div className="flex items-start sm:items-center gap-4">
          <div className="mt-1 sm:mt-0 relative flex h-4 w-4 shrink-0">
            <span className={`absolute inline-flex h-full w-full rounded-full opacity-75 ${isOffline ? 'bg-zinc-600' : 'animate-ping ' + (coughDetected ? 'bg-red-400' : isEnvironmentUnsafe ? 'bg-orange-400' : !data ? 'bg-blue-400' : 'bg-emerald-400')}`}></span>
            <span className={`relative inline-flex rounded-full h-4 w-4 ${isOffline ? 'bg-zinc-500' : coughDetected ? 'bg-red-500' : isEnvironmentUnsafe ? 'bg-orange-500' : !data ? 'bg-blue-500' : 'bg-emerald-500'}`}></span>
          </div>
          <div>
            <h4 className="text-base sm:text-lg font-semibold text-zinc-900 dark:text-zinc-100">
              {isOffline
                ? 'ESP32 Gateway Offline'
                : coughDetected 
                  ? 'AI Warning: Cough Event Detected'
                  : isEnvironmentUnsafe
                    ? 'AI Warning: Environmental Limit Exceeded'
                    : !data 
                      ? 'Awaiting ESP32 Edge Sensor Data' 
                      : 'Environment is Safe and Stable'}
            </h4>
            <p className="text-sm text-zinc-600 dark:text-zinc-400 mt-0.5 font-light">
              {isOffline
                ? 'Connection lost. Please check power and WiFi on the ESP32 device.'
                : coughDetected 
                  ? 'High probability of asthma triggers present in the room. Keep inhaler nearby.'
                  : isEnvironmentUnsafe
                    ? 'Current room conditions exceed AI safety thresholds. Consider ventilation or AC.'
                    : !data 
                      ? 'Please power on the ESP32 gateway to begin live monitoring.' 
                      : 'AI Active (Smart Monitoring)'}
            </p>
          </div>
        </div>
      </div>

      <InhalerTracker />
    </section>
  );
}
