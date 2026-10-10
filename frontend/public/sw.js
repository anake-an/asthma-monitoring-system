// RespiroSync service worker: shows cough-alert pushes and opens the dashboard on the alerting room
// when one is tapped. Registered in app/layout.tsx; subscriptions are made in lib/push.ts.

self.addEventListener('install', () => {
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', (event) => {
  event.respondWith(fetch(event.request));
});

self.addEventListener('push', (event) => {
  if (!event.data) return;
  const data = event.data.json();
  event.waitUntil(
    self.registration.showNotification(data.title || 'RespiroSync', {
      body: data.body,
      icon: '/icon.jpg',
      badge: '/icon.jpg',
      tag: data.tag,             // a newer alert for the same room replaces the older one
      renotify: Boolean(data.tag),
      vibrate: data.vibrate || [100, 50, 100],
      data: { url: (data.data && data.data.url) || '/' },
    })
  );
});

// Tapping the notification: focus an open dashboard tab (and show that room), or open one.
self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const url = new URL(event.notification.data && event.notification.data.url || '/', self.location.origin).href;
  event.waitUntil((async () => {
    const tabs = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const tab of tabs) {
      if (new URL(tab.url).origin === self.location.origin) {
        await tab.focus();
        return tab.navigate(url);
      }
    }
    return self.clients.openWindow(url);
  })());
});
