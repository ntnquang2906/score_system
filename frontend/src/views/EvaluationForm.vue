<script setup>
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { api, ApiError } from '../api'
import { t, pick } from '../i18n'
import { session, isUnitOnly } from '../session'
import { confirmDialog } from '../dialog'

const router = useRouter()
// Có id => chế độ SỬA bài đã nộp (admin/editor), không có => nộp bài mới
const props = defineProps({ id: { type: String, default: null } })
const editing = !!props.id
const editMode = ref('full')          // full | organization_only (bài nhập từ hệ cũ)
const existingFiles = ref([])          // tệp đã có của bài đang sửa
const removeIds = ref(new Set())       // tệp đã có mà người dùng chọn xoá

// Thứ tự hiển thị chức năng giống form cũ (Postgres không giữ thứ tự khoá JSON)
const FUNCTION_ORDER = ['basic', 'applied', 'tech', 'policy']
const DRAFT_KEY = `score_form_draft_v2_${session.user.id}`

const definition = ref(null)
const loadError = ref('')
const submitting = ref(false)
const draftRestored = ref(false)
const errors = ref({})         // { 'answers.basic.q1.yes': 'thông báo' }
const serverErrors = ref([])   // danh sách lỗi máy chủ trả về

const form = reactive({ organization_name: '', selected: [], weights: {}, answers: {} })
// Tệp không lưu được vào bản nháp (trình duyệt không cho), giữ riêng
const files = reactive({})

const unitOnly = !editing && isUnitOnly()
const functionKeys = computed(() => {
  const keys = Object.keys(definition.value?.functions || {})
  return [...FUNCTION_ORDER.filter((k) => keys.includes(k)), ...keys.filter((k) => !FUNCTION_ORDER.includes(k))]
})
const weightSum = computed(() => form.selected.reduce((sum, f) => sum + Number(form.weights[f] || 0), 0))

const isQuantitative = (q) => q.display_mode === 'quantitative' || !!q.inputs?.length
const fieldId = (path) => 'f-' + path.replaceAll('.', '-')

// Tạo sẵn chỗ chứa câu trả lời cho các chức năng được chọn (trước khi vẽ form)
function ensureAnswers() {
  if (!definition.value) return
  for (const func of form.selected) {
    form.answers[func] ??= {}
    for (const group of definition.value.functions[func]?.groups || []) {
      for (const q of group.criteria) {
        const a = (form.answers[func][q.id] ??= { yes: '', inputs: {}, note: '', evidence_text: '' })
        a.inputs ??= {}
        a.note ??= ''
        a.evidence_text ??= ''
      }
    }
  }
}
watch([() => [...form.selected], definition], ensureAnswers, { immediate: true, flush: 'sync' })

const answer = (func, qid) => form.answers[func][qid]

function fileList(func, qid) {
  return files[func]?.[qid] || []
}

const keptFiles = (func, qid) =>
  existingFiles.value.filter((a) => a.question_key === `${func}.${qid}` && !removeIds.value.has(a.id))
const filesOf = (func, qid) => existingFiles.value.filter((a) => a.question_key === `${func}.${qid}`)

function toggleRemove(id, func, qid) {
  const next = new Set(removeIds.value)
  next.has(id) ? next.delete(id) : next.add(id)
  removeIds.value = next
  clearError(`answers.${func}.${qid}.evidence_text`)
}

function onFiles(func, qid, event) {
  files[func] ??= {}
  files[func][qid] = [...event.target.files]
  clearError(`answers.${func}.${qid}.evidence_text`)
}

function clearError(path) {
  if (errors.value[path]) {
    const next = { ...errors.value }
    delete next[path]
    errors.value = next
  }
}

// ---- Bản nháp: tự lưu trên trình duyệt như form cũ ----
function loadDraft() {
  try {
    const saved = JSON.parse(localStorage.getItem(DRAFT_KEY) || 'null')
    if (saved && typeof saved === 'object') {
      Object.assign(form, saved)
      draftRestored.value = saved.selected?.length > 0 || !!saved.organization_name
    }
  } catch { /* bản nháp hỏng thì bỏ qua */ }
}

async function clearDraft() {
  if (!(await confirmDialog(t('form.confirm_clear')))) return
  try { localStorage.removeItem(DRAFT_KEY) } catch { /* bỏ qua */ }
  Object.assign(form, { organization_name: '', selected: [], weights: {}, answers: {} })
  Object.keys(files).forEach((k) => delete files[k])
  errors.value = {}
  serverErrors.value = []
  draftRestored.value = false
}

