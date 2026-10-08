// Đăng nhập OIDC (Authorization Code + PKCE) với Keycloak.
// Tự viết thay cho keycloak-js vì keycloak-js bắt buộc Web Crypto, mà trình duyệt chỉ cho dùng
// Web Crypto trên HTTPS/localhost -> hỏng khi chạy http://<IP>. Module này dùng Web Crypto nếu có,
// không có thì tự tính SHA-256. Khi Keycloak chuyển tiếp sang VNU-SSO, phần này không đổi.

import { lang, setLang } from './i18n'

const ISSUER = import.meta.env.VITE_OIDC_ISSUER || `${window.location.origin}/auth/realms/score`
const CLIENT_ID = import.meta.env.VITE_OIDC_CLIENT || 'score-web'
const REDIRECT_URI = `${window.location.origin}/`
const ENDPOINT = {
  authorize: `${ISSUER}/protocol/openid-connect/auth`,
  token: `${ISSUER}/protocol/openid-connect/token`,
  logout: `${ISSUER}/protocol/openid-connect/logout`,
}
const STORE = 'oidc_tokens'
const PENDING = 'oidc_pending'

let tokens = null   // { access_token, refresh_token, id_token, expires_at, refresh_expires_at }
let refreshing = null

// ---------------------------------------------------------------- public API

/** Gọi 1 lần khi mở app: xử lý callback từ Keycloak, hoặc dùng phiên cũ, hoặc chuyển sang trang đăng nhập. */
export async function initAuth() {
  const params = new URLSearchParams(window.location.search)
  if (params.has('code') && params.has('state')) {
    await handleCallback(params)
    return
  }
  if (params.has('error')) {
    throw new Error(`${params.get('error')}: ${params.get('error_description') || ''}`)
  }

  tokens = load()
  if (tokens && tokens.refresh_expires_at > Date.now() + 5000) {
    await accessToken()
    return
  }
  await login()
  await new Promise(() => {})   // đang chuyển trang, dừng khởi tạo app
}

/** Access token còn hạn (tự gia hạn nếu sắp hết) */
export async function accessToken() {
  if (!tokens) await login()
  if (tokens.expires_at - Date.now() < 30_000) {
    refreshing ??= refresh().finally(() => { refreshing = null })
    await refreshing
  }
  return tokens.access_token
}

export async function login() {
  const verifier = randomString(64)
  const state = randomString(24)
  sessionStorage.setItem(PENDING, JSON.stringify({
    verifier,
    state,
    returnTo: window.location.pathname + window.location.search + window.location.hash,
  }))
  const url = new URL(ENDPOINT.authorize)
  url.search = new URLSearchParams({
    client_id: CLIENT_ID,
    redirect_uri: REDIRECT_URI,
    response_type: 'code',
    scope: 'openid profile email',
    state,
    code_challenge: base64url(await sha256(new TextEncoder().encode(verifier))),
    code_challenge_method: 'S256',
    ui_locales: lang.value,   // trang đăng nhập dùng cùng ngôn ngữ với app
  })
  window.location.assign(url)
}

export function logout() {
  const idToken = tokens?.id_token
  clear()
  const url = new URL(ENDPOINT.logout)
  url.search = new URLSearchParams({
    client_id: CLIENT_ID,
    post_logout_redirect_uri: REDIRECT_URI,
    ui_locales: lang.value,
    ...(idToken && { id_token_hint: idToken }),
  })
  window.location.assign(url)
}

// ---------------------------------------------------------------- token flow

async function handleCallback(params) {
  const pending = JSON.parse(sessionStorage.getItem(PENDING) || 'null')
  sessionStorage.removeItem(PENDING)
  if (!pending || pending.state !== params.get('state')) {
    // callback cũ/không khớp (vd bấm Back) -> đăng nhập lại từ đầu
    window.history.replaceState(null, '', REDIRECT_URI)
    await login()
    await new Promise(() => {})
  }
  await tokenRequest({
    grant_type: 'authorization_code',
    code: params.get('code'),
    redirect_uri: REDIRECT_URI,
    code_verifier: pending.verifier,
  })
  // Nếu người dùng đổi ngôn ngữ ngay trên trang đăng nhập -> app đổi theo
  const locale = claims(tokens.id_token).locale
  if (locale === 'vi' || locale === 'en') setLang(locale)

  // bỏ ?code=&state= khỏi thanh địa chỉ, quay về trang đang mở trước khi đăng nhập
  window.history.replaceState(null, '', pending.returnTo?.startsWith('/') ? pending.returnTo : REDIRECT_URI)
}

