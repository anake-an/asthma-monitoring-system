"use client";
import { useEffect, useState } from "react";
import { GAS_NOTE, GAS_DEFAULT_LIMIT_PPM } from "@/lib/gas";

export function urlBase64ToUint8Array(base64String: string) {
  const padding = '='.repeat((4 - base64String.length % 4) % 4);
  const base64 = (base64String + padding).replace(/\-/g, '+').replace(/_/g, '/');
  const rawData = window.atob(base64);
  const outputArray = new Uint8Array(rawData.length);
  for (let i = 0; i < rawData.length; ++i) {
    outputArray[i] = rawData.charCodeAt(i);
  }
  return outputArray;
}

export default function CommandCenter() {
  // In Smart Alerts each *_threshold is the user's own value (the cap): the AI may lower the effective
  // limit below it (toward what is usual for the room), never raise it above. *_locked: the AI never changes it.
  const [config, setConfig] = useState({
    pm25_threshold: 35,
    temperature_threshold: 35,
    humidity_threshold: 75,
    mq135_threshold: GAS_DEFAULT_LIMIT_PPM,
    pm25_locked: false,
    temperature_locked: false,
    humidity_locked: false,
    mq135_locked: false,
    is_buzzer_muted: false,
    ai_optimization_enabled: true
  });
  const [saving, setSaving] = useState(false);
  const [saved, setSaved] = useState(false);
  const [toast, setToast] = useState<{message: string, type: 'success' | 'error'} | null>(null);
  
  // Modal State
  const [showModal, setShowModal] = useState(false);
  const [showAccountModal, setShowAccountModal] = useState(false);
  const [user, setUser] = useState<{name: string, email: string, created_at: string} | null>(null);
  const [deletingAccount, setDeletingAccount] = useState(false);
  const [currentPassword, setCurrentPassword] = useState("");
  const [newPassword, setNewPassword] = useState("");
  const [newPasswordConfirm, setNewPasswordConfirm] = useState("");
  const [updatingPassword, setUpdatingPassword] = useState(false);
  const [showPasswordForm, setShowPasswordForm] = useState(false);
  const [confirmAction, setConfirmAction] = useState<{
    isOpen: boolean,
    title: string,
    message: string,
    isDanger: boolean,
    onConfirm: () => void
  }>({ isOpen: false, title: "", message: "", isDanger: false, onConfirm: () => {} });
  const [pairingToken, setPairingToken] = useState<string | null>(null);
  const [generatingToken, setGeneratingToken] = useState(false);
  const [devices, setDevices] = useState<{id: number, name: string, status: string, device_token: string}[]>([]);

  // Lock switch under each limit, with a line saying what the AI may do with it.
  const lockRow = (name: "pm25" | "temperature" | "humidity" | "mq135") => {
    const key = `${name}_locked` as const;
    return (
      <label className="flex items-center justify-between gap-3 mt-3 text-[11px] text-zinc-600 dark:text-zinc-400 cursor-pointer">
        <span className="font-light">
          {config[key]
            ? "Locked: the AI never changes this limit."
            : config.ai_optimization_enabled
              ? "The AI may lower this to just above what is usual for your room, and further when the room is unusual (once a day at most, by up to 10 %, after a full day of readings), never above your value."
              : "AI optimization is off: this limit is used as set."}
        </span>
        <span className="flex items-center gap-1.5 shrink-0 font-medium">
          <input type="checkbox" checked={config[key]} onChange={(e) => setConfig({ ...config, [key]: e.target.checked })} className="accent-zinc-500" />
          Lock
        </span>
      </label>
    );
  };

  const showToast = (message: string, type: 'success' | 'error') => {
    setToast({ message, type });
    setTimeout(() => setToast(null), 3500);
  };

  useEffect(() => {
    const token = localStorage.getItem("auth_token");
    fetch("/api/config", {
      headers: { 
        "Authorization": `Bearer ${token}`,
        "Accept": "application/json"
      }
    })
      .then(res => {
        if (res.status === 401) window.location.href = "/login";
        return res.json();
      })
      .then(data => setConfig({
        // Show the user's own values (caps), not the AI-lowered effective limits.
        pm25_threshold: Math.round(data.pm25_cap ?? data.pm25_threshold ?? 35),
        temperature_threshold: Math.round(data.temperature_cap ?? data.temperature_threshold ?? 35),
        humidity_threshold: Math.round(data.humidity_cap ?? data.humidity_threshold ?? 75),
        mq135_threshold: Math.round(data.mq135_cap ?? data.mq135_threshold ?? GAS_DEFAULT_LIMIT_PPM),
        pm25_locked: !!data.pm25_locked,
        temperature_locked: !!data.temperature_locked,
        humidity_locked: !!data.humidity_locked,
        mq135_locked: !!data.mq135_locked,
        is_buzzer_muted: data.is_buzzer_muted || false,
        ai_optimization_enabled: data.ai_optimization_enabled !== undefined ? data.ai_optimization_enabled : true
      }))
      .catch(err => console.error(err));
      
    // Fetch user
    fetch("/api/user", {
      headers: {
        "Authorization": `Bearer ${token}`,
        "Accept": "application/json"
      }
    })
    .then(res => res.json())
    .then(data => {
      if(data.id) setUser(data);
    })
    .catch(err => console.error(err));

    const fetchDevices = () => {
      fetch("/api/devices", {
        headers: {
          "Authorization": `Bearer ${token}`,
          "Accept": "application/json"
        }
      })
      .then(res => res.json())
      .then(data => {
        if(data.devices) setDevices(data.devices);
      })
      .catch(err => console.error(err));
    };

    fetchDevices();
    const interval = setInterval(fetchDevices, 5000);
    return () => clearInterval(interval);
  }, []);

  const handleLogout = () => {
    setConfirmAction({
      isOpen: true,
      title: "Sign Out",
      message: "Are you sure you want to securely sign out of RespiroSync?",
      isDanger: false,
      onConfirm: async () => {
        try {
          await fetch("/api/logout", {
            method: "POST",
            headers: {
              "Authorization": `Bearer ${localStorage.getItem("auth_token")}`,
              "Accept": "application/json"
            }
          });
          localStorage.removeItem("auth_token");
          setTimeout(() => {
            window.location.href = "/login";
          }, 300);
        } catch(e) {
          showToast("Logout failed", "error");
        }
      }
    });
  };

  const handleDeleteAccount = () => {
    setConfirmAction({
      isOpen: true,
      title: "Permanently Delete Account",
      message: "DANGER: Are you absolutely sure you want to PERMANENTLY delete your account and all associated medical data? This action is irreversible.",
      isDanger: true,
      onConfirm: async () => {
        setDeletingAccount(true);
        try {
          const res = await fetch("/api/user", {
            method: "DELETE",
            headers: {
              "Authorization": `Bearer ${localStorage.getItem("auth_token")}`,
              "Accept": "application/json"
            }
          });
          if(res.ok) {
            localStorage.removeItem("auth_token");
            window.location.href = "/login";
          } else {
            showToast("Failed to delete account", "error");
            setDeletingAccount(false);
          }
        } catch(e) {
          showToast("Error connecting to server", "error");
          setDeletingAccount(false);
        }
      }
    });
  };

  const handlePairDevice = async () => {
    setGeneratingToken(true);
    try {
      const res = await fetch("/api/devices/generate-token", {
        method: "POST",
        headers: {
          "Authorization": `Bearer ${localStorage.getItem("auth_token")}`,
          "Accept": "application/json"
        }
      });
      const data = await res.json();
      if(res.ok) {
        setPairingToken(data.token);
      } else {
        showToast("Failed to generate setup token", "error");
      }
    } catch(e) {
      showToast("Error connecting to server", "error");
    } finally {
      setGeneratingToken(false);
    }
  };

  const handleDeleteDevice = async (id: number, name: string) => {
    setConfirmAction({
      isOpen: true,
      title: "Remove Device",
      message: `Are you sure you want to disconnect and remove "${name}"? You will need to pair it again to use it.`,
      isDanger: true,
      onConfirm: async () => {
        try {
          const res = await fetch(`/api/devices/${id}`, {
            method: "DELETE",
            headers: {
              "Authorization": `Bearer ${localStorage.getItem("auth_token")}`,
              "Accept": "application/json"
            }
          });
          if(res.ok) {
            setDevices(devices.filter(d => d.id !== id));
            showToast("Device removed successfully", "success");
          } else {
            showToast("Failed to remove device", "error");
          }
        } catch(e) {
          showToast("Error connecting to server", "error");
        }
      }
    });
  };

  const handleUpdatePassword = async (e: React.FormEvent) => {
    e.preventDefault();
    if(newPassword !== newPasswordConfirm) {
      showToast("New passwords do not match", "error");
      return;
    }
    setUpdatingPassword(true);
    try {
      const res = await fetch("/api/user/password", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "Authorization": `Bearer ${localStorage.getItem("auth_token")}`,
          "Accept": "application/json"
        },
        body: JSON.stringify({
          current_password: currentPassword,
          new_password: newPassword,
          new_password_confirmation: newPasswordConfirm
        })
      });
      const data = await res.json();
      if(res.ok) {
        showToast("Password updated successfully!", "success");
        setShowPasswordForm(false);
        setCurrentPassword("");
        setNewPassword("");
        setNewPasswordConfirm("");
      } else {
        showToast(data.message || "Failed to update password", "error");
      }
    } catch(e) {
      showToast("Error updating password", "error");
    } finally {
      setUpdatingPassword(false);
    }
  };
  const handleSave = async () => {
    setSaving(true);
    try {
      const res = await fetch("/api/config", {
        method: "POST",
        headers: { 
          "Content-Type": "application/json",
          "Authorization": `Bearer ${localStorage.getItem("auth_token")}`,
          "Accept": "application/json"
        },
        body: JSON.stringify(config),
      });
      setSaving(false);
      if (!res.ok) {
        showToast("Failed to save thresholds", "error");
        return;
      }
      setSaved(true);
      showToast("Smart thresholds updated", "success");
      setTimeout(() => setSaved(false), 2000);
    } catch (err) {
      console.error(err);
      setSaving(false);
    }
  };

  const getPm25Color = (val: number) => {
    if (val <= 12) return "text-emerald-400";
    if (val <= 35) return "text-yellow-400";
    if (val <= 55) return "text-orange-400";
    return "text-red-400";
  };

  const getPm25Accent = (val: number) => {
    if (val <= 12) return "accent-emerald-500";
    if (val <= 35) return "accent-yellow-500";
    if (val <= 55) return "accent-orange-500";
    return "accent-red-500";
  };

  const getTempColor = (val: number) => {
    if (val < 20) return "text-blue-400";
    if (val <= 28) return "text-emerald-400";
    if (val <= 35) return "text-orange-400";
    return "text-red-400";
  };

  const getTempAccent = (val: number) => {
    if (val < 20) return "accent-blue-500";
    if (val <= 28) return "accent-emerald-500";
    if (val <= 35) return "accent-orange-500";
    return "accent-red-500";
  };

  return (
    <>
      {/* The Clean "Quick Action" Card on the Dashboard */}
      <section className="bg-white/80 dark:bg-[#12121e]/80 backdrop-blur-xl border border-zinc-200 dark:border-white/5 rounded-3xl p-6 sticky top-6 shadow-xl flex-1 flex flex-col justify-center">
        <div className="flex items-center gap-4 mb-4">
          <div className="w-10 h-10 rounded-full bg-blue-500/10 flex items-center justify-center">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="text-blue-400">
              <path d="M12 20h9"></path>
              <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path>
            </svg>
          </div>
          <div>
            <h2 className="text-base font-semibold text-zinc-900 dark:text-zinc-100">Preferences</h2>
            <p className="text-xs text-zinc-600 dark:text-zinc-400 font-light">Manage device settings</p>
          </div>
        </div>

        <div className="space-y-2">
          <button 
            onClick={() => setShowModal(true)}
            className="w-full py-3 bg-zinc-100 dark:bg-white/5 hover:bg-zinc-200 dark:hover:bg-white/10 border border-zinc-300 dark:border-white/10 rounded-xl text-sm font-medium text-zinc-700 dark:text-zinc-300 transition-colors flex items-center justify-center gap-2"
          >
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
              <circle cx="12" cy="12" r="3"></circle>
              <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
            </svg>
            Configure Smart Alerts
          </button>
          
          <button 
            onClick={() => setShowAccountModal(true)}
            className="w-full py-3 bg-zinc-100 dark:bg-white/5 hover:bg-zinc-200 dark:hover:bg-white/10 border border-zinc-300 dark:border-white/10 rounded-xl text-sm font-medium text-zinc-700 dark:text-zinc-300 transition-colors flex items-center justify-center gap-2"
          >
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
              <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
              <circle cx="12" cy="7" r="4"></circle>
            </svg>
            Account Settings
          </button>
        </div>
      </section>

      {/* Full Settings Modal */}
      {showModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6 bg-zinc-800/40 dark:bg-black/60 backdrop-blur-sm animate-in fade-in duration-200">
          <div className="bg-white dark:bg-[#12121e]/95 backdrop-blur-xl border border-zinc-300 dark:border-white/10 rounded-3xl shadow-2xl w-full max-w-lg flex flex-col max-h-[90vh] overflow-hidden animate-in zoom-in-95 duration-200">
            
            <div className="px-6 py-5 border-b border-zinc-200 dark:border-white/5 flex items-center justify-between bg-zinc-100 dark:bg-white/5">
              <div>
                <h3 className="text-lg font-semibold text-zinc-900 dark:text-zinc-100">Smart Alerts Configuration</h3>
                <p className="text-xs text-zinc-600 dark:text-zinc-400 font-light mt-0.5">Customize room thresholds and notifications</p>
              </div>
              <button onClick={() => setShowModal(false)} className="p-2 bg-zinc-100 dark:bg-white/5 hover:bg-zinc-200 dark:bg-white/10 rounded-full text-zinc-600 dark:text-zinc-400 transition-colors">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
              </button>
            </div>

            <div className="p-6 overflow-y-auto space-y-5 custom-scrollbar">
              
              {/* AI Optimization Toggle */}
              <div className="bg-blue-500/10 border border-blue-500/20 rounded-2xl p-4 flex items-center justify-between shadow-[0_0_20px_rgba(59,130,246,0.1)]">
                <div>
                  <span className="text-sm font-semibold text-blue-900 dark:text-blue-100 flex items-center gap-2">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="text-blue-400"><path d="M12 2a10 10 0 1 0 10 10 4 4 0 0 1-5-5 4 4 0 0 1-5-5"></path></svg>
                    AI Smart Optimization
                  </span>
                  <span className="text-[11px] text-blue-700 dark:text-blue-200/60 font-light mt-1 block">The AI learns what is usual for your room and may lower a limit toward it, but never raises it above your value</span>
                </div>
                <label className="relative inline-flex items-center cursor-pointer">
                  <input type="checkbox" className="sr-only peer" checked={config.ai_optimization_enabled} onChange={(e) => setConfig({...config, ai_optimization_enabled: e.target.checked})} />
                  <div className="w-11 h-6 bg-blue-900/40 rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-blue-200 after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-500 shadow-inner"></div>
                </label>
              </div>

              {/* PM2.5 Threshold */}
              <div className={`bg-zinc-100 dark:bg-white/5 border border-zinc-200 dark:border-white/5 rounded-2xl p-5`}>
                <div className="flex justify-between items-center mb-1">
                  <label className="text-sm font-medium text-zinc-800 dark:text-zinc-200">Dust & Particles Limit (PM2.5)</label>
                  <span className={`text-lg font-semibold transition-colors ${getPm25Color(config.pm25_threshold)}`}>
                    {config.pm25_threshold} <span className="text-xs text-zinc-600 dark:text-zinc-400 font-normal">µg/m³</span>
                  </span>
                </div>
                <p className="text-xs text-zinc-600 dark:text-zinc-400 mb-4 font-light">Triggers room alarm if air becomes hazardous</p>
                <input
                  type="range" min="10" max="150"
                  value={config.pm25_threshold}
                  onChange={(e) => setConfig({...config, pm25_threshold: Number(e.target.value)})}
                  className="w-full accent-yellow-500"
                />
                <div className="flex justify-between text-[10px] text-zinc-600 dark:text-zinc-400 mt-2 font-medium">
                  <span>10 (Clean)</span><span>80 (Fair)</span><span>150 (Poor)</span>
                </div>
                {lockRow("pm25")}
              </div>

              {/* Gas Threshold */}
              <div className={`bg-zinc-100 dark:bg-white/5 border border-zinc-200 dark:border-white/5 rounded-2xl p-5`}>
                <div className="flex justify-between items-center mb-1">
                  <label className="text-sm font-medium text-zinc-800 dark:text-zinc-200">Air Gas Limit (VOCs)</label>
                  <span className="text-lg font-semibold text-indigo-400">
                    {config.mq135_threshold} <span className="text-xs text-zinc-600 dark:text-zinc-400 font-normal" title={GAS_NOTE}>ppm (est.)</span>
                  </span>
                </div>
                <p className="text-xs text-zinc-600 dark:text-zinc-400 mb-4 font-light">Estimated CO2-equivalent; also reacts to smoke and household chemicals</p>
                <input
                  type="range" min="450" max="3000" step="50"
                  value={config.mq135_threshold}
                  onChange={(e) => setConfig({...config, mq135_threshold: Number(e.target.value)})}
                  className="w-full accent-indigo-500"
                />
                <div className="flex justify-between text-[10px] text-zinc-600 dark:text-zinc-400 mt-2 font-medium">
                  <span>450 (Fresh air)</span><span>1000 (Ventilate)</span><span>3000 (Poor)</span>
                </div>
                {lockRow("mq135")}
              </div>

              {/* Temperature Threshold */}
              <div className={`bg-zinc-100 dark:bg-white/5 border border-zinc-200 dark:border-white/5 rounded-2xl p-5`}>
                <div className="flex justify-between items-center mb-1">
                  <label className="text-sm font-medium text-zinc-800 dark:text-zinc-200">Maximum Temperature</label>
                  <span className={`text-lg font-semibold transition-colors ${getTempColor(config.temperature_threshold)}`}>
                    {config.temperature_threshold}°C
                  </span>
                </div>
                <p className="text-xs text-zinc-600 dark:text-zinc-400 mb-4 font-light">Warns if the room becomes too hot</p>
                <input
                  type="range" min="20" max="50"
                  value={config.temperature_threshold}
                  onChange={(e) => setConfig({...config, temperature_threshold: Number(e.target.value)})}
                  className="w-full accent-orange-500"
                />
                <div className="flex justify-between text-[10px] text-zinc-600 dark:text-zinc-400 mt-2 font-medium">
                  <span>20°C (Cold)</span><span>35°C (Warm)</span><span>50°C (Hot)</span>
                </div>
                {lockRow("temperature")}
              </div>

              {/* Humidity Threshold */}
              <div className={`bg-zinc-100 dark:bg-white/5 border border-zinc-200 dark:border-white/5 rounded-2xl p-5`}>
                <div className="flex justify-between items-center mb-1">
                  <label className="text-sm font-medium text-zinc-800 dark:text-zinc-200">Maximum Humidity</label>
                  <span className="text-lg font-semibold text-blue-400">
                    {config.humidity_threshold}%
                  </span>
                </div>
                <p className="text-xs text-zinc-600 dark:text-zinc-400 mb-4 font-light">Warns if the room becomes too damp (mold risk)</p>
                <input
                  type="range" min="30" max="90"
                  value={config.humidity_threshold}
                  onChange={(e) => setConfig({...config, humidity_threshold: Number(e.target.value)})}
                  className="w-full accent-blue-500"
                />
                <div className="flex justify-between text-[10px] text-zinc-600 dark:text-zinc-400 mt-2 font-medium">
                  <span>30% (Dry)</span><span>60% (Comfortable)</span><span>90% (Damp)</span>
                </div>
                {lockRow("humidity")}
              </div>

              {/* Toggles Group */}
              <div className="space-y-3">
                {/* Buzzer Toggle */}
                <div className="bg-zinc-100 dark:bg-white/5 border border-zinc-200 dark:border-white/5 rounded-2xl p-4 flex items-center justify-between">
                  <div>
                    <span className="text-sm font-medium text-zinc-800 dark:text-zinc-200 block">Silence Physical Alarm</span>
                    <span className="text-[11px] text-zinc-600 dark:text-zinc-400 font-light">Mutes the buzzer on the device</span>
                  </div>
                  <label className="relative inline-flex items-center cursor-pointer">
                    <input type="checkbox" className="sr-only peer" checked={config.is_buzzer_muted} onChange={(e) => setConfig({...config, is_buzzer_muted: e.target.checked})} />
                    <div className="w-11 h-6 bg-zinc-100 dark:bg-zinc-800 rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-500"></div>
                  </label>
                </div>


              </div>

            </div>
            
            <div className="p-5 border-t border-zinc-200 dark:border-white/5 bg-zinc-100 dark:bg-black/20">
              <button
                onClick={handleSave}
                disabled={saving}
                className={`w-full py-3 rounded-xl text-sm font-medium transition-all shadow-lg ${
                  saved
                    ? 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30'
                    : 'bg-blue-600 text-zinc-900 dark:text-white hover:bg-blue-500 disabled:opacity-50'
                }`}
              >
                {saving ? "Syncing..." : saved ? "✓ Configuration Applied" : "Save Changes"}
              </button>
            </div>

          </div>
        </div>
      )}
      {/* Account Settings Modal */}
      {showAccountModal && user && (
        <div className="fixed inset-0 z-[70] flex items-center justify-center p-4 sm:p-6 bg-zinc-800/40 dark:bg-black/60 backdrop-blur-sm animate-in fade-in duration-200">
          <div className="bg-white dark:bg-[#12121e] border border-zinc-200 dark:border-white/10 w-full max-w-md rounded-3xl shadow-2xl overflow-hidden flex flex-col animate-in zoom-in-95 duration-200">
            
            <div className="px-6 py-5 border-b border-zinc-200 dark:border-white/10 flex justify-between items-center bg-zinc-50/50 dark:bg-white/5">
              <div className="flex items-center gap-3">
                <div className="w-8 h-8 rounded-full bg-blue-100 dark:bg-blue-500/20 flex items-center justify-center text-blue-600 dark:text-blue-400 font-bold">
                  {user.name.charAt(0).toUpperCase()}
                </div>
                <div>
                  <h3 className="font-semibold text-lg text-zinc-900 dark:text-white leading-tight">Account Settings</h3>
                  <p className="text-[11px] text-zinc-500 dark:text-zinc-400">{user.email}</p>
                </div>
              </div>
              <button 
                onClick={() => setShowAccountModal(false)}
                className="p-2 rounded-full hover:bg-zinc-200 dark:hover:bg-white/10 text-zinc-500 dark:text-zinc-400 transition-colors"
              >
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
              </button>
            </div>

            <div className="p-6 space-y-6 overflow-y-auto max-h-[70vh]">
              
              {/* Profile Details */}
              <div>
                <h4 className="text-sm font-semibold text-zinc-900 dark:text-zinc-100 mb-3 uppercase tracking-wider">Profile</h4>
                <div className="bg-zinc-50 dark:bg-black/40 border border-zinc-200 dark:border-white/5 rounded-xl p-4 flex justify-between items-center">
                  <div>
                    <p className="text-sm text-zinc-600 dark:text-zinc-400 mb-1">Full Name</p>
                    <p className="font-medium text-zinc-900 dark:text-zinc-200 mb-4">{user.name}</p>
                    <p className="text-sm text-zinc-600 dark:text-zinc-400 mb-1">Joined</p>
                    <p className="font-medium text-zinc-900 dark:text-zinc-200">{new Date(user.created_at).toLocaleDateString()}</p>
                  </div>
                </div>
              </div>

              {/* Security Section */}
              <div>
                <h4 className="text-sm font-semibold text-zinc-900 dark:text-zinc-100 mb-3 uppercase tracking-wider">Security</h4>
                <div className="bg-zinc-50 dark:bg-black/40 border border-zinc-200 dark:border-white/5 rounded-xl p-4">
                  {!showPasswordForm ? (
                    <div className="flex justify-between items-center">
                      <div>
                        <p className="font-medium text-zinc-900 dark:text-zinc-200">Change Password</p>
                        <p className="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">Ensure your account uses a secure password.</p>
                      </div>
                      <button 
                        onClick={() => setShowPasswordForm(true)}
                        className="px-4 py-2 bg-zinc-200 dark:bg-white/10 hover:bg-zinc-300 dark:hover:bg-white/20 text-zinc-900 dark:text-white rounded-lg text-sm font-medium transition-colors"
                      >
                        Update
                      </button>
                    </div>
                  ) : (
                    <form onSubmit={handleUpdatePassword} className="space-y-4 animate-in fade-in zoom-in-95 duration-200">
                      <div>
                        <label className="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">Current Password</label>
                        <input type="password" value={currentPassword} onChange={e => setCurrentPassword(e.target.value)} required className="w-full bg-white dark:bg-black/40 border border-zinc-200 dark:border-white/10 rounded-lg px-3 py-2 text-sm text-zinc-900 dark:text-white focus:outline-none focus:border-blue-500" />
                      </div>
                      <div>
                        <label className="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">New Password</label>
                        <input type="password" value={newPassword} onChange={e => setNewPassword(e.target.value)} required className="w-full bg-white dark:bg-black/40 border border-zinc-200 dark:border-white/10 rounded-lg px-3 py-2 text-sm text-zinc-900 dark:text-white focus:outline-none focus:border-blue-500" />
                      </div>
                      <div>
                        <label className="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">Confirm New Password</label>
                        <input type="password" value={newPasswordConfirm} onChange={e => setNewPasswordConfirm(e.target.value)} required className="w-full bg-white dark:bg-black/40 border border-zinc-200 dark:border-white/10 rounded-lg px-3 py-2 text-sm text-zinc-900 dark:text-white focus:outline-none focus:border-blue-500" />
                      </div>
                      <div className="flex gap-2 justify-end pt-2">
                        <button type="button" onClick={() => setShowPasswordForm(false)} className="px-4 py-2 text-zinc-600 dark:text-zinc-400 hover:text-zinc-900 dark:hover:text-white text-sm font-medium transition-colors">Cancel</button>
                        <button type="submit" disabled={updatingPassword} className="px-4 py-2 bg-blue-500 hover:bg-blue-600 text-white rounded-lg text-sm font-medium transition-colors disabled:opacity-50">
                          {updatingPassword ? "Saving..." : "Save Password"}
                        </button>
                      </div>
                    </form>
                  )}
                </div>
              </div>

              {/* Hardware Devices */}
              <div>
                <h4 className="text-sm font-semibold text-zinc-900 dark:text-zinc-100 mb-3 uppercase tracking-wider">Hardware Devices</h4>
                <div className="bg-zinc-50 dark:bg-black/40 border border-zinc-200 dark:border-white/5 rounded-xl p-4">
                  {pairingToken ? (
                    <div className="animate-in fade-in slide-in-from-bottom-2 duration-300">
                      <div className="bg-blue-50 dark:bg-blue-500/10 border border-blue-200 dark:border-blue-500/20 rounded-xl p-4 mb-4">
                        <h5 className="font-semibold text-blue-800 dark:text-blue-300 mb-2">Connect Your Device</h5>
                        <ol className="list-decimal pl-4 text-xs text-blue-700 dark:text-blue-400 space-y-2 mb-4">
                          <li>Turn on your RespiroSync physical device.</li>
                          <li>On your phone, connect to the WiFi network called <strong className="font-semibold">RespiroSync-Setup</strong>.</li>
                          <li>When the setup page appears, enter your home WiFi password and the secret token below.</li>
                        </ol>
                        <div className="bg-white dark:bg-black/40 border border-blue-200 dark:border-blue-500/20 rounded-lg p-3 text-center shadow-inner">
                          <span className="text-3xl font-bold tracking-[0.2em] text-zinc-900 dark:text-white">{pairingToken}</span>
                        </div>
                      </div>
                      <button 
                        onClick={() => {
                          setPairingToken(null);
                          // Manually fetch devices one extra time when clicking Done
                          fetch("/api/devices", {
                            headers: {
                              "Authorization": `Bearer ${localStorage.getItem("auth_token")}`,
                              "Accept": "application/json"
                            }
                          })
                          .then(res => res.json())
                          .then(data => { if(data.devices) setDevices(data.devices); });
                        }} 
                        className="w-full py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition-colors shadow-sm"
                      >
                        Done
                      </button>
                    </div>
                  ) : (
                    <>
                      {devices.length === 0 ? (
                        <div className="text-center py-4 mb-4">
                          <p className="text-sm text-zinc-500 dark:text-zinc-400">No devices connected yet.</p>
                        </div>
                      ) : (
                        <div className="space-y-3 mb-4">
                          {devices.map(device => (
                            <div key={device.id} className="flex justify-between items-center p-3 bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-white/10 rounded-lg">
                              <div>
                                <h5 className="font-medium text-zinc-800 dark:text-zinc-200">{device.name}</h5>
                                <p className="text-xs text-zinc-500 dark:text-zinc-400">Token: {device.device_token}</p>
                              </div>
                              <div className="flex flex-col items-end">
                                {device.status === 'online' ? (
                                  <span className="text-xs text-emerald-600 dark:text-emerald-400 flex items-center gap-1 font-medium bg-emerald-50 dark:bg-emerald-500/10 px-2 py-1 rounded-full">
                                    <span className="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span> Online
                                  </span>
                                ) : (
                                  <span className="text-xs text-amber-600 dark:text-amber-400 flex items-center gap-1 font-medium bg-amber-50 dark:bg-amber-500/10 px-2 py-1 rounded-full">
                                    <span className="w-1.5 h-1.5 rounded-full bg-amber-500"></span> {device.status === 'pending' ? 'Pending Setup' : 'Offline'}
                                  </span>
                                )}
                                <button 
                                  onClick={() => handleDeleteDevice(device.id, device.name)}
                                  className="mt-2 text-[10px] text-zinc-400 hover:text-red-500 transition-colors uppercase tracking-wider font-semibold"
                                >
                                  Remove Device
                                </button>
                              </div>
                            </div>
                          ))}
                        </div>
                      )}
                      <button 
                        onClick={handlePairDevice} 
                        disabled={generatingToken}
                        className="w-full py-2.5 border-2 border-dashed border-zinc-300 dark:border-zinc-700 hover:border-blue-500 dark:hover:border-blue-500 hover:bg-blue-50 dark:hover:bg-blue-500/10 text-zinc-600 dark:text-zinc-400 rounded-lg text-sm font-medium transition-colors disabled:opacity-50"
                      >
                        {generatingToken ? "Generating Secure Token..." : "+ Pair New ESP32 Device"}
                      </button>
                    </>
                  )}
                </div>
              </div>

              {/* Danger Zone */}
              <div>
                <h4 className="text-sm font-semibold text-red-600 dark:text-red-400 mb-3 uppercase tracking-wider flex items-center gap-2">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                  Danger Zone
                </h4>
                <div className="bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/20 rounded-xl p-4">
                  <p className="text-xs text-red-600 dark:text-red-400 mb-4">
                    Permanently delete your account and all associated medical data in accordance with the PDPA Right to Erasure. This action cannot be undone.
                  </p>
                  <button 
                    onClick={handleDeleteAccount}
                    disabled={deletingAccount}
                    className="w-full py-2 bg-red-500 hover:bg-red-600 text-white rounded-lg text-sm font-medium transition-colors disabled:opacity-50 shadow-sm shadow-red-500/20"
                  >
                    {deletingAccount ? "Deleting..." : "Permanently Delete Account"}
                  </button>
                </div>
              </div>

            </div>

            <div className="p-4 border-t border-zinc-200 dark:border-white/10 bg-zinc-50 dark:bg-black/20">
              <button 
                onClick={handleLogout}
                className="w-full py-2.5 bg-zinc-200 dark:bg-white/10 hover:bg-zinc-300 dark:hover:bg-white/20 text-zinc-800 dark:text-zinc-200 rounded-xl text-sm font-medium transition-colors flex items-center justify-center gap-2 shadow-sm"
              >
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
                Sign Out
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Global Toast UI */}
      {toast && (
        <div className="fixed top-6 right-6 z-[120] animate-in slide-in-from-top-5 fade-in duration-300">
          <div className={`flex items-center gap-3 px-4 py-3 rounded-xl shadow-2xl border backdrop-blur-md ${
            toast.type === 'success' 
              ? 'bg-emerald-500/10 border-emerald-500/20 text-emerald-400' 
              : 'bg-red-500/10 border-red-500/20 text-red-400'
          }`}>
            {toast.type === 'success' ? (
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
            ) : (
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
            )}
            <p className="text-sm font-medium">{toast.message}</p>
          </div>
        </div>
      )}

      {/* Custom Confirmation Modal */}
      {confirmAction.isOpen && (
        <div className="fixed inset-0 z-[110] flex items-center justify-center p-4 bg-zinc-900/60 backdrop-blur-sm animate-in fade-in duration-200">
          <div className="bg-white dark:bg-[#12121e] border border-zinc-200 dark:border-white/10 w-full max-w-sm rounded-3xl shadow-2xl p-6 animate-in zoom-in-95 duration-200">
            <div className="flex items-center gap-3 mb-3">
              {confirmAction.isDanger ? (
                <div className="w-10 h-10 rounded-full bg-red-100 dark:bg-red-500/20 flex items-center justify-center text-red-600 dark:text-red-400">
                  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                </div>
              ) : (
                <div className="w-10 h-10 rounded-full bg-blue-100 dark:bg-blue-500/20 flex items-center justify-center text-blue-600 dark:text-blue-400">
                  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                </div>
              )}
              <h3 className={`font-semibold text-lg ${confirmAction.isDanger ? 'text-red-600 dark:text-red-400' : 'text-zinc-900 dark:text-white'}`}>
                {confirmAction.title}
              </h3>
            </div>
            <p className="text-sm text-zinc-600 dark:text-zinc-400 mb-6 pl-13">
              {confirmAction.message}
            </p>
            <div className="flex gap-3 justify-end">
              <button 
                onClick={() => setConfirmAction({...confirmAction, isOpen: false})}
                className="px-4 py-2.5 rounded-xl text-sm font-medium bg-zinc-100 dark:bg-white/5 hover:bg-zinc-200 dark:hover:bg-white/10 text-zinc-700 dark:text-zinc-300 transition-colors"
              >
                Cancel
              </button>
              <button 
                onClick={() => {
                  setConfirmAction({...confirmAction, isOpen: false});
                  confirmAction.onConfirm();
                }}
                className={`px-4 py-2.5 text-sm font-medium rounded-xl text-white transition-colors shadow-sm ${
                  confirmAction.isDanger 
                    ? 'bg-red-500 hover:bg-red-600 shadow-red-500/20' 
                    : 'bg-blue-600 hover:bg-blue-700 shadow-blue-500/20'
                }`}
              >
                Confirm
              </button>
            </div>
          </div>
        </div>
      )}
    </>
  );
}
