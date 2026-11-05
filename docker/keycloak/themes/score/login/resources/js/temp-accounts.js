// TẠM THỜI khi chưa nối VNU-SSO: đăng nhập bằng email chưa có tài khoản thì tự tạo tài khoản đơn vị
// với mật khẩu vừa nhập (API /api/v1/temp-accounts), rồi mới gửi form đăng nhập như bình thường.
// Tên đăng nhập không phải email (tài khoản lãnh đạo được cấp) => gửi form ngay, không tạo gì.
// Khi tích hợp SSO: xoá tệp này và dòng "scripts=" trong theme.properties.
document.addEventListener('DOMContentLoaded', () => {
  const form = document.getElementById('kc-form-login')
  if (!form || !form.username || !form.password) return

  const en = (document.documentElement.lang || '').startsWith('en')
  const hint = document.createElement('p')
  hint.className = 'temp-account-hint'
  hint.textContent = en
    ? 'Units: sign in with your email and a password of your choice. The first time, an account is created automatically; next time use the same password. Leaders: use the account you were given.'
    : 'Đơn vị: đăng nhập bằng email và một mật khẩu tự chọn. Lần đầu hệ thống tự tạo tài khoản; các lần sau dùng lại đúng mật khẩu đó. Lãnh đạo: dùng tài khoản đã được cấp.'
  form.prepend(hint)

  const isEmail = (v) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)
  let ready = false
  form.addEventListener('submit', async (event) => {
    if (ready || !isEmail(form.username.value.trim()) || !form.password.value) return
    event.preventDefault()
    try {
      await fetch('/api/v1/temp-accounts', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ email: form.username.value.trim(), password: form.password.value }),
      })
    } catch { /* lỗi mạng: vẫn đăng nhập như thường */ }
    ready = true
    form.submit()
  })
})
