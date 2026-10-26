// Service Worker - تتبع مستمر في الخلفية
const CACHE_NAME = 'tracking-v6';
const OFFLINE_QUEUE = 'offline-locations';

// تثبيت السيرفس وركر
self.addEventListener('install', event => {
    self.skipWaiting();
});

self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys =>
            Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k)))
        ).then(() => clients.claim())
    );
});

// استقبال رسائل من التطبيق
self.addEventListener('message', event => {
    if (event.data.type === 'START_TRACKING') {
        startBackgroundTracking(event.data.interval || 60000);
    }
    if (event.data.type === 'STOP_TRACKING') {
        stopBackgroundTracking();
    }
});

let trackingTimer = null;

function startBackgroundTracking(interval) {
    if (trackingTimer) clearInterval(trackingTimer);
    // أرسل موقع فوراً
    sendLocationFromSW();
    // كرر كل دقيقة
    trackingTimer = setInterval(sendLocationFromSW, interval);
}

function stopBackgroundTracking() {
    if (trackingTimer) {
        clearInterval(trackingTimer);
        trackingTimer = null;
    }
}

async function sendLocationFromSW() {
    try {
        // أبلّغ التطبيق يبعت الموقع
        const allClients = await clients.matchAll({ type: 'window' });
        if (allClients.length > 0) {
            allClients.forEach(client => {
                client.postMessage({ type: 'GET_LOCATION' });
            });
        }
    } catch(e) {
        console.log('SW tracking error:', e);
    }
}

// Background Sync - لما يرجع الإنترنت
self.addEventListener('sync', event => {
    if (event.tag === 'sync-locations') {
        event.waitUntil(syncOfflineLocations());
    }
});

async function syncOfflineLocations() {
    try {
        const cache = await caches.open(OFFLINE_QUEUE);
        const keys = await cache.keys();
        for (const key of keys) {
            const response = await cache.match(key);
            const data = await response.json();
            try {
                await fetch('/tracking/api/update_location.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
                await cache.delete(key);
            } catch(e) {}
        }
    } catch(e) {}
}

// Fetch interceptor - حفظ المواقع أوفلاين لما ما في إنترنت
self.addEventListener('fetch', event => {
    if (event.request.url.includes('update_location.php') && event.request.method === 'POST') {
        event.respondWith(
            fetch(event.request.clone()).catch(async () => {
                // حفظ أوفلاين
                const body = await event.request.clone().json();
                const cache = await caches.open(OFFLINE_QUEUE);
                await cache.put(
                    new Request('offline-' + Date.now()),
                    new Response(JSON.stringify(body))
                );
                // طلب sync لما يرجع الإنترنت
                if ('sync' in self.registration) {
                    await self.registration.sync.register('sync-locations');
                }
                return new Response(JSON.stringify({ queued: true }));
            })
        );
    }
});
