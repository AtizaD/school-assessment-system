/**
 * Service Worker for School Assessment System PWA
 * Handles offline caching and background sync
 */

const CACHE_VERSION = 'v1.0.4';
const CACHE_NAME = `assessment-system-${CACHE_VERSION}`;

// Assets to cache on install
const STATIC_ASSETS = [
    '/',
    '/manifest.json',
    '/assets/css/assessment.css',
    '/assets/js/assessment-offline.js',
    '/assets/js/assessment-ui.js',
    '/assets/css/bootstrap.min.css',
    '/assets/js/bootstrap.bundle.min.js',
    // Add more core assets as needed
];

// Install event - cache static assets
self.addEventListener('install', (event) => {
    console.log('[Service Worker] Installing...');

    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => {
                console.log('[Service Worker] Caching static assets');
                return cache.addAll(STATIC_ASSETS);
            })
            .then(() => {
                console.log('[Service Worker] Installation complete');
                return self.skipWaiting(); // Activate immediately
            })
            .catch((error) => {
                console.error('[Service Worker] Installation failed:', error);
            })
    );
});

// Activate event - clean up old caches
self.addEventListener('activate', (event) => {
    console.log('[Service Worker] Activating...');

    event.waitUntil(
        caches.keys()
            .then((cacheNames) => {
                return Promise.all(
                    cacheNames.map((cacheName) => {
                        if (cacheName !== CACHE_NAME) {
                            console.log('[Service Worker] Deleting old cache:', cacheName);
                            return caches.delete(cacheName);
                        }
                    })
                );
            })
            .then(() => {
                console.log('[Service Worker] Activation complete');
                return self.clients.claim(); // Take control immediately
            })
    );
});

// Fetch event - serve from cache with network fallback
self.addEventListener('fetch', (event) => {
    const { request } = event;
    const url = new URL(request.url);

    // Skip non-GET requests
    if (request.method !== 'GET') {
        return;
    }

    // Skip API calls and PHP pages (we want fresh data, never cache)
    if (url.pathname.includes('/api/') || url.pathname.endsWith('.php')) {
        event.respondWith(
            fetch(request, {
                cache: 'no-store'
            })
                .then((networkResponse) => {
                    // NEVER cache PHP pages or API responses to prevent session issues
                    return networkResponse;
                })
                .catch((error) => {
                    console.log('[Service Worker] Network request failed:', error);

                    // Return offline response for API/PHP calls
                    if (url.pathname.includes('/api/')) {
                        return new Response(
                            JSON.stringify({
                                status: 'offline',
                                message: 'You are currently offline. Changes are saved locally.'
                            }),
                            {
                                headers: { 'Content-Type': 'application/json' }
                            }
                        );
                    }

                    // For PHP pages, show offline message with retry button
                    if (request.headers.get('accept')?.includes('text/html')) {
                        return new Response(
                            `<!DOCTYPE html>
                            <html>
                            <head>
                                <meta charset="UTF-8">
                                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                                <title>Offline - School Assessment System</title>
                                <style>
                                    body {
                                        font-family: Arial, sans-serif;
                                        display: flex;
                                        justify-content: center;
                                        align-items: center;
                                        height: 100vh;
                                        margin: 0;
                                        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                                        color: white;
                                        text-align: center;
                                    }
                                    .container {
                                        padding: 2rem;
                                        background: rgba(255, 255, 255, 0.1);
                                        border-radius: 10px;
                                        backdrop-filter: blur(10px);
                                    }
                                    h1 { margin: 0 0 1rem 0; }
                                    p { margin: 0 0 1.5rem 0; }
                                    button {
                                        background: white;
                                        color: #667eea;
                                        border: none;
                                        padding: 0.75rem 2rem;
                                        font-size: 1rem;
                                        border-radius: 5px;
                                        cursor: pointer;
                                        font-weight: bold;
                                    }
                                    button:hover { background: #f0f0f0; }
                                </style>
                            </head>
                            <body>
                                <div class="container">
                                    <h1>Unable to Connect</h1>
                                    <p>Please check your internet connection and try again.</p>
                                    <button onclick="window.location.reload()">Retry</button>
                                </div>
                            </body>
                            </html>`,
                            {
                                headers: {
                                    'Content-Type': 'text/html',
                                    'Cache-Control': 'no-store'
                                }
                            }
                        );
                    }

                    return new Response('Network error', {
                        status: 408,
                        statusText: 'Request Timeout'
                    });
                })
        );
        return;
    }

    // Strategy: Cache First for static assets only (CSS, JS, images)
    event.respondWith(
        caches.match(request)
            .then((cachedResponse) => {
                if (cachedResponse) {
                    return cachedResponse;
                }

                return fetch(request)
                    .then((networkResponse) => {
                        if (networkResponse.ok) {
                            const responseClone = networkResponse.clone();
                            caches.open(CACHE_NAME)
                                .then((cache) => cache.put(request, responseClone));
                        }
                        return networkResponse;
                    })
                    .catch((error) => {
                        console.error('[Service Worker] Fetch failed:', error);
                        return new Response('Network error', {
                            status: 408,
                            statusText: 'Request Timeout'
                        });
                    });
            })
    );
});

// Background sync for pending assessment answers
self.addEventListener('sync', (event) => {
    console.log('[Service Worker] Background sync triggered:', event.tag);

    if (event.tag === 'sync-answers') {
        event.waitUntil(
            syncPendingAnswers()
        );
    }
});

// Helper function to sync pending answers
async function syncPendingAnswers() {
    try {
        // Get all clients (open tabs/windows)
        const clients = await self.clients.matchAll();

        // Notify clients to sync
        clients.forEach(client => {
            client.postMessage({
                type: 'SYNC_ANSWERS',
                timestamp: Date.now()
            });
        });

        console.log('[Service Worker] Sync notification sent to clients');
    } catch (error) {
        console.error('[Service Worker] Sync failed:', error);
    }
}

// Push notification support (for future use)
self.addEventListener('push', (event) => {
    console.log('[Service Worker] Push notification received:', event);

    const options = {
        body: event.data ? event.data.text() : 'New assessment available',
        icon: '/school_system/assets/images/icon-192x192.png',
        badge: '/school_system/assets/images/icon-96x96.png',
        vibrate: [200, 100, 200],
        data: {
            dateOfArrival: Date.now(),
            primaryKey: 1
        }
    };

    event.waitUntil(
        self.registration.showNotification('School Assessment', options)
    );
});

// Notification click handler
self.addEventListener('notificationclick', (event) => {
    console.log('[Service Worker] Notification clicked');

    event.notification.close();

    event.waitUntil(
        clients.openWindow('/student/assessments.php')
    );
});

// Message handler from clients
self.addEventListener('message', (event) => {
    console.log('[Service Worker] Message received:', event.data);

    if (event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }

    if (event.data.type === 'CLEAR_CACHE') {
        event.waitUntil(
            caches.delete(CACHE_NAME)
                .then(() => {
                    console.log('[Service Worker] Cache cleared');
                    event.ports[0].postMessage({ success: true });
                })
        );
    }
});
