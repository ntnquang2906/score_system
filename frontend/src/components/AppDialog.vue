<script setup>
import { nextTick, ref, watch } from 'vue'
import { dialog, closeDialog } from '../dialog'
import { t } from '../i18n'

const okButton = ref(null)
watch(() => dialog.open, async (open) => {
  if (open) {
    await nextTick()
    okButton.value?.focus()
  }
})
</script>

<template>
  <div v-if="dialog.open" class="app-dialog-overlay" @keydown.esc="closeDialog(false)">
    <div class="app-dialog" role="alertdialog" aria-modal="true">
      <div class="app-dialog-title">{{ t('dialog.title') }}</div>
      <div class="app-dialog-body">{{ dialog.message }}</div>
      <div class="app-dialog-actions">
        <button type="button" class="secondary" @click="closeDialog(false)">{{ t('dialog.cancel') }}</button>
        <button ref="okButton" type="button" @click="closeDialog(true)">OK</button>
      </div>
    </div>
  </div>
</template>
