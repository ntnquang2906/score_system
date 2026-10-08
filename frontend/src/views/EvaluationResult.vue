<script setup>
import { computed, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { api } from '../api'
import { lang, t } from '../i18n'

const props = defineProps({ id: { type: String, required: true } })
const route = useRoute()

const evaluation = ref(null)
const error = ref('')
const downloading = ref(false)

const FUNCTION_ORDER = ['basic', 'applied', 'tech', 'policy']

async function load() {
  evaluation.value = null
  error.value = ''
  try {
    evaluation.value = await api.get(`/evaluations/${props.id}`)
  } catch (e) {
    error.value = e.status === 403 ? t('common.no_access') : e.message
  }
}
watch(() => props.id, load, { immediate: true })

const functions = computed(() => {
  const all = evaluation.value?.result?.functions || {}
  const keys = Object.keys(all)
  return [...FUNCTION_ORDER.filter((k) => keys.includes(k)), ...keys.filter((k) => !FUNCTION_ORDER.includes(k))]
    .map((key) => ({ key, ...all[key] }))
})
const rankClass = computed(() => `rank-${(evaluation.value?.grade || 'd').toLowerCase()}`)
const totalPct = computed(() => Math.max(0, Math.min(100, Number(evaluation.value?.total_score || 0))))

// Làm tròn 2 chữ số, bỏ số 0 thừa (67.5, 0.19, 85.75)
const num = (n) => String(Math.round(Number(n) * 100) / 100)
const printPage = () => window.print()
const formatTime = (iso) => new Date(iso).toLocaleString(lang.value === 'en' ? 'en-GB' : 'vi-VN', { timeZone: 'Asia/Ho_Chi_Minh' })

/** Nhóm câu hỏi theo Đt1..Đt4 để hiện tiêu đề nhóm giống trang cũ */
function groupedDetails(f) {
  const groups = []
  for (const d of f.details || []) {
    if (groups.at(-1)?.id !== d.group) groups.push({ id: d.group, items: [] })
    groups.at(-1).items.push(d)
  }
  return groups
}
const filesFor = (funcKey, questionId) =>
  (evaluation.value?.attachments || []).filter((a) => a.question_key === `${funcKey}.${questionId}`)

async function downloadExcel() {
  downloading.value = true
  try {
    await api.download(`/evaluations/${props.id}/export`, 'ket_qua.xlsx')
  } finally {
    downloading.value = false
  }
}
const downloadFile = (a) => api.download(`/attachments/${a.id}/download`, a.name)
</script>

<template>
  <div class="report-page">
    <h1>{{ t('result.h1') }}</h1>

    <div v-if="error" class="report-banner report-banner-error">{{ t('common.error') }} {{ error }}</div>
    <p v-else-if="!evaluation">{{ t('common.loading') }}</p>

    <template v-else>
      <div v-if="route.query.updated" class="report-banner report-banner-success">
        <div><strong>{{ t('edit.saved_label') }}</strong> {{ evaluation.edit_mode === 'full' ? t('edit.saved') : t('edit.saved_legacy') }}</div>
      </div>
      <div v-if="route.query.new" class="report-banner report-banner-success">
        <div><strong>{{ t('result.success_label') }}</strong> {{ t('result.success_message') }}</div>
      </div>

      <div class="report-hero">
        <div class="report-hero-top">
          <div>
            <div class="report-org">{{ t('result.evaluator_prefix') }}{{ evaluation.organization.submitted_name }}</div>
            <div class="report-meta">
              {{ formatTime(evaluation.submitted_at) }}
              <template v-if="evaluation.submitted_by"> · {{ t('result.submitted_by') }}{{ evaluation.submitted_by }}</template>
            </div>
          </div>
          <span class="rank-badge" :class="rankClass">{{ t('result.rank_label') }}{{ t(`result.rank_${evaluation.grade}`) }}</span>
        </div>

        <div class="report-score-block">
          <div class="report-score-value">{{ num(evaluation.total_score) }}<span class="report-score-max">/100</span></div>
          <div class="report-score-label">{{ t('result.total_score_heading') }}</div>
          <div class="report-progress">
            <div class="report-progress-fill" :class="rankClass" :style="{ width: `${totalPct}%` }" />
          </div>
        </div>
      </div>

      <h2 class="report-section-title">{{ t('result.details_heading') }}</h2>

      <div v-for="f in functions" :key="f.key" class="function-card">
        <div class="function-card-header">
          <h2>{{ lang === 'en' ? f.name_en || f.name : f.name }}</h2>
          <span class="weight-pill">{{ t('result.weight_label') }}{{ num(f.weight * 100) }}%</span>
        </div>

        <div class="score-chips">
          <div v-for="k in ['dt1', 'dt2', 'dt3', 'dt4']" :key="k" class="score-chip">
            <span class="chip-label">{{ t(`result.${k}_label`) }}</span>
            <span class="chip-value">{{ num(f[k]) }}</span>
          </div>
          <div class="score-chip total">
            <span class="chip-label">{{ t('result.dt_label') }}</span>
            <span class="chip-value">{{ num(f.dt) }}</span>
          </div>
          <div class="score-chip weighted">
            <span class="chip-label">{{ t('result.weighted_label') }}</span>
            <span class="chip-value">{{ num(f.weighted) }}</span>
          </div>
        </div>

        <details class="explain-details">
          <summary>{{ t('result.details_summary') }}</summary>
          <template v-for="g in groupedDetails(f)" :key="g.id">
            <h4 class="explain-group-title">{{ g.id }}</h4>
            <div v-for="(d, i) in g.items" :key="i" class="explain-item" :class="d.yes ? 'answer-yes' : 'answer-no'">
              <span class="explain-score-badge">{{ num(d.score) }}<template v-if="d.max != null">/{{ num(d.max) }}</template></span>
              <p class="explain-question">{{ lang === 'en' ? d.question_en || d.question : d.question }}</p>
              <p class="explain-meta">
                <span class="answer-pill" :class="d.yes ? 'yes' : 'no'">{{ d.yes ? t('form.yes') : t('form.no') }}</span>
              </p>
              <p v-if="d.explanation" class="explain-text">{{ lang === 'en' ? d.explanation_en || d.explanation : d.explanation }}</p>
              <p v-for="a in filesFor(f.key, d.question_id)" :key="a.id" class="explain-meta">
                <a href="#" @click.prevent="downloadFile(a)">{{ a.name }}</a>
              </p>
            </div>
          </template>
        </details>
      </div>

      <div class="report-actions">
        <a href="#" class="btn-download" @click.prevent="downloadExcel">{{ downloading ? t('common.loading') : t('result.download_result') }}</a>
        <RouterLink v-if="evaluation.can_edit" :to="`/evaluations/${evaluation.id}/edit`" class="btn-edit">{{ t('edit.btn') }}</RouterLink>
        <a href="#" class="btn-print" @click.prevent="printPage">{{ t('result.print_btn') }}</a>
        <RouterLink to="/evaluations" class="btn-back">{{ t('result.back_link') }}</RouterLink>
      </div>
    </template>
  </div>
</template>
