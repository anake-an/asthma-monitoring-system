"use client";
import { useRouter } from "next/navigation";

export default function LogoutButton() {
  const router = useRouter();

  const handleLogout = async () => {
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
    router.push("/login");
  };

  return (
    <button 
      onClick={handleLogout}
      className="flex items-center gap-2 bg-red-500/10 hover:bg-red-500/20 transition-colors border border-red-500/20 rounded px-2 md:px-3 py-1.5 ml-0 md:ml-2"
    >
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#f87171" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
        <polyline points="16 17 21 12 16 7"></polyline>
        <line x1="21" y1="12" x2="9" y2="12"></line>
      </svg>
      <span className="hidden sm:inline text-xs font-medium text-red-400">Logout</span>
    </button>
  );
}
