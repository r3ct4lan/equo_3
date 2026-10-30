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

const { t, tError } = useTranslation()
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
    <strong>{{ tError(title ?? 'Check the form') }}</strong>
    <UiText>{{ tError(message) }}</UiText>
    <UiText
      v-if="safeRequestId(requestId)"
      class="support-reference"
    >
      {{ t('Support reference:') }} <code>{{ safeRequestId(requestId) }}</code>
    </UiText>
  </div>
</template>
