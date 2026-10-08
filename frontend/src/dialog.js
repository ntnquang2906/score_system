import { reactive } from 'vue'

// Hộp xác nhận tự vẽ (thay confirm() của trình duyệt có dòng "... says")
export const dialog = reactive({ open: false, message: '', resolve: null })

/** await confirmDialog('Xoá?') -> true/false */
export function confirmDialog(message) {
  return new Promise((resolve) => Object.assign(dialog, { open: true, message, resolve }))
}

export function closeDialog(result) {
  dialog.resolve?.(result)
  Object.assign(dialog, { open: false, message: '', resolve: null })
}
