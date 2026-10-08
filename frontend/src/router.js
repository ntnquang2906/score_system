import { createRouter, createWebHistory } from 'vue-router'
import { canSeeAllEvaluations, canSeeEvaluations, canSubmit, hasRole } from './session'

const routes = [
  {
    path: '/',
    redirect: () => (canSubmit() ? '/form' : canSeeEvaluations() ? '/evaluations' : '/profile'),
  },
  { path: '/form', component: () => import('./views/EvaluationForm.vue'), meta: { allow: canSubmit } },
  { path: '/evaluations', component: () => import('./views/EvaluationList.vue'), meta: { allow: canSeeEvaluations } },
  { path: '/evaluations/summary', component: () => import('./views/SummaryView.vue'), meta: { allow: canSeeAllEvaluations } },
  { path: '/evaluations/:id(\\d+)/edit', component: () => import('./views/EvaluationForm.vue'), meta: { allow: () => hasRole('admin', 'editor') }, props: true },
  { path: '/evaluations/:id(\\d+)', component: () => import('./views/EvaluationResult.vue'), meta: { allow: canSeeEvaluations }, props: true },
  { path: '/profile', component: () => import('./views/ProfileView.vue') },
  { path: '/:pathMatch(.*)*', redirect: '/' },
]

// Tạo router SAU khi đăng nhập xong: router đọc URL lúc khởi tạo, phải để module đăng nhập
// xoá ?code=&state= của Keycloak khỏi URL trước.
export function createAppRouter() {
  const router = createRouter({
    history: createWebHistory('/'),
    routes,
    scrollBehavior: (to, from, saved) => saved || { top: 0 },
  })
  router.beforeEach((to) => {
    if (to.meta.allow && !to.meta.allow()) return '/profile'
  })
  return router
}