let saveTimer = null
watch(form, () => {
  if (editing) return
  clearTimeout(saveTimer)
  saveTimer = setTimeout(() => {
    try { localStorage.setItem(DRAFT_KEY, JSON.stringify(form)) } catch { /* bỏ qua */ }
  }, 400)
}, { deep: true })

onMounted(async () => {
  try {
    if (editing) {
      await loadForEdit()
    } else {
      loadDraft()
      definition.value = (await api.get('/criteria/active')).definition
    }
  } catch (e) {
    loadError.value = e.status === 403 ? t('common.no_access') : e.message
  }
})

// Sửa bài: nạp bài + đúng phiên bản bộ tiêu chí bài đó đã dùng, điền sẵn vào form
async function loadForEdit() {
  const e = await api.get(`/evaluations/${props.id}`)
  if (!e.can_edit) throw Object.assign(new Error(t('common.no_access')), { status: 403 })
  editMode.value = e.edit_mode
  form.organization_name = e.organization.submitted_name
  if (e.edit_mode === 'organization_only') {
    definition.value = { functions: {} }
    return
  }
  const criteria = await api.get(`/criteria/${e.criteria_version_id}`)
  existingFiles.value = e.attachments || []
  form.selected = [...e.functions]
  form.weights = Object.fromEntries(Object.entries(e.answers.weights || {}).map(([k, v]) => [k, String(v)]))
  form.answers = JSON.parse(JSON.stringify(e.answers.answers || {}))
  definition.value = criteria.definition
}

// ---- Kiểm tra trước khi nộp (giống script.js cũ) ----
function validate() {
  const errs = {}
  let first = null
  const add = (path, message) => {
    errs[path] = message
    first ??= { path, message }
  }

  if (!unitOnly && !form.organization_name.trim()) add('organization_name', t('form.alert_missing_org'))
  if (editMode.value === 'organization_only') {
    errors.value = errs
    return first
  }
  if (!form.selected.length) add('functions', t('form.alert_missing_function'))

  for (const func of form.selected) {
    if (form.weights[func] === '' || form.weights[func] == null) add(`weights.${func}`, t('form.alert_missing_number'))
  }
  if (form.selected.length && Math.abs(weightSum.value - 100) > 0.00001) add('weights', t('form.alert_weight_sum'))

  for (const func of form.selected) {
    for (const group of definition.value.functions[func].groups) {
      for (const q of group.criteria) {
        const a = answer(func, q.id)
        const p = `answers.${func}.${q.id}`
        if (a.yes === '') add(`${p}.yes`, t('form.alert_missing_yes_no'))
        for (const input of q.inputs || []) {
          if (String(a.inputs[input.name] ?? '').trim() === '') add(`${p}.inputs.${input.name}`, t('form.alert_missing_number'))
        }
        if (!isQuantitative(q) && !a.note.trim()) add(`${p}.note`, t('form.alert_missing_note'))
        if (!a.evidence_text.trim() && !fileList(func, q.id).length && !keptFiles(func, q.id).length) add(`${p}.evidence_text`, t('form.alert_missing_evidence'))
      }
    }
  }

  errors.value = errs
  return first
}

async function focusField(path) {
  await nextTick()
  const el = document.getElementById(fieldId(path)) || document.getElementById(fieldId(path.split('.').slice(0, 1).join('.')))
  el?.scrollIntoView({ behavior: 'smooth', block: 'center' })
  el?.focus?.({ preventScroll: true })
}

function buildFormData() {
  const fd = new FormData()
  if (!unitOnly) fd.append('organization_name', form.organization_name.trim())
  if (editing) {
    fd.append('_method', 'PUT')
    for (const id of removeIds.value) fd.append('remove_attachments[]', id)
  }
  for (const func of form.selected) {
    fd.append('functions[]', func)
    fd.append(`weights[${func}]`, form.weights[func])
    for (const group of definition.value.functions[func].groups) {
      for (const q of group.criteria) {
        const a = answer(func, q.id)
        const p = `answers[${func}][${q.id}]`
        fd.append(`${p}[yes]`, a.yes)
        for (const input of q.inputs || []) fd.append(`${p}[inputs][${input.name}]`, a.inputs[input.name])
        if (a.note.trim()) fd.append(`${p}[note]`, a.note)
        if (a.evidence_text.trim()) fd.append(`${p}[evidence_text]`, a.evidence_text)
        for (const file of fileList(func, q.id)) fd.append(`evidence[${func}][${q.id}][]`, file)
      }
    }
  }
  return fd
}

