// =============================================
// Q-Bazaar — Service Worker (Offline Support)
// =============================================

const CACHE_NAME = 'qbazaar-v1';
const STATIC_ASSETS = [
  '/QR BAZAR/',
  '/QR BAZAR/index.php',
  '/QR BAZAR/assets/css/style.css',
  '/QR BAZAR/assets/js/app.js',
  '/QR BAZAR/assets/js/offline.js',
  '/QR BAZAR/assets/js/qr-generator.js',
  '/QR BAZAR/assets/js/qr-scanner.js',
  '/QR BAZAR/assets/js/charts.js',
  '/QR BAZAR/assets/js/payment.js',
  '/QR BAZAR/assets/js/ai-search.js',
  'https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&family=Inter:wght@300;400;500;600&display=swap',
  'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js',
  'https://cdn.jsdelivr.net/npm/chart.js',
];

// Install: cache static assets
self.addEventListener('install', (e) => {
  e.waitUntil(
    caches.open(CACHE_NAME).then(cache => {
      return cache.addAll(STATIC_ASSETS.filter(url => !url.startsWith('https://fonts')));
    })
  );
  self.skipWaiting();
});

// Activate: clear old caches
self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k)))
    )
  );
  self.clients.claim();
});

// Fetch: Network-first for API, Cache-first for static
self.addEventListener('fetch', (e) => {
  const url = new URL(e.request.url);

  // Skip non-GET, cross-origin except CDNs
  if (e.request.method !== 'GET') return;

  // API routes: network-first
  if (url.pathname.includes('/api/')) {
    e.respondWith(
      fetch(e.request)
        .then(res => {
          // Cache successful API responses
          if (res.ok) {
            const clone = res.clone();
            caches.open(CACHE_NAME).then(c => c.put(e.request, clone));
          }
          return res;
        })
        .catch(() => caches.match(e.request))
    );
    return;
  }

  // Vendor pages: Network-first with cache fallback
  if (url.pathname.includes('/buyer/vendor.php')) {
    e.respondWith(
      fetch(e.request)
        .then(res => {
          if (res.ok) {
            const clone = res.clone();
            caches.open(CACHE_NAME).then(c => c.put(e.request, clone));
          }
          return res;
        })
        .catch(() => caches.match(e.request))
    );
    return;
  }

  // Static assets: Cache-first
  e.respondWith(
    caches.match(e.request).then(cached => {
      if (cached) return cached;
      return fetch(e.request).then(res => {
        if (res.ok) {
          const clone = res.clone();
          caches.open(CACHE_NAME).then(c => c.put(e.request, clone));
        }
        return res;
      });
    })
  );
});

// Background sync for pending transactions
self.addEventListener('sync', (e) => {
  if (e.tag === 'sync-transactions') {
    e.waitUntil(syncPendingTransactions());
  }
});

async function syncPendingTransactions() {
  // Notify clients to sync
  const clients = await self.clients.matchAll();
  clients.forEach(client => client.postMessage({ type: 'SYNC_TRANSACTIONS' }));
}
