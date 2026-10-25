<script setup lang="ts">
import type { FocusTarget } from '~/types/ui'

const props = defineProps<{
  title?: string
  message?: string | null
}>()

const summary = useTemplateRef<FocusTarget>('summary')

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
    <strong>{{ title ?? 'Check the form' }}</strong>
    <UiText>{{ message }}</UiText>
  </div>
</template>
