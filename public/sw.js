const CACHE = 'contabil-estatico-v1';
const ARQUIVOS = ['/assets/app.css', '/assets/app.js', '/assets/icon.svg'];

self.addEventListener('install', (evento) => {
  evento.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(ARQUIVOS)));
});

self.addEventListener('fetch', (evento) => {
  const url = new URL(evento.request.url);
  if (evento.request.method !== 'GET' || !url.pathname.startsWith('/assets/')) {
    return;
  }
  evento.respondWith(
    caches.match(evento.request).then((guardado) => guardado || fetch(evento.request).then((resposta) => {
      const copia = resposta.clone();
      caches.open(CACHE).then((cache) => cache.put(evento.request, copia));
      return resposta;
    }))
  );
});