async function submit() {
  serverErrors.value = []
  const first = validate()
  if (first) {
    focusField(first.path)
    return
  }

  const totalMb = form.selected
    .flatMap((f) => Object.values(files[f] || {}).flat())
    .reduce((sum, file) => sum + file.size, 0) / 1048576
  if (totalMb > 50 && !(await confirmDialog(t('form.confirm_large_file', { mb: totalMb.toFixed(1) })))) return

  submitting.value = true
  try {
    if (editing) {
      await api.post(`/evaluations/${props.id}`, buildFormData())
      router.push({ path: `/evaluations/${props.id}`, query: { updated: 1 } })
      return
    }
    const evaluation = await api.post('/evaluations', buildFormData())
    try { localStorage.removeItem(DRAFT_KEY) } catch { /* bỏ qua */ }
    router.push({ path: `/evaluations/${evaluation.id}`, query: { new: 1 } })
  } catch (e) {
    if (e instanceof ApiError && e.status === 422) {
      errors.value = Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v[0]]))
      serverErrors.value = Object.values(errors.value)
      const firstKey = Object.keys(e.errors)[0]
      if (firstKey) focusField(firstKey)
    } else {
      serverErrors.value = [e.message]
      window.scrollTo({ top: 0, behavior: 'smooth' })
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <h1>{{ editing ? t('edit.h1') : t('form.h1') }}</h1>

  <div v-if="loadError" class="report-banner report-banner-error">{{ t('common.error') }} {{ loadError }}</div>
  <p v-else-if="!definition">{{ t('common.loading') }}</p>

  <form v-else novalidate @submit.prevent="submit">
    <div v-if="editing" class="info-box">
      {{ editMode === 'organization_only' ? t('edit.info_legacy') : t('edit.info') }}
    </div>
    <div v-if="draftRestored && !editing" class="info-box">{{ t('form.draft_restored') }}</div>

    <div v-if="serverErrors.length" class="report-banner report-banner-error">
      <div>
        <strong>{{ t('form.server_errors') }}</strong>
        <ul><li v-for="(m, i) in serverErrors.slice(0, 10)" :key="i">{{ m }}</li></ul>
      </div>
    </div>

    <template v-if="unitOnly">
      <div class="info-box">{{ t('form.org_fixed', { name: session.user.unit_name }) }}</div>
    </template>
    <template v-else>
      <label for="f-organization_name">{{ t('form.org_label') }} <span style="color:red">*</span></label>
      <input
        id="f-organization_name"
        v-model="form.organization_name"
        type="text"
        autocomplete="organization"
        :class="{ 'has-error': errors.organization_name }"
        :placeholder="t('form.org_placeholder')"
        @input="clearError('organization_name')">
      <div v-if="errors.organization_name" class="field-error">{{ errors.organization_name }}</div>
    </template>

    <template v-if="editMode !== 'organization_only'">
    <h3>{{ t('form.function_heading') }}</h3>
    <div id="function-checkboxes">
      <label v-for="(key, i) in functionKeys" :key="key">
        <input
          :id="i === 0 ? 'f-functions' : undefined"
          v-model="form.selected"
          type="checkbox"
          :value="key"
          style="width:auto"
          @change="clearError('functions')">
        {{ pick(definition.functions[key], 'name') }}
      </label>
    </div>
    <div v-if="errors.functions" class="field-error">{{ errors.functions }}</div>

    <template v-for="func in functionKeys" :key="func">
      <section v-if="form.selected.includes(func)" :id="`section-${func}`" class="function-card">
        <h2>{{ pick(definition.functions[func], 'name') }}</h2>

        <div class="weight-box">
          <label :for="fieldId(`weights.${func}`)">{{ t('form.weight_label') }} <span style="color:red">*</span></label>
          <input
            :id="fieldId(`weights.${func}`)"
            v-model="form.weights[func]"
            type="number"
            min="0"
            max="100"
            :class="{ 'has-error': errors[`weights.${func}`] || errors.weights }"
            @input="clearError(`weights.${func}`); clearError('weights')">
          <small :style="{ color: Math.abs(weightSum - 100) < 0.00001 ? '#15803d' : '#b45309' }">
            {{ t('form.weight_sum', { sum: weightSum }) }}
          </small>
          <div v-if="errors[`weights.${func}`] || errors.weights" class="field-error">
            {{ errors[`weights.${func}`] || errors.weights }}
          </div>
        </div>

        <div v-for="group in definition.functions[func].groups" :key="group.id" class="group-block">
          <h3>{{ pick(group, 'title') }} ({{ group.max }} {{ t('form.points') }})</h3>

          <div v-for="q in group.criteria" :key="q.id" class="question-row">
            <div class="q-main">
              <strong>{{ pick(q, 'text') }}</strong>
              <small>{{ t('form.group_label') }}{{ group.id }} | {{ t('form.max_label') }}{{ q.max }} {{ t('form.points') }}</small>
            </div>

            <div>
              <label :for="fieldId(`answers.${func}.${q.id}.yes`)">{{ t('form.answer_label') }} <span style="color:red">*</span></label>
              <select
                :id="fieldId(`answers.${func}.${q.id}.yes`)"
                v-model="answer(func, q.id).yes"
                :class="{ 'has-error': errors[`answers.${func}.${q.id}.yes`] }"
                @change="clearError(`answers.${func}.${q.id}.yes`)">
                <option value="">{{ t('form.select_placeholder') }}</option>
                <option value="1">{{ t('form.yes') }}</option>
                <option value="0">{{ t('form.no') }}</option>
              </select>
              <div v-if="errors[`answers.${func}.${q.id}.yes`]" class="field-error">{{ errors[`answers.${func}.${q.id}.yes`] }}</div>
            </div>

            <div v-for="input in q.inputs || []" :key="input.name">
              <label :for="fieldId(`answers.${func}.${q.id}.inputs.${input.name}`)">{{ pick(input, 'label') }} <span style="color:red">*</span></label>
              <input
                :id="fieldId(`answers.${func}.${q.id}.inputs.${input.name}`)"
                v-model="answer(func, q.id).inputs[input.name]"
                type="number"
                step="any"
                min="0"
                :class="{ 'has-error': errors[`answers.${func}.${q.id}.inputs.${input.name}`] }"
                @input="clearError(`answers.${func}.${q.id}.inputs.${input.name}`)">
              <div v-if="errors[`answers.${func}.${q.id}.inputs.${input.name}`]" class="field-error">
                {{ errors[`answers.${func}.${q.id}.inputs.${input.name}`] }}
              </div>
            </div>

            <div v-if="!isQuantitative(q)">
              <label :for="fieldId(`answers.${func}.${q.id}.note`)">{{ t('form.note_label') }} <span style="color:red">*</span></label>
              <textarea
                :id="fieldId(`answers.${func}.${q.id}.note`)"
                v-model="answer(func, q.id).note"
                :class="{ 'has-error': errors[`answers.${func}.${q.id}.note`] }"
                :placeholder="pick(q, 'note_placeholder') || t('form.note_placeholder_default')"
                @input="clearError(`answers.${func}.${q.id}.note`)" />
              <div v-if="errors[`answers.${func}.${q.id}.note`]" class="field-error">{{ errors[`answers.${func}.${q.id}.note`] }}</div>
            </div>

            <div class="evidence-box">
              <label :for="fieldId(`answers.${func}.${q.id}.evidence_text`)">{{ t('form.evidence_label') }} <span style="color:red">*</span></label>
              <textarea
                :id="fieldId(`answers.${func}.${q.id}.evidence_text`)"
                v-model="answer(func, q.id).evidence_text"
                :class="{ 'has-error': errors[`answers.${func}.${q.id}.evidence_text`] }"
                :placeholder="t('form.evidence_placeholder')"
                @input="clearError(`answers.${func}.${q.id}.evidence_text`)" />
              <div v-for="f in filesOf(func, q.id)" :key="f.id" class="existing-file" :class="{ removed: removeIds.has(f.id) }">
                <span>{{ f.name }}</span>
                <button type="button" class="link-btn" @click="toggleRemove(f.id, func, q.id)">
                  {{ removeIds.has(f.id) ? t('edit.keep_file') : t('edit.remove_file') }}
                </button>
              </div>
              <input type="file" multiple @change="onFiles(func, q.id, $event)">
              <div v-if="errors[`answers.${func}.${q.id}.evidence_text`]" class="field-error">
                {{ errors[`answers.${func}.${q.id}.evidence_text`] }}
              </div>
              <div v-for="(m, i) in Object.entries(errors).filter(([k]) => k.startsWith(`evidence.${func}.${q.id}`))" :key="i" class="field-error">{{ m[1] }}</div>
              <small>
                {{ t('form.evidence_help') }}
                <br><em>{{ t('form.evidence_note') }}</em>
              </small>
            </div>
          </div>
        </div>
      </section>
    </template>

    </template>

    <div class="sticky-submit">
      <button type="submit" :disabled="submitting">
        {{ submitting ? t('form.submitting') : editing ? t('edit.save') : t('form.submit_btn') }}
      </button>
      <button v-if="!editing" type="button" class="btn-secondary" :disabled="submitting" @click="clearDraft">{{ t('form.clear_draft') }}</button>
      <button v-else type="button" class="btn-secondary" :disabled="submitting" @click="router.push(`/evaluations/${props.id}`)">{{ t('edit.cancel') }}</button>
      <small v-if="form.selected.length && editMode !== 'organization_only'">{{ t('form.weight_sum', { sum: weightSum }) }}</small>
    </div>
  </form>
</template>
