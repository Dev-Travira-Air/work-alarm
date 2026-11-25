const CACHE_NAME = "jeda-pwa-v8";
const APP_SHELL = [
    "./",
    "./index.php",
    "./style.css",
    "./app.js",
    "./manifest.webmanifest",
    "./app-icon.svg",
    "./logo-travira.png"
];

self.addEventListener("install", (event) => {
    event.waitUntil(caches.open(CACHE_NAME).then((cache) => cache.addAll(APP_SHELL)));
    self.skipWaiting();
});

self.addEventListener("activate", (event) => {
    event.waitUntil(
        caches.keys().then((keys) => Promise.all(
            keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key))
        ))
    );
    self.clients.claim();
});

self.addEventListener("fetch", (event) => {
    if (event.request.method !== "GET") return;
    const requestUrl = new URL(event.request.url);
    if (requestUrl.origin !== self.location.origin) return;

    if (requestUrl.pathname.endsWith("/push-public-key.php")) {
        event.respondWith(fetch(event.request, { cache: "no-store" }));
        return;
    }

    if (requestUrl.pathname.endsWith("/") || requestUrl.pathname.endsWith("/index.php")) {
        event.respondWith(
            fetch(event.request, { cache: "no-cache" }).then((response) => {
                if (response.ok && response.type === "basic") {
                    event.waitUntil(
                        caches.open(CACHE_NAME).then((cache) => cache.put(event.request, response.clone()))
                    );
                }
                return response;
            }).catch((error) => caches.match(event.request).then((cached) => {
                if (cached) return cached;
                throw error;
            }))
        );
        return;
    }

    event.respondWith(
        caches.match(event.request).then((cached) => cached || fetch(event.request).then((response) => {
            if (response.ok && response.type === "basic") {
                const copy = response.clone();
                caches.open(CACHE_NAME).then((cache) => cache.put(event.request, copy));
            }
            return response;
        }))
    );
});

self.addEventListener("push", (event) => {
    let payload = {};
    if (event.data) {
        const content = event.data.text();
        try {
            payload = JSON.parse(content);
        } catch {
            payload = { body: content };
        }
    }

    const notificationOptions = {
        body: payload.body || "Saatnya istirahat sejenak.",
        icon: "./app-icon.svg",
        badge: "./app-icon.svg",
        tag: "jeda-break",
        renotify: true,
        requireInteraction: true,
        data: { url: payload.url || "./?break=1" }
    };

    event.waitUntil(
        Promise.all([
            self.registration.showNotification(payload.title || "Saatnya istirahat sejenak", notificationOptions),
            self.clients.matchAll({ type: "window", includeUncontrolled: true }).then((clients) => {
                clients.forEach((client) => client.postMessage({ type: "JEDA_SHOW_BREAK" }));
            })
        ])
    );
});

self.addEventListener("notificationclick", (event) => {
    event.notification.close();
    const targetUrl = new URL(event.notification.data.url || "./?break=1", self.registration.scope);
    targetUrl.searchParams.set("break", "1");
    event.waitUntil(
        self.clients.matchAll({ type: "window", includeUncontrolled: true }).then((clients) => {
            const existing = clients.find((client) => new URL(client.url).origin === targetUrl.origin);
            if (existing) {
                return existing.navigate(targetUrl.href).then((client) => client.focus());
            }
            return self.clients.openWindow(targetUrl.href);
        })
    );
});

self.addEventListener("pushsubscriptionchange", (event) => {
    if (!event.newSubscription) return;
    event.waitUntil(
        fetch("./push-subscribe.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(event.newSubscription)
        })
    );
});
