<script setup lang="ts">
const props = defineProps<{
  message?: string | null
}>()

const summary = useTemplateRef<HTMLElement>('summary')

watch(() => props.message, async (message) => {
  if (!message) {
    return
  }

  await nextTick()
  summary.value?.focus()
})
</script>

<template>
  <div
    v-if="message"
    ref="summary"
    class="form-error-summary"
    role="alert"
    aria-live="assertive"
    tabindex="-1"
  >
    <strong>Check the form</strong>
    <p>{{ message }}</p>
  </div>
</template>
