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
</script>

<template>
  <UiNotice tone="error">
    <div>
      <strong>{{ title }}</strong>
      <UiText>{{ message }}</UiText>
      <UiText
        v-if="safeRequestId(requestId)"
        class="support-reference"
      >
        Support reference: <code>{{ safeRequestId(requestId) }}</code>
      </UiText>
    </div>
    <UiButton
      v-if="retryLabel"
      variant="secondary"
      @click="$emit('retry')"
    >
      {{ retryLabel }}
    </UiButton>
  </UiNotice>
</template>
