import { reactive } from 'vue'
import { api } from './api'

// Thông tin người dùng hiện tại (từ /api/v1/me)
export const session = reactive({ user: null })

export async function loadSession() {
  session.user = await api.get('/me')
}

export const hasRole = (...roles) => roles.some((r) => session.user?.roles?.includes(r))
export const canSeeAllEvaluations = () => hasRole('admin', 'editor', 'viewer')
export const canSeeEvaluations = () => hasRole('admin', 'editor', 'viewer', 'unit')
export const canSubmit = () => hasRole('admin', 'editor', 'unit')
export const isUnitOnly = () => hasRole('unit') && !hasRole('admin', 'editor')
