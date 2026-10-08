<script setup>
import { computed, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { api } from '../api'
import { lang, t } from '../i18n'
import { canSeeAllEvaluations, isUnitOnly } from '../session'

const route = useRoute()
const router = useRouter()
const unitOnly = isUnitOnly()

const data = ref(null)
const error = ref('')
const search = ref(route.query.q || '')
const grade = ref(route.query.grade || '')
const page = computed(() => Number(route.query.page || 1))

const GRADE_COLORS = { A: '#16a34a', B: '#2563eb', C: '#f59e0b', D: '#dc2626' }

async function load() {
  error.value = ''
  const params = new URLSearchParams({ page: page.value, per_page: 20 })
  if (route.query.q) params.set('q', route.query.q)
  if (route.query.grade) params.set('grade', route.query.grade)
  if (route.query.organization_id) params.set('organization_id', route.query.organization_id)
  try {
    data.value = await api.get(`/evaluations?${params}`)
  } catch (e) {
    error.value = e.message
  }
}
watch(() => route.query, load, { immediate: true })

// Gõ tìm kiếm: chờ người dùng ngừng gõ 300ms rồi mới gọi API
let timer = null
watch([search, grade], () => {
  clearTimeout(timer)
  timer = setTimeout(() => {
    router.replace({ query: { ...(search.value && { q: search.value }), ...(grade.value && { grade: grade.value }) } })
  }, 300)
})

// Đang xem lịch sử nộp của 1 đơn vị
const historyOrg = computed(() => (route.query.organization_id ? data.value?.data?.[0]?.organization?.name : null))
const showHistory = (e) => router.push({ query: { organization_id: e.organization.id } })
const backToLatest = () => router.push({ query: {} })

const goPage = (p) => router.replace({ query: { ...route.query, page: p } })
const formatTime = (iso) => new Date(iso).toLocaleString(lang.value === 'en' ? 'en-GB' : 'vi-VN', { timeZone: 'Asia/Ho_Chi_Minh' })
const downloadingSummary = ref(false)
async function downloadSummary() {
  downloadingSummary.value = true
  try {
    await api.download('/evaluations/summary/export', 'Tong_hop_ket_qua.xlsx')
  } finally {
    downloadingSummary.value = false
  }
}
const download = (e) => api.download(`/evaluations/${e.id}/export`, 'ket_qua.xlsx')
</script>

<template>
  <h1>{{ unitOnly ? t('list.h1_unit') : t('list.h1') }}</h1>

  <div v-if="error" class="report-banner report-banner-error">{{ t('common.error') }} {{ error }}</div>

  <div v-if="data" class="section">
    <h2>{{ t('list.summary_heading') }}</h2>
    <div class="stats">
      <div v-if="!unitOnly" class="stat-box">
        <div class="label">{{ t('list.stat_orgs') }}</div>
        <div class="value">{{ data.stats.organizations }}</div>
      </div>
      <div class="stat-box">
        <div class="label">{{ t('list.stat_total') }}</div>
        <div class="value">{{ data.stats.total }}</div>
      </div>
      <div class="stat-box">
        <div class="label">{{ data.latest_only ? t('list.stat_avg_latest') : t('list.stat_avg') }}</div>
        <div class="value">{{ data.stats.average ?? '–' }}</div>
      </div>
    </div>
  </div>

  <div class="section">
    <h2 v-if="route.query.organization_id">{{ t('list.history_heading', { name: historyOrg || '' }) }}</h2>
    <h2 v-else>{{ t('list.files_heading') }}</h2>
    <div v-if="unitOnly" class="info-box">{{ t('list.info_box_unit') }}</div>
    <div v-else-if="route.query.organization_id" class="info-box">
      {{ t('list.info_box_history') }}
      <a href="#" @click.prevent="backToLatest">{{ t('list.back_to_latest') }}</a>
    </div>
    <div v-else class="info-box">{{ t('list.info_box_latest') }}</div>

    <div v-if="!route.query.organization_id" class="filters">
      <input v-if="!unitOnly" v-model="search" type="search" :placeholder="t('list.search')">
      <select v-model="grade">
        <option value="">{{ t('list.all_grades') }}</option>
        <option v-for="g in ['A', 'B', 'C', 'D']" :key="g" :value="g">{{ t(`result.rank_${g}`) }}</option>
      </select>
    </div>

    <p v-if="!data && !error">{{ t('common.loading') }}</p>
    <div v-else-if="data && !data.data.length" class="empty-message">{{ t('list.empty') }}</div>
    <div v-else-if="data" class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>{{ t('list.th_org') }}</th>
            <th>{{ t('list.th_time') }}</th>
            <th>{{ t('list.th_score') }}</th>
            <th>{{ t('list.th_grade') }}</th>
            <th v-if="data.latest_only">{{ t('list.th_count') }}</th>
            <th>{{ t('list.th_actions') }}</th>
          </tr>
        </thead>
        <tbody>
          <!-- File tổng hợp, ghim đầu trang (chỉ lãnh đạo/quản trị) -->
          <tr v-if="canSeeAllEvaluations() && data.latest_only && data.stats.organizations" class="summary-row">
            <td>
              <strong>{{ t('list.summary_file') }}</strong>
              <div class="muted">{{ t('list.summary_file_desc', { n: data.stats.organizations }) }}</div>
            </td>
            <td colspan="3" />
            <td v-if="data.latest_only" />
            <td>
              <RouterLink to="/evaluations/summary" class="view-btn">{{ t('list.view') }}</RouterLink>
              <button type="button" class="download-btn" :disabled="downloadingSummary" @click="downloadSummary">
                {{ downloadingSummary ? t('common.loading') : t('list.download') }}
              </button>
            </td>
          </tr>
          <tr v-for="e in data.data" :key="e.id">
            <td>
              {{ e.organization.name }}
              <div v-if="e.organization.submitted_name !== e.organization.name" class="muted">{{ e.organization.submitted_name }}</div>
            </td>
            <td>{{ formatTime(e.submitted_at) }}</td>
            <td><strong>{{ e.total_score }}</strong></td>
            <td><span class="grade-pill" :style="{ background: GRADE_COLORS[e.grade] }">{{ t(`result.rank_${e.grade}`) }}</span></td>
            <td v-if="data.latest_only">
              {{ e.organization.submissions_count }}
              <a v-if="e.organization.submissions_count > 1" href="#" class="muted" @click.prevent="showHistory(e)">({{ t('list.history') }})</a>
            </td>
            <td>
              <RouterLink :to="`/evaluations/${e.id}`" class="view-btn">{{ t('list.view') }}</RouterLink>
              <button type="button" class="download-btn" @click="download(e)">{{ t('list.download') }}</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="data && data.meta.last_page > 1" class="pager">
      <button type="button" :disabled="page <= 1" @click="goPage(page - 1)">{{ t('list.prev') }}</button>
      <span>{{ t('list.page', { page: data.meta.current_page, last: data.meta.last_page }) }}</span>
      <button type="button" :disabled="page >= data.meta.last_page" @click="goPage(page + 1)">{{ t('list.next') }}</button>
    </div>
  </div>
</template>
