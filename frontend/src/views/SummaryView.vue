<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { api } from '../api'
import { lang, t } from '../i18n'

const FUNCTION_ORDER = ['basic', 'applied', 'tech', 'policy']
const GRADE_COLORS = { A: '#16a34a', B: '#2563eb', C: '#f59e0b', D: '#dc2626' }
// Cột của file Excel (thứ tự giống hệ cũ); cột rút gọn mặc định khi xem chi tiết
const KEY_COLUMNS = [1, 2, 12, 13, 14, 15, 16, 17, 18]
const COLUMN_KEYS = ['time', 'org', 'function', 'weight', 'dt1', 'dt2', 'dt3', 'dt4', 'dt', 'weighted', 'total',
  'grade', 'group', 'question', 'answer', 'score', 'note', 'evidence', 'explanation']

const data = ref(null)
const error = ref('')
const tab = ref('units')
const unitFilter = ref('')
const search = ref('')
const allColumns = ref(false)
const limit = ref(200)
const downloading = ref(false)

onMounted(async () => {
  try {
    data.value = await api.get('/evaluations/summary')
  } catch (e) {
    error.value = e.status === 403 ? t('common.no_access') : e.message
  }
})

const functionKeys = computed(() => {
  const keys = new Set((data.value?.organizations || []).flatMap((o) => Object.keys(o.functions)))
  return [...FUNCTION_ORDER.filter((k) => keys.has(k)), ...[...keys].filter((k) => !FUNCTION_ORDER.includes(k))]
})
const functionName = (key) => {
  const f = data.value.organizations.find((o) => o.functions[key])?.functions[key]
  return lang.value === 'en' ? f?.name_en || f?.name : f?.name
}
const average = computed(() => {
  const list = data.value?.organizations || []
  return list.length ? Math.round((list.reduce((s, o) => s + o.total_score, 0) / list.length) * 100) / 100 : null
})

const columns = computed(() => (allColumns.value ? COLUMN_KEYS.map((_, i) => i) : KEY_COLUMNS))
const filteredRows = computed(() => {
  const q = search.value.trim().toLowerCase()
  return (data.value?.rows || []).filter((r) =>
    (!unitFilter.value || r.evaluation_id === Number(unitFilter.value))
    && (!q || r.cells.some((c) => String(c).toLowerCase().includes(q))))
})

// Số lẻ dài -> làm tròn 2 chữ số khi hiển thị
const cell = (v) => (typeof v === 'number' && !Number.isInteger(v) ? Math.round(v * 100) / 100 : v)
const num = (n) => String(Math.round(Number(n) * 100) / 100)
const formatTime = (iso) => new Date(iso).toLocaleString(lang.value === 'en' ? 'en-GB' : 'vi-VN', { timeZone: 'Asia/Ho_Chi_Minh' })
const TZ = { timeZone: 'Asia/Ho_Chi_Minh' }
const formatDate = (iso) => new Date(iso).toLocaleDateString(lang.value === 'en' ? 'en-GB' : 'vi-VN', TZ)
const formatClock = (iso) => new Date(iso).toLocaleTimeString(lang.value === 'en' ? 'en-GB' : 'vi-VN', TZ)
const printPage = () => window.print()

async function download() {
  downloading.value = true
  try {
    await api.download('/evaluations/summary/export', 'Tong_hop_ket_qua.xlsx')
  } finally {
    downloading.value = false
  }
}
</script>

