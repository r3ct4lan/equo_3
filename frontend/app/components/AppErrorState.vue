<script setup lang="ts">
import { safeRequestId } from '~/utils/api-error'

withDefaults(defineProps<{
  title?: string
  message: string
  retryLabel?: string
  requestId?: string | null
}>(), {
  title: 'Something went wrong',
  retryLabel: undefined
})

defineEmits<{
  retry: []
}>()

const { t, tError } = useTranslation()
</script>

<template>
  <UiNotice tone="error">
    <div>
      <strong>{{ tError(title) }}</strong>
      <UiText>{{ tError(message) }}</UiText>
      <UiText
        v-if="safeRequestId(requestId)"
        class="support-reference"
      >
        {{ t('Support reference:') }} <code>{{ safeRequestId(requestId) }}</code>
      </UiText>
    </div>
    <UiButton
      v-if="retryLabel"
      variant="secondary"
      @click="$emit('retry')"
    >
      {{ t(retryLabel) }}
    </UiButton>
  </UiNotice>
</template>
