"use client";
import { useEffect, useState } from "react";
import InhalerTracker from "./InhalerTracker";
import { dustLevel, DUST_BANDS_NOTE } from "@/lib/dustBands";
import { barPercent, readingLevel, LEVEL_STYLE, type Level } from "@/lib/readingStatus";
import { GAS_NOTE } from "@/lib/gas";

type Telemetry = {
  pm25_level: number;
  temperature: number | null; // null when the DHT22 read failed
  humidity: number | null;
  mq135_level?: number | null; // estimated ppm (CO2-equivalent); null = no gas signal
  recorded_at: string;
};

type LimitName = "pm25" | "temperature" | "humidity" | "mq135";
const LIMIT_NAMES: LimitName[] = ["pm25", "temperature", "humidity", "mq135"];
const round1 = (v: number) => Math.round(v * 10) / 10;

export default function LiveMonitor() {
  const [data, setData] = useState<Telemetry | null>(null);
  const [coughDetected, setCoughDetected] = useState(false);
  const [isOffline, setIsOffline] = useState(true);
  // *_threshold = effective limit (what the device uses); caps = the user's own values; locked = AI never changes it.
  const [config, setConfig] = useState({
    pm25_threshold: 35, temperature_threshold: 35, humidity_threshold: 75, mq135_threshold: 1000, ai_optimization_enabled: true,
    caps: {} as Partial<Record<LimitName, number>>,
    locked: {} as Partial<Record<LimitName, boolean>>,
    // Limits without the missed-dose rule while it is on (the effective ones are 15 % lower), else null.
    doseBase: null as Partial<Record<LimitName, number>> | null,
  });
  // Stage of the AI model ("Stage 1 (Anomaly Detection)" / "Stage 2 (Personalised)"), or null while learning.
  const [aiStage, setAiStage] = useState<string | null>(null);
  // Top of the room's normal range per limit (from the AI engine), e.g. { humidity_threshold: 73.6 }.
  const [roomNormal, setRoomNormal] = useState<Record<string, number> | null>(null);

  useEffect(() => {
    const fetchAiStage = async () => {
      try {
        const token = localStorage.getItem("auth_token");
        const res = await fetch("/api/ai/predict", {
          headers: { "Authorization": `Bearer ${token}`, "Accept": "application/json" }
        });
        if (!res.ok) { setAiStage(null); setRoomNormal(null); return; }
        const p = await res.json();
        setAiStage(p.learning || p.probability_of_attack == null ? null : (p.model_stage ?? "AI model"));
        setRoomNormal(p.room_normal_limits ?? null);
      } catch {
        setAiStage(null);
        setRoomNormal(null);
      }
    };
    fetchAiStage();
    const interval = setInterval(fetchAiStage, 60000);
    return () => clearInterval(interval);
  }, []);

  useEffect(() => {
    let inFlight = false;
    let tick = 0;

    const fetchTelemetry = async (headers: HeadersInit) => {
      // Only the newest reading is needed here.
      const res = await fetch("/api/telemetry?limit=1", { headers });
      if (res.status === 401) {
        window.location.href = "/login";
        return;
      }
      if (!res.ok) return;
      const logs = await res.json();
      if (logs.length === 0) {
        setData(null);
        setIsOffline(true);
        return;
      }
      const latestLog = logs[0];
      setData(latestLog);

      // Offline = no reading for 20 s (the ESP32 sends every 3 s). Measured against the server's
      // clock (X-Server-Time, else the Date header) so a wrong clock on the viewer's computer
      // cannot make the device flicker between online and offline.
      const serverNow = Number(res.headers.get("X-Server-Time")) || Date.parse(res.headers.get("Date") ?? "") || Date.now();
      const recordedAtUtc = latestLog.recorded_at.endsWith('Z') ? latestLog.recorded_at : latestLog.recorded_at + 'Z';
      setIsOffline((serverNow - new Date(recordedAtUtc).getTime()) / 1000 > 20);
    };

    const fetchData = async () => {
      if (inFlight) return; // never stack requests if the network is slow
      inFlight = true;
      try {
        const token = localStorage.getItem("auth_token");
        const headers = { "Authorization": `Bearer ${token}`, "Accept": "application/json" };
        // Live values every 2 s; cough status and limits change rarely, so every 6 s.
        const slow = tick++ % 3 === 0;
        await Promise.all([
          fetchTelemetry(headers),
          slow ? fetchSlow(headers) : Promise.resolve(),
        ]);
      } catch (err) {
      } finally {
        inFlight = false;
      }
    };

    const fetchSlow = async (headers: HeadersInit) => {
        // Fetch Cough Events to set AI Status
        const coughRes = await fetch("/api/cough-events?per_page=1", { headers });
        if (coughRes.ok) {
          const coughData = await coughRes.json();
          if (coughData.data && coughData.data.length > 0) {
            const latestEvent = coughData.data[0];
            const diffHours = (new Date().getTime() - new Date(latestEvent.recorded_at).getTime()) / (1000 * 60 * 60);
            setCoughDetected(diffHours < 12); // Alert if cough detected in last 12 hours
          }
        }

        // Fetch thresholds from config API
        const confRes = await fetch("/api/config", { headers });
        if (confRes.ok) {
          const confData = await confRes.json();
          const caps: Partial<Record<LimitName, number>> = {};
          const locked: Partial<Record<LimitName, boolean>> = {};
          for (const name of LIMIT_NAMES) {
            if (confData[`${name}_cap`] != null) caps[name] = round1(confData[`${name}_cap`]);
            locked[name] = !!confData[`${name}_locked`];
          }
          setConfig({
            pm25_threshold: round1(confData.pm25_threshold || 35),
            temperature_threshold: round1(confData.temperature_threshold || 35),
            humidity_threshold: round1(confData.humidity_threshold || 75),
            mq135_threshold: Math.round(confData.mq135_threshold || 1000),
            ai_optimization_enabled: confData.ai_optimization_enabled !== undefined ? confData.ai_optimization_enabled : true,
            caps,
            locked,
            doseBase: confData.missed_dose_base ?? null,
          });
        }
    };

    fetchData();
    const interval = setInterval(fetchData, 2000);
    return () => clearInterval(interval);
  }, []);

  // Headlines follow the same rule as the bars (lib/readingStatus.ts): green / amber "near" / red "over".
  type CardInfo = { text: string } & (typeof LEVEL_STYLE)[Level];
  const card = (text: string, level: Level): CardInfo => ({ text, ...LEVEL_STYLE[level] });

  const getAirInfo = (pm25: number, gas?: number | null): CardInfo => {
    const dust = readingLevel("pm25", pm25, config.pm25_threshold);
    const gasLevel = gas != null ? readingLevel("mq135", gas, config.mq135_threshold) : "ok";
    if (dust === "over" || gasLevel === "over") return card("Action Needed", "over");
    if (dust === "near") return card("Slightly Dusty", "near");
    if (gasLevel === "near") return card("Slightly Stuffy", "near"); // high CO2-equivalent: the room needs fresh air
    // Below the limits: the word follows the published dust bands (lib/dustBands.ts).
    const band = dustLevel(pm25);
    if (band === "high") return card("Dusty", "near");
    return card(band === "low" ? "Clean Air" : "Fair Air", "ok");
  };

  const getClimateInfo = (temp: number | null, hum: number | null): CardInfo => {
    if (temp === null || hum === null) return { text: "Sensor Error", color: "text-zinc-500", bg: "bg-zinc-500/10", bar: "bg-zinc-400" };
    const t = readingLevel("temperature", temp, config.temperature_threshold);
    const h = readingLevel("humidity", hum, config.humidity_threshold);
    if (t === "over" || h === "over") return card("Action Needed", "over");
    if (t === "near") return card("Slightly Warm", "near");
    if (h === "near") return card("Slightly Humid", "near");
    return card("Comfortable", "ok");
  };

  const isAqiBreached = data ? data.pm25_level > config.pm25_threshold || (data.mq135_level != null && data.mq135_level > config.mq135_threshold) : false;
  const isClimateBreached = data ? (data.temperature ?? -Infinity) > config.temperature_threshold || (data.humidity ?? -Infinity) > config.humidity_threshold : false;
  const isEnvironmentUnsafe = isAqiBreached || isClimateBreached;

  // "AI" only when AI optimisation is on and the engine has a model: ai:optimize then applies its
  // limits every 5 minutes. While the engine is learning, the limits are the user's or the defaults.
  // Badges next to each limit:
  //   "AI"     the AI engine lowered this limit below the user's own value (cap)
  //   "locked" the user locked it; the AI never changes it
  //   "dose"   the missed daily dose rule lowered it 15 % until a daily dose is logged
  //   "!"      the limit is inside the room's usual range, so it will alarm often
  const badgeClass = "text-[9px] px-1.5 py-0.5 rounded ml-1.5 font-bold tracking-wider";
  const limitBadges = (name: LimitName) => {
    const effective = config[`${name}_threshold` as `${LimitName}_threshold`];
    const cap = config.caps[name] ?? effective;
    const ruleOn = config.doseBase != null && !config.locked[name];
    const aiLimit = config.doseBase?.[name] ?? effective; // what the AI set, before the rule
    const roomTop = roomNormal?.[`${name}_threshold`];
    return (
      <>
        {config.locked[name] ? (
          <span className={`${badgeClass} bg-zinc-500/20 text-zinc-400`} title="Locked: the AI never changes this limit">locked</span>
        ) : config.ai_optimization_enabled && aiStage && aiLimit < cap ? (
          <span className={`${badgeClass} bg-blue-500/20 text-blue-400`} title={`Lowered by the AI engine (${aiStage}) from your limit of ${cap}`}>AI</span>
        ) : null}
        {ruleOn && (
          <span className={`${badgeClass} bg-orange-500/20 text-orange-400`} title={`Daily inhaler dose missed: 15 % lower until one is logged (normally ${round1(aiLimit)})`}>dose</span>
        )}
        {roomTop != null && effective < roomTop && (
          <span className={`${badgeClass} bg-amber-500/20 text-amber-400`} title={`Your room usually reaches ${roomTop}, above this limit: expect frequent alerts`}>!</span>
        )}
      </>
    );
  };

  // Bars: empty at "nothing to worry about", full at the limit; coloured by the same rule as the headlines.
  const barWidth = (name: LimitName, value: number | null | undefined, limit: number) =>
    isOffline || value == null ? 0 : barPercent(name, value, limit);
  const barColor = (name: LimitName, value: number | null | undefined, limit: number) =>
    isOffline || value == null ? "bg-transparent" : LEVEL_STYLE[readingLevel(name, value, limit)].bar;

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
                {isOffline ? "Device Offline" : (data ? getAirInfo(data.pm25_level, data.mq135_level).text : "Awaiting Data")}
              </h3>
            </div>
            <div className={`w-12 h-12 rounded-full flex items-center justify-center transition-colors duration-500 ${isOffline ? 'bg-zinc-100 dark:bg-zinc-800/50' : (data ? getAirInfo(data.pm25_level, data.mq135_level).bg : 'bg-zinc-100 dark:bg-zinc-800/50')}`}>
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className={isOffline ? 'text-zinc-600 dark:text-zinc-400' : (data ? getAirInfo(data.pm25_level, data.mq135_level).color : 'text-zinc-600 dark:text-zinc-400')}>
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
                  className={`h-full rounded-full transition-all duration-1000 ${barColor("pm25", data?.pm25_level, config.pm25_threshold)}`} 
                  style={{ width: `${barWidth("pm25", data?.pm25_level, config.pm25_threshold)}%` }}
                ></div>
              </div>
              <div className="flex justify-between items-center mt-1">
                <p className="text-[10px] sm:text-xs text-zinc-600 dark:text-zinc-400 font-medium" title={DUST_BANDS_NOTE}>PM2.5</p>
                <p className="text-[10px] sm:text-xs text-zinc-600 dark:text-zinc-400 font-medium flex items-center whitespace-nowrap">
                  Limit: {config.pm25_threshold} {limitBadges("pm25")}
                </p>
              </div>
            </div>

            <div>
              <div className="flex items-end gap-1 mb-2">
                <span className="text-3xl sm:text-4xl font-semibold tracking-tight text-zinc-900 dark:text-white">
                  {isOffline ? "--" : (data?.mq135_level != null ? Math.round(data.mq135_level) : "--")}
                </span>
                <span className="text-zinc-600 dark:text-zinc-400 font-medium mb-1" title={GAS_NOTE}>ppm</span>
              </div>
              <div className="w-full h-1.5 bg-zinc-200 dark:bg-white/10 rounded-full overflow-hidden mb-2">
                <div 
                  className={`h-full rounded-full transition-all duration-1000 ${barColor("mq135", data?.mq135_level, config.mq135_threshold)}`}
                  style={{ width: `${barWidth("mq135", data?.mq135_level, config.mq135_threshold)}%` }}
                ></div>
              </div>
              <div className="flex justify-between items-center mt-1">
                <p className="text-[10px] sm:text-xs text-zinc-600 dark:text-zinc-400 font-medium" title={GAS_NOTE}>Gas</p>
                <p className="text-[10px] sm:text-xs text-zinc-600 dark:text-zinc-400 font-medium flex items-center whitespace-nowrap">
                  Limit: {config.mq135_threshold} {limitBadges("mq135")}
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
                <div className={`h-full rounded-full transition-all duration-1000 ${barColor("temperature", data?.temperature, config.temperature_threshold)}`} style={{ width: `${barWidth("temperature", data?.temperature, config.temperature_threshold)}%` }}></div>
              </div>
              <div className="flex justify-between items-center mt-1">
                <p className="text-[10px] sm:text-xs text-zinc-600 dark:text-zinc-400 font-medium">Temperature</p>
                <p className="text-[10px] sm:text-xs text-zinc-600 dark:text-zinc-400 font-medium flex items-center whitespace-nowrap">
                  Limit: {config.temperature_threshold}°C {limitBadges("temperature")}
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
                <div className={`h-full rounded-full transition-all duration-1000 ${barColor("humidity", data?.humidity, config.humidity_threshold)}`} style={{ width: `${barWidth("humidity", data?.humidity, config.humidity_threshold)}%` }}></div>
              </div>
              <div className="flex justify-between items-center mt-1">
                <p className="text-[10px] sm:text-xs text-zinc-600 dark:text-zinc-400 font-medium">Humidity</p>
                <p className="text-[10px] sm:text-xs text-zinc-600 dark:text-zinc-400 font-medium flex items-center whitespace-nowrap">
                  Limit: {config.humidity_threshold}% {limitBadges("humidity")}
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
                  ? 'Cough-like Sound Detected'
                  : isEnvironmentUnsafe
                    ? 'Warning: Alert Limit Exceeded'
                    : !data 
                      ? 'Awaiting ESP32 Edge Sensor Data' 
                      : 'Environment is Safe and Stable'}
            </h4>
            <p className="text-sm text-zinc-600 dark:text-zinc-400 mt-0.5 font-light">
              {isOffline
                ? 'Connection lost. Please check power and WiFi on the ESP32 device.'
                : coughDetected 
                  ? 'The device heard a loud cough-like sound in the last 12 hours. Keep the inhaler nearby if symptoms appear.'
                  : isEnvironmentUnsafe
                    ? 'A reading is above your alert limits. Consider ventilation or AC.'
                    : !data 
                      ? 'Please power on the ESP32 gateway to begin live monitoring.' 
                      : 'All readings are within your alert limits.'}
            </p>
          </div>
        </div>
      </div>

      <InhalerTracker />
    </section>
  );
}
