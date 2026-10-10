// Web Push for alerts (coughs, readings over a limit) on this browser/phone. The service worker (public/sw.js) shows them;
// the backend sends them with its VAPID key to every subscription of every member who gets alerts.
// iPhone/iPad: only works after "Add to Home Screen" and opening RespiroSync from there (iOS 16.4+).

import { authHeaders } from "@/lib/rooms";

export type PushState = "unsupported" | "needs-home-screen" | "blocked" | "off" | "on";

function urlBase64ToUint8Array(base64String: string): BufferSource {
  const padding = "=".repeat((4 - (base64String.length % 4)) % 4);
  const base64 = (base64String + padding).replace(/-/g, "+").replace(/_/g, "/");
  const raw = window.atob(base64);
  return Uint8Array.from(raw, c => c.charCodeAt(0)) as BufferSource;
}

function isIos(): boolean {
  return /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === "MacIntel" && navigator.maxTouchPoints > 1);
}

function isStandalone(): boolean {
  return window.matchMedia("(display-mode: standalone)").matches || (navigator as unknown as { standalone?: boolean }).standalone === true;
}

export async function pushState(): Promise<PushState> {
  if (typeof window === "undefined") return "unsupported";
  if (!("serviceWorker" in navigator) || !("PushManager" in window) || !("Notification" in window)) {
    return isIos() && !isStandalone() ? "needs-home-screen" : "unsupported";
  }
  if (Notification.permission === "denied") return "blocked";
  const registration = await navigator.serviceWorker.getRegistration(); // never hangs, unlike .ready
  return registration && (await registration.pushManager.getSubscription()) ? "on" : "off";
}

/** Ask permission, subscribe this browser and register it with the backend. */
export async function enablePush(): Promise<PushState> {
  const permission = await Notification.requestPermission();
  if (permission !== "granted") return permission === "denied" ? "blocked" : "off";

  const keyRes = await fetch("/api/vapid-public-key", { headers: authHeaders() });
  const { key } = await keyRes.json();
  if (!key) throw new Error("Push is not configured on the server (no VAPID key).");

  const registration = await navigator.serviceWorker.ready;
  const subscription = (await registration.pushManager.getSubscription())
    ?? (await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: urlBase64ToUint8Array(key) }));

  const res = await fetch("/api/push-subscribe", {
    method: "POST",
    headers: { ...authHeaders(), "Content-Type": "application/json" },
    body: JSON.stringify(subscription.toJSON()),
  });
  if (!res.ok) throw new Error("The server did not accept this browser's subscription.");
  return "on";
}

/** Stop pushes to this browser, on the server and in the browser. */
export async function disablePush(): Promise<PushState> {
  if (!("serviceWorker" in navigator)) return "unsupported";
  // getRegistration(), not .ready: .ready never resolves without a service worker and would hang sign-out.
  const registration = await navigator.serviceWorker.getRegistration();
  const subscription = registration ? await registration.pushManager.getSubscription() : null;
  if (subscription) {
    await fetch("/api/push-subscribe", {
      method: "DELETE",
      headers: { ...authHeaders(), "Content-Type": "application/json" },
      body: JSON.stringify({ endpoint: subscription.endpoint }),
    }).catch(() => {});
    await subscription.unsubscribe();
  }
  return "off";
}
