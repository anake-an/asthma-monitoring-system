"use client";
import { useState } from "react";
import { useRouter } from "next/navigation";
import ThemeToggle from "@/components/ThemeToggle";
import { Mail, Lock, User as UserIcon, Eye, EyeOff, ShieldCheck } from "lucide-react";
import Link from "next/link";

export default function RegisterPage() {
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [passwordConfirm, setPasswordConfirm] = useState("");
  
  const [showPassword, setShowPassword] = useState(false);
  const [acceptedTerms, setAcceptedTerms] = useState(false);
  
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);
  const router = useRouter();

  const handleRegister = async (e: React.FormEvent) => {
    e.preventDefault();
    
    if (!acceptedTerms) {
      setError("You must accept the Privacy Policy to use RespiroSync.");
      return;
    }

    if (password !== passwordConfirm) {
      setError("Passwords do not match.");
      return;
    }

    setLoading(true);
    setError("");
    let success = false;

    try {
      const res = await fetch("/api/register", {
        method: "POST",
        headers: { 
          "Content-Type": "application/json",
          "Accept": "application/json"
        },
        body: JSON.stringify({ 
          name, 
          email, 
          password,
          password_confirmation: passwordConfirm 
        }),
      });

      const data = await res.json();

      if (!res.ok) {
        setError(data.message || "Registration failed. Please try again.");
        return;
      }

      if (data.access_token) {
        localStorage.setItem("auth_token", data.access_token);
        success = true;
        // Small artificial delay so the loading spinner doesn't flash off instantly
        setTimeout(() => {
          router.push("/");
        }, 300);
      } else {
        setError("Account created, but failed to automatically log in.");
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
      <div className="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[700px] h-[700px] bg-indigo-600/10 rounded-full blur-[140px] pointer-events-none animate-[pulse_10s_ease-in-out_infinite]"></div>
      <div className="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[400px] h-[400px] bg-blue-500/10 rounded-full blur-[100px] pointer-events-none animate-[pulse_7s_ease-in-out_infinite_alternate]"></div>

      {/* Main Register Card */}
      <div className="w-full max-w-[460px] backdrop-blur-2xl bg-white/70 dark:bg-white/5 border border-zinc-200 dark:border-white/10 rounded-[32px] shadow-2xl p-8 sm:p-10 relative z-10 animate-in fade-in zoom-in-95 duration-700">
        
        {/* Header */}
        <div className="mb-8 text-center flex flex-col items-center">
          <div className="w-16 h-16 sm:w-20 sm:h-20 mb-4 flex items-center justify-center drop-shadow-2xl">
            <img src="/logo.jpg?v=3" alt="RespiroSync Logo" className="w-full h-full object-contain rounded-[20px] border border-zinc-200 dark:border-white/10" />
          </div>
          <h1 className="text-2xl sm:text-3xl font-semibold tracking-tight text-zinc-900 dark:text-white mb-2">Create Account</h1>
          <p className="text-sm text-zinc-500 dark:text-zinc-400 font-light">Join the RespiroSync medical network</p>
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

        <form onSubmit={handleRegister} className="space-y-4">
          
          {/* Full Name */}
          <div>
            <label className="block text-[11px] font-semibold text-zinc-600 dark:text-zinc-400 mb-2 uppercase tracking-widest pl-1">Full Name</label>
            <div className="relative group">
              <div className="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                <UserIcon className="w-5 h-5 text-zinc-400 group-focus-within:text-blue-500 transition-colors duration-300" />
              </div>
              <input
                type="text"
                value={name}
                onChange={(e) => setName(e.target.value)}
                className="w-full bg-zinc-100/80 dark:bg-black/40 border border-zinc-200 dark:border-white/10 rounded-2xl pl-11 pr-5 py-3.5 text-sm text-zinc-900 dark:text-zinc-100 placeholder:text-zinc-400 dark:placeholder:text-zinc-500 focus:outline-none focus:border-blue-500/50 focus:bg-blue-50/50 dark:focus:bg-blue-500/5 transition-all duration-300 focus:ring-4 focus:ring-blue-500/10"
                placeholder="John Doe"
                required
              />
            </div>
          </div>

          {/* Email */}
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
                className="w-full bg-zinc-100/80 dark:bg-black/40 border border-zinc-200 dark:border-white/10 rounded-2xl pl-11 pr-5 py-3.5 text-sm text-zinc-900 dark:text-zinc-100 placeholder:text-zinc-400 dark:placeholder:text-zinc-500 focus:outline-none focus:border-blue-500/50 focus:bg-blue-50/50 dark:focus:bg-blue-500/5 transition-all duration-300 focus:ring-4 focus:ring-blue-500/10"
                placeholder="hello@example.com"
                required
              />
            </div>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            {/* Password */}
            <div>
              <label className="block text-[11px] font-semibold text-zinc-600 dark:text-zinc-400 mb-2 uppercase tracking-widest pl-1">Password</label>
              <div className="relative group">
                <div className="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none">
                  <Lock className="w-4 h-4 text-zinc-400 group-focus-within:text-blue-500 transition-colors duration-300" />
                </div>
                <input
                  type={showPassword ? "text" : "password"}
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  className="w-full bg-zinc-100/80 dark:bg-black/40 border border-zinc-200 dark:border-white/10 rounded-2xl pl-10 pr-9 py-3.5 text-sm text-zinc-900 dark:text-zinc-100 placeholder:text-zinc-400 dark:placeholder:text-zinc-500 focus:outline-none focus:border-blue-500/50 focus:bg-blue-50/50 dark:focus:bg-blue-500/5 transition-all duration-300 focus:ring-4 focus:ring-blue-500/10"
                  placeholder="••••••••"
                  required
                />
              </div>
            </div>

            {/* Confirm Password */}
            <div>
              <label className="block text-[11px] font-semibold text-zinc-600 dark:text-zinc-400 mb-2 uppercase tracking-widest pl-1">Confirm</label>
              <div className="relative group">
                <div className="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none">
                  <ShieldCheck className="w-4 h-4 text-zinc-400 group-focus-within:text-blue-500 transition-colors duration-300" />
                </div>
                <input
                  type={showPassword ? "text" : "password"}
                  value={passwordConfirm}
                  onChange={(e) => setPasswordConfirm(e.target.value)}
                  className="w-full bg-zinc-100/80 dark:bg-black/40 border border-zinc-200 dark:border-white/10 rounded-2xl pl-10 pr-9 py-3.5 text-sm text-zinc-900 dark:text-zinc-100 placeholder:text-zinc-400 dark:placeholder:text-zinc-500 focus:outline-none focus:border-blue-500/50 focus:bg-blue-50/50 dark:focus:bg-blue-500/5 transition-all duration-300 focus:ring-4 focus:ring-blue-500/10"
                  placeholder="••••••••"
                  required
                />
                <button 
                  type="button"
                  onClick={() => setShowPassword(!showPassword)}
                  className="absolute inset-y-0 right-0 pr-3 flex items-center text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200 transition-colors"
                >
                  {showPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                </button>
              </div>
            </div>
          </div>

          {/* Privacy Consent */}
          <div className="pt-2 pb-1">
            <label className="flex items-start gap-3 cursor-pointer group">
              <div className="relative flex items-center justify-center w-5 h-5 shrink-0 mt-0.5">
                <input 
                  type="checkbox" 
                  className="peer sr-only"
                  checked={acceptedTerms}
                  onChange={(e) => setAcceptedTerms(e.target.checked)}
                />
                <div className="w-4 h-4 sm:w-5 sm:h-5 rounded-[4px] sm:rounded-md border-2 border-zinc-300 dark:border-zinc-700 bg-transparent peer-checked:bg-blue-500 peer-checked:border-blue-500 transition-all group-hover:border-blue-400"></div>
                <svg className="absolute w-2.5 h-2.5 sm:w-3 sm:h-3 text-white pointer-events-none opacity-0 peer-checked:opacity-100 transition-opacity" viewBox="0 0 14 10" fill="none">
                  <path d="M1 5L5 9L13 1" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"/>
                </svg>
              </div>
              <span className="text-[12px] sm:text-[13px] text-zinc-500 dark:text-zinc-400 font-medium leading-snug group-hover:text-zinc-700 dark:group-hover:text-zinc-300 transition-colors select-none">
                I agree to the <Link href="/privacy" className="text-blue-500 dark:text-blue-400 hover:underline relative z-20">Privacy Policy (PDPA)</Link> and consent to my telemetry and medical data being processed by RespiroSync AI.
              </span>
            </label>
          </div>

          {/* Submit Button */}
          <button
            type="submit"
            disabled={loading}
            className="w-full bg-gradient-to-r from-blue-600 to-indigo-500 hover:from-blue-500 hover:to-indigo-400 text-white font-semibold py-3.5 sm:py-4 rounded-2xl transition-all mt-4 disabled:opacity-70 disabled:cursor-not-allowed shadow-lg shadow-blue-500/25 hover:shadow-blue-500/40 text-sm tracking-wide relative overflow-hidden group"
          >
            <div className="absolute top-0 -inset-full h-full w-1/2 z-5 block transform -skew-x-12 bg-gradient-to-r from-transparent to-white opacity-20 group-hover:animate-shine"></div>
            
            {loading ? (
              <span className="flex items-center justify-center gap-2 relative z-10">
                <svg className="animate-spin h-5 w-5 text-white" fill="none" viewBox="0 0 24 24"><circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle><path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                Creating Account...
              </span>
            ) : (
              <span className="relative z-10">Create Account</span>
            )}
          </button>
        </form>

        <div className="mt-6 text-center">
          <p className="text-sm text-zinc-500 dark:text-zinc-400 font-medium">
            Already have an account?{" "}
            <Link href="/login" className="text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 transition-colors">
              Sign In
            </Link>
          </p>
        </div>

      </div>
    </div>
  );
}
