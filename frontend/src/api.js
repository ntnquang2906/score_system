import { accessToken } from './auth'

export class ApiError extends Error {
  constructor(status, body) {
    super(body?.message || `Lỗi ${status}`)
    this.status = status
    this.errors = body?.errors || {}   // lỗi kiểm tra dữ liệu (422): { "answers.basic.q1.yes": ["..."] }
  }
}

async function request(path, options = {}) {
  const res = await fetch(`/api/v1${path}`, {
    ...options,
    headers: { Accept: 'application/json', Authorization: `Bearer ${await accessToken()}`, ...options.headers },
  })
  if (!res.ok) {
    throw new ApiError(res.status, await res.json().catch(() => null))
  }
  return res
}

export const api = {
  get: async (path) => (await request(path)).json(),
  /** Gửi FormData (có tệp) hoặc object (JSON) */
  post: async (path, body) => (await request(path, {
    method: 'POST',
    ...(body instanceof FormData
      ? { body }
      : { body: JSON.stringify(body), headers: { 'Content-Type': 'application/json' } }),
  })).json(),
  /** Tải tệp về máy (Excel, minh chứng) - phải kèm token nên không dùng link thường được */
  async download(path, fallbackName) {
    const res = await request(path)
    const disposition = res.headers.get('Content-Disposition') || ''
    const name = decodeURIComponent(disposition.match(/filename\*=utf-8''([^;]+)/i)?.[1] || '')
      || disposition.match(/filename="([^"]+)"/)?.[1] || fallbackName
    const url = URL.createObjectURL(await res.blob())
    const a = Object.assign(document.createElement('a'), { href: url, download: name })
    a.click()
    setTimeout(() => URL.revokeObjectURL(url), 1000)
  },
}
