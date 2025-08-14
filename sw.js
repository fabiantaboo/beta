const CACHE_NAME = 'ayuni-v1.0.0';
const urlsToCache = [
  '/',
  '/dashboard',
  '/assets/ayuni.png',
  'https://cdn.tailwindcss.com',
  'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css',
  'https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap'
];

// Install event - cache resources
self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => {
        console.log('Ayuni PWA: Caching app shell');
        return cache.addAll(urlsToCache);
      })
      .catch(err => {
        console.log('Ayuni PWA: Cache failed', err);
      })
  );
});

// Fetch event - serve from cache when offline
self.addEventListener('fetch', event => {
  event.respondWith(
    caches.match(event.request)
      .then(response => {
        // Return cached version or fetch from network
        if (response) {
          return response;
        }
        return fetch(event.request);
      })
      .catch(() => {
        // If both cache and network fail, show offline page
        if (event.request.destination === 'document') {
          return new Response(
            `<!DOCTYPE html>
            <html>
            <head>
              <title>Ayuni - Offline</title>
              <meta name="viewport" content="width=device-width, initial-scale=1.0">
              <style>
                body { 
                  font-family: Inter, sans-serif; 
                  text-align: center; 
                  padding: 50px;
                  background: #10142B;
                  color: white;
                }
                .logo { color: #39D2DF; font-size: 2em; margin-bottom: 20px; }
              </style>
            </head>
            <body>
              <div class="logo">🤖 Ayuni</div>
              <h1>You're offline</h1>
              <p>Please check your internet connection and try again.</p>
              <button onclick="window.location.reload()">Retry</button>
            </body>
            </html>`,
            {
              headers: {
                'Content-Type': 'text/html'
              }
            }
          );
        }
      })
  );
});

// Activate event - clean up old caches
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(cacheNames => {
      return Promise.all(
        cacheNames.map(cacheName => {
          if (cacheName !== CACHE_NAME) {
            console.log('Ayuni PWA: Deleting old cache', cacheName);
            return caches.delete(cacheName);
          }
        })
      );
    })
  );
});