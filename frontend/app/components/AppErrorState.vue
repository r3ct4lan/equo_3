<script setup lang="ts">
withDefaults(defineProps<{
  title?: string
  message: string
  retryLabel?: string
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
