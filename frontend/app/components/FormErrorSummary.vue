<script setup lang="ts">
import type { FocusTarget } from '~/types/ui'
import { safeRequestId } from '~/utils/api-error'

const props = defineProps<{
  title?: string
  message?: string | null
  requestId?: string | null
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
    <UiText
      v-if="safeRequestId(requestId)"
      class="support-reference"
    >
      Support reference: <code>{{ safeRequestId(requestId) }}</code>
    </UiText>
  </div>
</template>
