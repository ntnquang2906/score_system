import { createApp } from 'vue'
import App from './App.vue'
import { createAppRouter } from './router'
import { initAuth } from './auth'
import { loadSession } from './session'
import { lang } from './i18n'
import './assets/style.css'
import './assets/i18n.css'
import './assets/layout.css'

document.documentElement.lang = lang.value

// Đăng nhập trước (chuyển sang trang Keycloak nếu chưa đăng nhập), rồi mới dựng giao diện
initAuth()
  .then(loadSession)
  .then(() => createApp(App).use(createAppRouter()).mount('#app'))
  .catch((e) => {
    document.getElementById('app').innerHTML =
      `<div class="page"><div class="report-banner report-banner-error">Không kết nối được hệ thống đăng nhập/API. Vui lòng thử lại sau.<br><small>${String(e?.message || e)}</small></div></div>`
  })
