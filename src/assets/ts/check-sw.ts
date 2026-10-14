// Service worker of the installed entrance check (served as /admin/kontrola/sw.js, scope /admin/kontrola):
// keeps the app (page, scripts, styles, fonts, icons, QR scanner) so that it opens without a signal.
// The API is never cached: the app itself works from the downloaded data in its offline mode.

interface SwExtendableEvent extends Event {
  waitUntil(promise: Promise<unknown>): void;
}

interface SwFetchEvent extends SwExtendableEvent {
  request: Request;
  respondWith(response: Promise<Response> | Response): void;
}

interface SwGlobal {
  registration: { scope: string };
  location: Location;
  skipWaiting(): Promise<void>;
  clients: { claim(): Promise<void> };
  addEventListener(type: 'install' | 'activate', listener: (event: SwExtendableEvent) => void): void;
  addEventListener(type: 'fetch', listener: (event: SwFetchEvent) => void): void;
}

const sw = self as unknown as SwGlobal;
const CacheName = 'kontrola-v1';
/** Static files the app uses (versioned by ?v= in their URLs). */
const StaticPath = /^\/(build|fonts|img)\/|\.webmanifest$/;

const scopeUrl = () => new URL(sw.registration.scope);
/** The page is cached under the scope URL itself (/admin/kontrola), whatever code the QR link carried. */
const pageKey = () => scopeUrl().href;

sw.addEventListener('install', (event) => {
  event.waitUntil(
    (async () => {
      const cache = await caches.open(CacheName);
      const response = await fetch(pageKey(), { credentials: 'same-origin' });
      if (response.ok && !response.redirected) {
        const html = await response.clone().text();
        await cache.put(pageKey(), response);
        // Everything the page links to: scripts, styles, fonts, icons, the manifest.
        const assets = [...html.matchAll(/(?:src|href)="([^"]+)"/g)]
          .map((m) => new URL(m[1] ?? '', pageKey()))
          .filter((url) => url.origin === sw.location.origin && StaticPath.test(url.pathname))
          .map((url) => url.href);
        await cache.addAll([...new Set(assets)]).catch(() => undefined);
      }
      await sw.skipWaiting();
    })(),
  );
});

sw.addEventListener('activate', (event) => {
  event.waitUntil(
    (async () => {
      for (const name of await caches.keys()) {
        if (name !== CacheName) {
          await caches.delete(name);
        }
      }
      await sw.clients.claim();
    })(),
  );
});

sw.addEventListener('fetch', (event) => {
  const request = event.request;
  const url = new URL(request.url);
  const scope = scopeUrl().pathname.replace(/\/$/, '');
  if (request.method !== 'GET' || url.origin !== sw.location.origin || url.pathname.startsWith(`${scope}/api/`)) {
    return; // the API and all changes go to the network as they are
  }

  if (request.mode === 'navigate' && url.pathname.startsWith(scope)) {
    // The page: fresh from the network (keeps the login and the CSRF token current), offline from the cache.
    event.respondWith(
      (async () => {
        const cache = await caches.open(CacheName);
        try {
          const response = await fetch(request);
          if (response.ok && !response.redirected && url.pathname === scope) {
            await cache.put(pageKey(), response.clone());
          }
          return response;
        } catch {
          return (await cache.match(pageKey())) ?? Response.error();
        }
      })(),
    );
    return;
  }

  if (StaticPath.test(url.pathname)) {
    // Scripts, styles, fonts, icons, the QR scanner worker: from the cache, filled on first use.
    event.respondWith(
      (async () => {
        const cache = await caches.open(CacheName);
        const cached = await cache.match(request);
        if (cached) {
          return cached;
        }
        const response = await fetch(request);
        if (response.ok) {
          await cache.put(request, response.clone());
        }
        return response;
      })(),
    );
  }
});
