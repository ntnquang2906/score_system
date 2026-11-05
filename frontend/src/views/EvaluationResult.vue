<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { api } from '../api'
import { formatTime, lang, num, t } from '../i18n'

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

const printPage = () => window.print()

/** Nhóm câu hỏi theo Đt1..Đt4 để hiện tiêu đề nhóm giống trang cũ */
function groupedDetails(f) {
  const groups = []
  for (const d of f.details || []) {
    if (onlyAttention.value && !['review', 'fail'].includes(d.evidence?.status)) continue
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

// ---- Kiểm tra minh chứng tự động
const EVIDENCE_ICON = { pass: '🟢', review: '🟡', fail: '🔴', pending: '⏳' }
const onlyAttention = ref(false)   // chỉ hiện tiêu chí cần xem lại / không đạt
const attentionCount = computed(() => (evaluation.value?.evidence?.review || 0) + (evaluation.value?.evidence?.fail || 0))
const unreadFiles = computed(() => (evaluation.value?.attachments || []).filter((a) => a.analysis_status !== 'done' && a.analysis_status !== 'pending'))
const pending = computed(() => (evaluation.value?.evidence?.pending || 0) > 0)

// Tệp đang được đọc: tự tải lại kết quả mỗi 10 giây
let pollTimer = null
watch(pending, (on) => {
  clearInterval(pollTimer)
  if (on) pollTimer = setInterval(async () => {
    try { evaluation.value = await api.get(`/evaluations/${props.id}`) } catch { /* thử lại lần sau */ }
  }, 10000)
})
onBeforeUnmount(() => clearInterval(pollTimer))

const factsOf = (d, a) => d.evidence?.files?.find((f) => f.id === a.id)
/** Dữ kiện hệ thống đọc được từ 1 tệp, gọn 1 dòng */
function factLine(f) {
  if (f.status === 'pending') return t('evidence.fact_reading')
  if (f.status !== 'done') return t('evidence.fact_failed')
  if (!f.readable) return t('evidence.fact_unreadable')
  const parts = []
  if (f.relevant === 'no') parts.push(t('evidence.fact_irrelevant'))
  if (f.doc_type) parts.push(f.page ? `${f.doc_type} (${t('evidence.page', { n: f.page })})` : f.doc_type)
  if (f.year) parts.push(t('evidence.fact_year', { y: f.year }))
  if (f.unit === 'other') parts.push(t('evidence.fact_other_unit', { name: f.other_unit }))
  if (f.kind === 'xlsx') parts.push(t('evidence.fact_rows', { n: f.count }))
  else {
    parts.push(f.stamp ? t('evidence.fact_stamp') : t('evidence.fact_no_stamp'))
    parts.push(f.signature ? (f.digital_signature ? t('evidence.fact_digital_signature') : t('evidence.fact_signature')) : t('evidence.fact_no_signature'))
  }
  if (f.ocr) parts.push(t('evidence.fact_ocr'))
  return parts.join(' · ')
}

// Lãnh đạo đặt lại kết luận
const reviewing = ref(null)   // "chức_năng.mã_câu" đang mở form xem lại
const reviewVerdict = ref('pass')
const reviewReason = ref('')
const reviewError = ref('')
const saving = ref(false)
function startReview(key, d) {
  reviewing.value = key
  reviewVerdict.value = d.evidence.review?.verdict || 'pass'
  reviewReason.value = d.evidence.review?.reason || ''
  reviewError.value = ''
}
async function saveReview(key, verdict, reason) {
  saving.value = true
  reviewError.value = ''
  try {
    evaluation.value = await api.put(`/evaluations/${props.id}/evidence`, { question_key: key, verdict, reason })
    reviewing.value = null
  } catch (e) {
    reviewError.value = Object.values(e.errors).flat()[0] || e.message
  } finally {
    saving.value = false
  }
}
async function recheck() {
  evaluation.value = await api.post(`/evaluations/${props.id}/evidence/recheck`, {})
}
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

      <div v-if="evaluation.evidence" class="evidence-summary">
        <div class="evidence-summary-head">
          <strong>{{ t('evidence.summary_title') }}</strong>
          <span v-for="k in ['pass', 'review', 'fail', 'pending']" v-show="k !== 'pending' || pending" :key="k" class="evidence-pill" :class="k">
            {{ EVIDENCE_ICON[k] }} {{ t(`evidence.${k}`) }}: {{ evaluation.evidence[k] }}
          </span>
        </div>
        <p>{{ t('evidence.summary_help') }}</p>
        <p v-if="pending">{{ t('evidence.pending_help') }}</p>
        <div class="evidence-summary-actions">
          <label v-if="attentionCount"><input v-model="onlyAttention" type="checkbox"> {{ t('evidence.only_attention', { n: attentionCount }) }}</label>
          <button v-if="evaluation.can_edit && unreadFiles.length" type="button" class="link-btn" @click="recheck">{{ t('evidence.recheck', { n: unreadFiles.length }) }}</button>
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

        <details class="explain-details" :open="onlyAttention || undefined">
          <summary>{{ t('result.details_summary') }}</summary>
          <template v-for="g in groupedDetails(f)" :key="g.id">
            <h4 class="explain-group-title">{{ g.id }}</h4>
            <div v-for="(d, i) in g.items" :key="i" class="explain-item" :class="d.yes ? 'answer-yes' : 'answer-no'">
              <span class="explain-score-badge">{{ num(d.score) }}<template v-if="d.max != null">/{{ num(d.max) }}</template></span>
              <p class="explain-question">{{ lang === 'en' ? d.question_en || d.question : d.question }}</p>
              <p class="explain-meta">
                <span class="answer-pill" :class="d.yes ? 'yes' : 'no'">{{ d.yes ? t('form.yes') : t('form.no') }}</span>
                <span v-if="d.evidence" class="evidence-pill" :class="d.evidence.status">
                  {{ EVIDENCE_ICON[d.evidence.status] }} {{ t(`evidence.${d.evidence.status}`) }}<template v-if="d.evidence.source === 'reviewer'"> · {{ t('evidence.by_reviewer') }}</template>
                </span>
              </p>
              <p v-if="d.explanation" class="explain-text">{{ lang === 'en' ? d.explanation_en || d.explanation : d.explanation }}</p>
              <div v-for="a in filesFor(f.key, d.question_id)" :key="a.id" class="explain-meta">
                <a href="#" @click.prevent="downloadFile(a)">{{ a.name }}</a>
                <template v-if="factsOf(d, a)">
                  <div class="evidence-facts">{{ factLine(factsOf(d, a)) }}</div>
                  <div v-if="factsOf(d, a).quote" class="evidence-quote">“{{ factsOf(d, a).quote }}”</div>
                </template>
              </div>

              <div v-if="d.evidence && evaluation.can_edit && d.evidence.status !== 'pending'" class="evidence-review">
                <template v-if="reviewing === `${f.key}.${d.question_id}`">
                  <div class="evidence-verdicts">
                    <label v-for="v in ['pass', 'review', 'fail']" :key="v" class="evidence-pill" :class="[v, { selected: reviewVerdict === v }]">
                      <input v-model="reviewVerdict" type="radio" :value="v"> {{ EVIDENCE_ICON[v] }} {{ t(`evidence.${v}`) }}
                    </label>
                  </div>
                  <textarea v-model="reviewReason" rows="2" :placeholder="t('evidence.reason_placeholder')" />
                  <div v-if="reviewError" class="field-error">{{ reviewError }}</div>
                  <button type="button" :disabled="saving" @click="saveReview(`${f.key}.${d.question_id}`, reviewVerdict, reviewReason)">{{ t('evidence.save') }}</button>
                  <button type="button" class="link-btn" @click="reviewing = null">{{ t('evidence.cancel') }}</button>
                </template>
                <template v-else>
                  <button type="button" class="link-btn" @click="startReview(`${f.key}.${d.question_id}`, d)">{{ t('evidence.review_btn') }}</button>
                  <button v-if="d.evidence.source === 'reviewer'" type="button" class="link-btn" :disabled="saving" @click="saveReview(`${f.key}.${d.question_id}`, null)">{{ t('evidence.reset') }}</button>
                </template>
              </div>
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