async function refresh() {
  try {
    await tokenRequest({ grant_type: 'refresh_token', refresh_token: tokens.refresh_token })
  } catch {
    clear()
    await login()
    await new Promise(() => {})
  }
}

async function tokenRequest(body) {
  const res = await fetch(ENDPOINT.token, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ client_id: CLIENT_ID, ...body }),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.error_description || data.error || `token ${res.status}`)
  const now = Date.now()
  tokens = {
    access_token: data.access_token,
    refresh_token: data.refresh_token,
    id_token: data.id_token || tokens?.id_token,
    expires_at: now + data.expires_in * 1000,
    refresh_expires_at: now + (data.refresh_expires_in || 1800) * 1000,
  }
  sessionStorage.setItem(STORE, JSON.stringify(tokens))
}

function claims(jwt) {
  try {
    const payload = jwt.split('.')[1].replace(/-/g, '+').replace(/_/g, '/')
    return JSON.parse(decodeURIComponent(escape(atob(payload))))
  } catch {
    return {}
  }
}

function load() {
  try { return JSON.parse(sessionStorage.getItem(STORE) || 'null') } catch { return null }
}

function clear() {
  tokens = null
  sessionStorage.removeItem(STORE)
}

// ---------------------------------------------------------------- crypto helpers

function randomString(length) {
  const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-._~'
  const bytes = crypto.getRandomValues(new Uint8Array(length))   // có cả trên HTTP
  return Array.from(bytes, (b) => chars[b % chars.length]).join('')
}

function base64url(bytes) {
  return btoa(String.fromCharCode(...bytes)).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
}

async function sha256(data) {
  if (window.crypto?.subtle) {
    return new Uint8Array(await crypto.subtle.digest('SHA-256', data))
  }
  return sha256Fallback(data)
}

// SHA-256 thuần JS (FIPS 180-4) - chỉ dùng khi trang chạy HTTP không có Web Crypto
const K = new Uint32Array([
  0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
  0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
  0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
  0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
  0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
  0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
  0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
  0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2,
])

export function sha256Fallback(data) {
  const bitLen = data.length * 8
  const padded = new Uint8Array(((data.length + 9 + 63) >> 6) << 6)
  padded.set(data)
  padded[data.length] = 0x80
  const view = new DataView(padded.buffer)
  view.setUint32(padded.length - 8, Math.floor(bitLen / 0x100000000))
  view.setUint32(padded.length - 4, bitLen >>> 0)

  const h = new Uint32Array([0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a, 0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19])
  const w = new Uint32Array(64)
  const rotr = (x, n) => (x >>> n) | (x << (32 - n))

  for (let off = 0; off < padded.length; off += 64) {
    for (let i = 0; i < 16; i++) w[i] = view.getUint32(off + i * 4)
    for (let i = 16; i < 64; i++) {
      const s0 = rotr(w[i - 15], 7) ^ rotr(w[i - 15], 18) ^ (w[i - 15] >>> 3)
      const s1 = rotr(w[i - 2], 17) ^ rotr(w[i - 2], 19) ^ (w[i - 2] >>> 10)
      w[i] = (w[i - 16] + s0 + w[i - 7] + s1) >>> 0
    }
    let [a, b, c, d, e, f, g, hh] = h
    for (let i = 0; i < 64; i++) {
      const S1 = rotr(e, 6) ^ rotr(e, 11) ^ rotr(e, 25)
      const ch = (e & f) ^ (~e & g)
      const t1 = (hh + S1 + ch + K[i] + w[i]) >>> 0
      const S0 = rotr(a, 2) ^ rotr(a, 13) ^ rotr(a, 22)
      const maj = (a & b) ^ (a & c) ^ (b & c)
      const t2 = (S0 + maj) >>> 0
      hh = g; g = f; f = e; e = (d + t1) >>> 0
      d = c; c = b; b = a; a = (t1 + t2) >>> 0
    }
    h[0] += a; h[1] += b; h[2] += c; h[3] += d; h[4] += e; h[5] += f; h[6] += g; h[7] += hh
  }

  const out = new Uint8Array(32)
  const outView = new DataView(out.buffer)
  h.forEach((v, i) => outView.setUint32(i * 4, v))
  return out
}
