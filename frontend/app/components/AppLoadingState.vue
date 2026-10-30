<script setup lang="ts">
withDefaults(defineProps<{
  message?: string
}>(), {
  message: 'Loading…'
})
const { t } = useTranslation()
</script>

<template>
  <UiNotice
    aria-live="polite"
    busy
  >
    <UiSpinner />
    <span>{{ t(message) }}</span>
  </UiNotice>
</template>
