// Bản cũ (PHP) từng cài service worker tại /sw.js và lưu tạm style.css, script.js, icon...
// File này thay thế nó: xoá toàn bộ bộ nhớ đệm cũ, tự gỡ đăng ký rồi tải lại trang
// để người từng vào bản cũ thấy ngay giao diện mới. Không cài service worker mới.
self.addEventListener('install', () => self.skipWaiting())
self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const keys = await caches.keys()
    await Promise.all(keys.map((k) => caches.delete(k)))
    await self.registration.unregister()
    const clients = await self.clients.matchAll({ type: 'window' })
    clients.forEach((c) => c.navigate(c.url))
  })())
})
