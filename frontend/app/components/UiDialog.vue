<script setup lang="ts">
const dialog = useTemplateRef<HTMLDialogElement>('dialog')
defineExpose({
  get open() { return dialog.value?.open ?? false },
  showModal: () => dialog.value?.showModal(),
  close: () => dialog.value?.close(),
  focusFirst: (selector: string) => dialog.value?.querySelector<HTMLElement>(selector)?.focus()
})
</script>

<template>
  <dialog
    ref="dialog"
    class="ui-dialog"
  >
    <div
      v-if="$slots.actions"
      class="ui-dialog__actions"
    >
      <slot name="actions" />
    </div>
    <slot />
  </dialog>
</template>
