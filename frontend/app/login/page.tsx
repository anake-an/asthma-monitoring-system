"use client";
import { useState, useEffect } from "react";
import { useRouter } from "next/navigation";
import ThemeToggle from "@/components/ThemeToggle";
import { Mail, Lock, Eye, EyeOff } from "lucide-react";
import Link from "next/link";

export default function LoginPage() {
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [showPassword, setShowPassword] = useState(false);
  const [rememberMe, setRememberMe] = useState(false);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);
  const router = useRouter();

  // Load remember me email if exists
  useEffect(() => {
    const savedEmail = localStorage.getItem("respirosync_remembered_email");
    if (savedEmail) {
      setEmail(savedEmail);
      setRememberMe(true);
    }
  }, []);

  const handleLogin = async (e: React.FormEvent) => {
    e.preventDefault();
    setLoading(true);
    setError("");
    let success = false;

    try {
      const res = await fetch("/api/login", {
        method: "POST",
        headers: { 
          "Content-Type": "application/json",
          "Accept": "application/json"
        },
        body: JSON.stringify({ email, password }),
      });

      if (!res.ok) {
        const text = await res.text();
        try {
          const errorData = JSON.parse(text);
          setError(errorData.message || "Invalid credentials");
        } catch (e) {
          setError(`Server Error (${res.status}): Please check backend logs.`);
        }
        return;
      }

      const data = await res.json();

      if (data.access_token) {
        localStorage.setItem("auth_token", data.access_token);
        
        // Handle Remember Me
        if (rememberMe) {
          localStorage.setItem("respirosync_remembered_email", email);
        } else {
          localStorage.removeItem("respirosync_remembered_email");
        }

        success = true;
        // Small artificial delay so the loading spinner doesn't flash off instantly
        setTimeout(() => {
          router.push("/");
        }, 300);
      } else {
        setError(data.message || "Invalid credentials");
      }
    } catch (err) {
      setError("Failed to connect to the server.");
    } finally {
      if (!success) {
        setLoading(false);
      }
    }
  };

  return (
    <div className="min-h-[100dvh] bg-gray-50 dark:bg-[#09090b] flex items-center justify-center p-4 sm:p-6 relative overflow-hidden font-sans">
      
      {/* Floating Theme Toggle in Top Right */}
      <div className="absolute top-6 right-6 z-50">
        <ThemeToggle />
      </div>

      {/* Animated Ambient Glow */}
      <div className="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[600px] h-[600px] bg-blue-600/10 rounded-full blur-[120px] pointer-events-none animate-[pulse_8s_ease-in-out_infinite]"></div>
      <div className="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[300px] h-[300px] bg-emerald-500/10 rounded-full blur-[100px] pointer-events-none animate-[pulse_6s_ease-in-out_infinite_alternate]"></div>

      {/* Main Login Card */}
      <div className="w-full max-w-[420px] backdrop-blur-2xl bg-white/70 dark:bg-white/5 border border-zinc-200 dark:border-white/10 rounded-[32px] shadow-2xl p-8 sm:p-10 relative z-10 animate-in fade-in zoom-in-95 duration-700">
        
        {/* Logo Header */}
        <div className="mb-8 text-center flex flex-col items-center">
          <div className="w-20 h-20 sm:w-24 sm:h-24 mb-4 flex items-center justify-center drop-shadow-2xl">
            <img src="/logo.jpg?v=3" alt="RespiroSync Logo" className="w-full h-full object-contain rounded-[24px] border border-zinc-200 dark:border-white/10" />
          </div>
          <h1 className="text-2xl sm:text-3xl font-semibold tracking-tight text-zinc-900 dark:text-white mb-2">Welcome Back</h1>
          <p className="text-sm text-zinc-500 dark:text-zinc-400 font-light">Secure access to RespiroSync System</p>
        </div>

        {/* Error Alert */}
        {error && (
          <div className="bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/20 text-red-600 dark:text-red-400 text-sm p-4 rounded-2xl mb-6 flex items-start gap-3 animate-in slide-in-from-top-2">
            <div className="mt-0.5">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
            </div>
            <p className="font-medium leading-relaxed">{error}</p>
          </div>
        )}

        <form onSubmit={handleLogin} className="space-y-5">
          
          {/* Email Input */}
          <div>
            <label className="block text-[11px] font-semibold text-zinc-600 dark:text-zinc-400 mb-2 uppercase tracking-widest pl-1">Email Address</label>
            <div className="relative group">
              <div className="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                <Mail className="w-5 h-5 text-zinc-400 group-focus-within:text-blue-500 transition-colors duration-300" />
              </div>
              <input
                type="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                className="w-full bg-zinc-100/80 dark:bg-black/40 border border-zinc-200 dark:border-white/10 rounded-2xl pl-11 pr-5 py-3.5 sm:py-4 text-sm text-zinc-900 dark:text-zinc-100 placeholder:text-zinc-400 dark:placeholder:text-zinc-500 focus:outline-none focus:border-blue-500/50 focus:bg-blue-50/50 dark:focus:bg-blue-500/5 transition-all duration-300 focus:ring-4 focus:ring-blue-500/10"
                placeholder="you@example.com"
                required
              />
            </div>
          </div>

          {/* Password Input */}
          <div>
            <label className="block text-[11px] font-semibold text-zinc-600 dark:text-zinc-400 mb-2 uppercase tracking-widest pl-1">Password</label>
            <div className="relative group">
              <div className="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                <Lock className="w-5 h-5 text-zinc-400 group-focus-within:text-blue-500 transition-colors duration-300" />
              </div>
              <input
                type={showPassword ? "text" : "password"}
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                className="w-full bg-zinc-100/80 dark:bg-black/40 border border-zinc-200 dark:border-white/10 rounded-2xl pl-11 pr-12 py-3.5 sm:py-4 text-sm text-zinc-900 dark:text-zinc-100 placeholder:text-zinc-400 dark:placeholder:text-zinc-500 focus:outline-none focus:border-blue-500/50 focus:bg-blue-50/50 dark:focus:bg-blue-500/5 transition-all duration-300 focus:ring-4 focus:ring-blue-500/10"
                placeholder="••••••••"
                required
              />
              <button 
                type="button"
                onClick={() => setShowPassword(!showPassword)}
                className="absolute inset-y-0 right-0 pr-4 flex items-center text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200 transition-colors"
                aria-label={showPassword ? "Hide password" : "Show password"}
              >
                {showPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
              </button>
            </div>
          </div>

          {/* Remember Me & Forgot Password */}
          <div className="flex items-center justify-between pt-1">
            <label className="flex items-center gap-2 cursor-pointer group">
              <div className="relative flex items-center justify-center w-5 h-5">
                <input 
                  type="checkbox" 
                  className="peer sr-only"
                  checked={rememberMe}
                  onChange={(e) => setRememberMe(e.target.checked)}
                />
                <div className="w-4 h-4 sm:w-5 sm:h-5 rounded-[4px] sm:rounded-md border-2 border-zinc-300 dark:border-zinc-700 bg-transparent peer-checked:bg-blue-500 peer-checked:border-blue-500 transition-all group-hover:border-blue-400"></div>
                <svg className="absolute w-2.5 h-2.5 sm:w-3 sm:h-3 text-white pointer-events-none opacity-0 peer-checked:opacity-100 transition-opacity" viewBox="0 0 14 10" fill="none">
                  <path d="M1 5L5 9L13 1" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"/>
                </svg>
              </div>
              <span className="text-xs sm:text-[13px] text-zinc-600 dark:text-zinc-400 font-medium group-hover:text-zinc-900 dark:group-hover:text-zinc-200 transition-colors select-none">Remember me</span>
            </label>
            <Link href="/forgot-password" className="text-xs sm:text-[13px] text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 font-medium transition-colors">Forgot password?</Link>
          </div>

          {/* Submit Button */}
          <button
            type="submit"
            disabled={loading}
            className="w-full bg-gradient-to-r from-blue-600 to-blue-500 hover:from-blue-500 hover:to-blue-400 text-white font-semibold py-3.5 sm:py-4 rounded-2xl transition-all mt-4 disabled:opacity-70 disabled:cursor-not-allowed shadow-lg shadow-blue-500/25 hover:shadow-blue-500/40 text-sm tracking-wide relative overflow-hidden group"
          >
            {/* Button Shine Effect (Triggers on hover) */}
            <div className="absolute top-0 -inset-full h-full w-1/2 z-5 block transform -skew-x-12 bg-gradient-to-r from-transparent to-white opacity-20 group-hover:animate-shine"></div>
            
            {loading ? (
              <span className="flex items-center justify-center gap-2 relative z-10">
                <svg className="animate-spin h-5 w-5 text-white" fill="none" viewBox="0 0 24 24"><circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle><path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                Authenticating...
              </span>
            ) : (
              <span className="relative z-10">Sign In</span>
            )}
          </button>
          
        </form>

        <div className="mt-6 text-center">
          <p className="text-sm text-zinc-500 dark:text-zinc-400 font-medium">
            Don't have an account?{" "}
            <Link href="/register" className="text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 transition-colors">
              Sign Up
            </Link>
          </p>
        </div>

      </div>
    </div>
  );
}
