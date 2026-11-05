import { ref } from 'vue'
import vi from './locales/vi'
import en from './locales/en'

const dictionaries = { vi, en }
const saved = (() => { try { return localStorage.getItem('site_lang') } catch { return null } })()
export const lang = ref(saved === 'en' ? 'en' : 'vi')

export function setLang(value) {
  lang.value = value
  document.documentElement.lang = value
  try { localStorage.setItem('site_lang', value) } catch { /* bỏ qua */ }
}

/** t('form.submit', { mb: 3 }) -> chuỗi theo ngôn ngữ hiện tại, thay {mb} */
export function t(key, vars = {}) {
  let text = dictionaries[lang.value][key] ?? dictionaries.vi[key] ?? key
  for (const [k, v] of Object.entries(vars)) text = text.replaceAll(`{${k}}`, v)
  return text
}

/** Chọn trường tiếng Anh (name_en, text_en...) khi đang ở chế độ EN, nếu có */
export function pick(obj, field) {
  return (lang.value === 'en' && obj?.[`${field}_en`]) || obj?.[field] || ''
}

/** Thời điểm theo giờ Việt Nam, định dạng theo ngôn ngữ đang chọn */
export const formatTime = (iso) => new Date(iso).toLocaleString(lang.value === 'en' ? 'en-GB' : 'vi-VN', { timeZone: 'Asia/Ho_Chi_Minh' })

/** Làm tròn 2 chữ số, bỏ số 0 thừa (67.5, 0.19, 85.75) */
export const num = (n) => String(Math.round(Number(n) * 100) / 100)
