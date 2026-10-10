"use client";
import { useState } from "react";
import { useRouter } from "next/navigation";

export default function LogoutButton({ className = "" }: { className?: string }) {
  const router = useRouter();
  const [loading, setLoading] = useState(false);

  const handleLogout = async () => {
    setLoading(true);
    try {
      if ('serviceWorker' in navigator) {
        const registration = await navigator.serviceWorker.ready;
        const subscription = await registration.pushManager.getSubscription();
        if (subscription) {
          await subscription.unsubscribe();
        }
      }

      const token = localStorage.getItem("auth_token");
      if (token) {
        await fetch("/api/logout", {
          method: "POST",
          headers: { "Authorization": `Bearer ${token}` }
        });
      }
    } catch (e) {}

    localStorage.removeItem("auth_token");
    setTimeout(() => {
      router.push("/login");
    }, 300);
  };

  return (
    <button 
      onClick={handleLogout}
      disabled={loading}
      className={`flex items-center justify-center gap-2 h-10 transition-colors border rounded-full px-4 disabled:opacity-70 ${className} ${
        loading 
          ? 'bg-red-500/20 border-red-500/30' 
          : 'bg-red-500/10 hover:bg-red-500/20 border-red-500/20'
      }`}
    >
      {loading ? (
        <svg className="animate-spin h-3.5 w-3.5 text-red-400" fill="none" viewBox="0 0 24 24">
          <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
          <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
        </svg>
      ) : (
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#f87171" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
          <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
          <polyline points="16 17 21 12 16 7"></polyline>
          <line x1="21" y1="12" x2="9" y2="12"></line>
        </svg>
      )}
      <span className="hidden sm:inline text-xs font-medium text-red-400">
        {loading ? 'Logging out...' : 'Logout'}
      </span>
    </button>
  );
}
