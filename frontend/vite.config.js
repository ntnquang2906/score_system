import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// Web chạy ở đường dẫn gốc / (Nginx phục vụ thư mục dist/).
// Dev: npm run dev -> http://localhost:5173/ ; /api được chuyển tiếp tới server.
const backend = process.env.BACKEND_URL || 'http://192.168.88.13:6868'

export default defineConfig({
  base: '/',
  plugins: [vue()],
  server: {
    proxy: {
      '/api': { target: backend, changeOrigin: true },
    },
  },
})