<template>
  <h1>{{ t('summary.h1') }}</h1>

  <div v-if="error" class="report-banner report-banner-error">{{ t('common.error') }} {{ error }}</div>
  <p v-else-if="!data">{{ t('common.loading') }}</p>

  <template v-else>
    <div class="section summary-head">
      <div>
        <strong>{{ t('summary.desc', { n: data.organizations.length }) }}</strong>
        <div class="muted">{{ t('summary.generated_at', { time: formatTime(data.generated_at) }) }}</div>
      </div>
      <div class="summary-actions">
        <button type="button" :disabled="downloading" @click="download">{{ downloading ? t('common.loading') : t('summary.download') }}</button>
        <button type="button" class="btn-secondary" @click="printPage">{{ t('summary.print') }}</button>
        <RouterLink to="/evaluations" class="btn-link">{{ t('summary.back') }}</RouterLink>
      </div>
    </div>

    <div class="tabs" role="tablist">
      <button type="button" role="tab" :class="{ active: tab === 'units' }" @click="tab = 'units'">{{ t('summary.tab_units') }}</button>
      <button type="button" role="tab" :class="{ active: tab === 'details' }" @click="tab = 'details'">{{ t('summary.tab_details') }}</button>
    </div>

    <!-- Theo đơn vị -->
    <div v-if="tab === 'units'" class="section">
      <div v-if="!data.organizations.length" class="empty-message">{{ t('list.empty') }}</div>
      <div v-else class="table-wrap">
        <table class="summary-table">
          <thead>
            <tr>
              <th>#</th>
              <th>{{ t('list.th_org') }}</th>
              <th>{{ t('list.th_time') }}</th>
              <th v-for="k in functionKeys" :key="k" class="num">{{ functionName(k) }}</th>
              <th class="num">{{ t('list.th_score') }}</th>
              <th>{{ t('list.th_grade') }}</th>
              <th />
            </tr>
          </thead>
          <tbody>
            <tr v-for="(o, i) in data.organizations" :key="o.evaluation_id">
              <td>{{ i + 1 }}</td>
              <td class="org-cell">{{ o.organization }}</td>
              <td class="nowrap">
                {{ formatDate(o.submitted_at) }}
                <div class="muted">{{ formatClock(o.submitted_at) }}</div>
              </td>
              <td v-for="k in functionKeys" :key="k" class="num">
                <template v-if="o.functions[k]">
                  <strong>{{ num(o.functions[k].weighted) }}</strong>
                  <div class="muted">ĐT {{ num(o.functions[k].dt) }} × {{ num(o.functions[k].weight * 100) }}%</div>
                </template>
                <span v-else class="muted">–</span>
              </td>
              <td class="num"><strong>{{ num(o.total_score) }}</strong></td>
              <td class="nowrap"><span class="grade-pill" :style="{ background: GRADE_COLORS[o.grade] }">{{ t(`result.rank_${o.grade}`) }}</span></td>
              <td><RouterLink :to="`/evaluations/${o.evaluation_id}`" class="view-btn">{{ t('list.view') }}</RouterLink></td>
            </tr>
          </tbody>
          <tfoot>
            <tr>
              <td colspan="3"><strong>{{ t('list.stat_avg_latest') }}</strong></td>
              <td v-for="k in functionKeys" :key="k" />
              <td class="num"><strong>{{ average }}</strong></td>
              <td colspan="2" />
            </tr>
          </tfoot>
        </table>
      </div>
    </div>

    <!-- Chi tiết từng câu hỏi (nội dung giống file Excel) -->
    <div v-else class="section">
      <div class="filters">
        <select v-model="unitFilter" @change="limit = 200">
          <option value="">{{ t('summary.all_units') }}</option>
          <option v-for="o in data.organizations" :key="o.evaluation_id" :value="o.evaluation_id">{{ o.organization }}</option>
        </select>
        <input v-model="search" type="search" :placeholder="t('summary.search')" @input="limit = 200">
        <label class="inline-check"><input v-model="allColumns" type="checkbox"> {{ t('summary.all_columns') }}</label>
      </div>
      <p class="muted">{{ t('summary.row_count', { n: filteredRows.length }) }}</p>

      <div class="table-wrap">
        <table class="summary-table details-table">
          <thead>
            <tr><th v-for="c in columns" :key="c">{{ t(`summary.col.${COLUMN_KEYS[c]}`) }}</th></tr>
          </thead>
          <tbody>
            <tr v-for="(r, i) in filteredRows.slice(0, limit)" :key="i">
              <td v-for="c in columns" :key="c" :class="{ wide: [13, 16, 17, 18].includes(c), 'org-cell': c === 1 }">{{ cell(r.cells[c]) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="filteredRows.length > limit" class="pager">
        <button type="button" @click="limit += 200">{{ t('summary.show_more', { n: filteredRows.length - limit }) }}</button>
      </div>
    </div>
  </template>
</template>
