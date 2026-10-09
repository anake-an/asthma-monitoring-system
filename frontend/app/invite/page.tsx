"use client";
// Opened from an invitation email (/invite?token=...). Signed out: sign in or register first and
// come back here. Signed in: shows who shared which child with which role, and accepts it.
// The backend only accepts it for the account with the invited email.

import { useEffect, useState } from "react";
import Link from "next/link";
import { rememberAfterLogin } from "@/lib/afterLogin";

type Invite = { child: string; invited_by: string; role: string; email: string; expires_at: string; email_matches: boolean };

const ROLE_TEXT: Record<string, string> = {
  owner: "an owner: full control, including alert limits, rooms and sharing",
  caregiver: "a caregiver: see readings, history and reports, get alerts, log inhaler doses",
  viewer: "a viewer: see readings, history and reports",
};

export default function InvitePage() {
  const [token, setToken] = useState<string | null>(null);
  const [state, setState] = useState<"loading" | "signed-out" | "invalid" | "ready" | "accepting" | "done">("loading");
  const [invite, setInvite] = useState<Invite | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const t = new URLSearchParams(window.location.search).get("token");
    setToken(t);
    if (!t) {
      setState("invalid");
      return;
    }
    const auth = localStorage.getItem("auth_token");
    if (!auth) {
      rememberAfterLogin(`/invite?token=${t}`);
      setState("signed-out");
      return;
    }
    fetch(`/api/invites/${encodeURIComponent(t)}`, { headers: { Authorization: `Bearer ${auth}`, Accept: "application/json" } })
      .then(async res => {
        if (res.status === 401) {
          rememberAfterLogin(`/invite?token=${t}`);
          setState("signed-out");
          return;
        }
        if (!res.ok) {
          setState("invalid");
          return;
        }
        setInvite(await res.json());
        setState("ready");
      })
      .catch(() => setState("invalid"));
  }, []);

  const accept = async () => {
    setState("accepting");
    setError(null);
    try {
      const res = await fetch("/api/invites/accept", {
        method: "POST",
        headers: { Authorization: `Bearer ${localStorage.getItem("auth_token")}`, Accept: "application/json", "Content-Type": "application/json" },
        body: JSON.stringify({ token }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) {
        setError(data.message || "Could not accept the invitation.");
        setState("ready");
        return;
      }
      setState("done");
      setTimeout(() => { window.location.href = "/"; }, 1200);
    } catch {
      setError("Error connecting to server");
      setState("ready");
    }
  };

  const card = "max-w-md w-full bg-white dark:bg-[#12121e] border border-zinc-200 dark:border-white/10 rounded-3xl shadow-2xl p-8 text-center";
  const button = "inline-block w-full py-3 rounded-xl text-sm font-medium transition-colors";

  return (
    <main className="min-h-screen flex items-center justify-center p-4 bg-gray-50 dark:bg-[#09090b] text-zinc-900 dark:text-zinc-100">
      <div className={card}>
        <img src="/logo.jpg?v=3" alt="RespiroSync" className="w-14 h-14 rounded-xl mx-auto mb-5" />

        {state === "loading" && <p className="text-sm text-zinc-500">Checking the invitation...</p>}

        {state === "invalid" && (
          <>
            <h1 className="text-xl font-semibold mb-2">Invitation not valid</h1>
            <p className="text-sm text-zinc-600 dark:text-zinc-400 mb-6">This link is invalid, was already used, or has expired. Ask for a new invitation.</p>
            <Link href="/" className={`${button} bg-blue-600 hover:bg-blue-500 text-white`}>Go to the dashboard</Link>
          </>
        )}

        {state === "signed-out" && (
          <>
            <h1 className="text-xl font-semibold mb-2">You have been invited</h1>
            <p className="text-sm text-zinc-600 dark:text-zinc-400 mb-6">Sign in, or create an account, with the email address the invitation was sent to. You will come back here to accept it.</p>
            <div className="space-y-3">
              <Link href="/login" className={`${button} bg-blue-600 hover:bg-blue-500 text-white`}>Sign in</Link>
              <Link href="/register" className={`${button} bg-zinc-100 dark:bg-white/5 hover:bg-zinc-200 dark:hover:bg-white/10`}>Create an account</Link>
            </div>
          </>
        )}

        {(state === "ready" || state === "accepting") && invite && (
          <>
            <h1 className="text-xl font-semibold mb-2">{invite.invited_by} shared {invite.child} with you</h1>
            <p className="text-sm text-zinc-600 dark:text-zinc-400 mb-6">You would join as {ROLE_TEXT[invite.role] ?? invite.role}.</p>
            {invite.email_matches ? (
              <button onClick={accept} disabled={state === "accepting"} className={`${button} bg-blue-600 hover:bg-blue-500 text-white disabled:opacity-50`}>
                {state === "accepting" ? "Accepting..." : `Accept and see ${invite.child}`}
              </button>
            ) : (
              <p className="text-sm text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/20 rounded-xl p-3">
                This invitation is for <strong>{invite.email}</strong>, but you are signed in with another account. Sign out and sign in with {invite.email} to accept it.
              </p>
            )}
            {error && <p className="text-sm text-red-500 mt-4">{error}</p>}
            <p className="text-[11px] text-zinc-500 mt-6">Expires {new Date(invite.expires_at).toLocaleDateString()}. If you don&apos;t know {invite.invited_by}, just close this page.</p>
          </>
        )}

        {state === "done" && invite && (
          <>
            <h1 className="text-xl font-semibold mb-2">Done</h1>
            <p className="text-sm text-zinc-600 dark:text-zinc-400">You can now see {invite.child}. Opening the dashboard...</p>
          </>
        )}
      </div>
    </main>
  );
}
