"use client";

import { useState, useEffect } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import Head from "next/head";

export default function ResetPassword() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const token = searchParams.get("token");
  const emailQuery = searchParams.get("email");

  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [passwordConfirmation, setPasswordConfirmation] = useState("");
  const [loading, setLoading] = useState(false);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");

  useEffect(() => {
    if (emailQuery) setEmail(emailQuery);
  }, [emailQuery]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (password !== passwordConfirmation) {
      setError("Passwords do not match");
      return;
    }
    
    setLoading(true);
    setMessage("");
    setError("");

    try {
      const res = await fetch("/api/reset-password", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ email, token, password, password_confirmation: passwordConfirmation }),
      });

      const data = await res.json();

      if (!res.ok) {
        throw new Error(data.message || "Failed to process request");
      }

      setMessage("Password successfully reset! Redirecting to login...");
      setTimeout(() => router.push("/login"), 3000);
    } catch (err: any) {
      setError(err.message);
    } finally {
      setLoading(false);
    }
  };

  if (!token) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gray-50 dark:bg-[#09090b] text-red-500">
        Invalid or missing reset token.
      </div>
    );
  }

  return (
    <div className="min-h-screen flex items-center justify-center bg-gray-50 dark:bg-[#09090b] text-zinc-900 dark:text-zinc-100 p-4 font-sans selection:bg-blue-500/30">
      <Head>
        <title>Set New Password - RespiroSync</title>
      </Head>

      <div className="w-full max-w-md bg-white dark:bg-[#12121e] border border-zinc-200 dark:border-white/5 rounded-[2rem] p-8 md:p-10 shadow-2xl relative z-10">
        <div className="flex flex-col items-center mb-10">
          <div className="w-16 h-16 bg-green-500/10 border border-green-500/20 rounded-2xl flex items-center justify-center mb-6 shadow-inner shadow-green-500/20 drop-shadow-xl">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" className="text-green-500">
              <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
            </svg>
          </div>
          <h1 className="text-2xl font-semibold tracking-tight">New Password</h1>
          <p className="text-sm text-zinc-500 dark:text-zinc-400 mt-2 text-center">
            Create a strong new password for your account.
          </p>
        </div>

        {message && (
          <div className="mb-6 p-4 bg-green-500/10 border border-green-500/20 rounded-xl flex items-center gap-3 text-green-600 dark:text-green-400 text-sm">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
            {message}
          </div>
        )}

        {error && (
          <div className="mb-6 p-4 bg-red-500/10 border border-red-500/20 rounded-xl flex items-center gap-3 text-red-600 dark:text-red-400 text-sm">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
            {error}
          </div>
        )}

        <form onSubmit={handleSubmit} className="space-y-5">
          <div className="space-y-1.5">
            <label className="text-xs font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-widest ml-1">New Password</label>
            <input
              type="password"
              required
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              className="w-full bg-zinc-50 dark:bg-white/5 border border-zinc-200 dark:border-white/10 rounded-xl py-3.5 px-4 focus:outline-none focus:ring-2 focus:ring-green-500/50 transition-all text-sm"
              placeholder="••••••••"
            />
          </div>

          <div className="space-y-1.5">
            <label className="text-xs font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-widest ml-1">Confirm Password</label>
            <input
              type="password"
              required
              value={passwordConfirmation}
              onChange={(e) => setPasswordConfirmation(e.target.value)}
              className="w-full bg-zinc-50 dark:bg-white/5 border border-zinc-200 dark:border-white/10 rounded-xl py-3.5 px-4 focus:outline-none focus:ring-2 focus:ring-green-500/50 transition-all text-sm"
              placeholder="••••••••"
            />
          </div>

          <button
            type="submit"
            disabled={loading}
            className="w-full bg-green-600 hover:bg-green-500 text-white font-medium py-3.5 px-4 rounded-xl transition-all disabled:opacity-50 mt-4 shadow-lg shadow-green-500/20"
          >
            {loading ? "Saving..." : "Save Password"}
          </button>
        </form>
      </div>
    </div>
  );
}
