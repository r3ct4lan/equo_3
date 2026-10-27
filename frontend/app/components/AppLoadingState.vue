<script setup lang="ts">
withDefaults(defineProps<{
  message?: string
}>(), {
  message: 'Loading…'
})
</script>

<template>
  <UiNotice
    aria-live="polite"
    busy
  >
    <UiSpinner />
    <span>{{ message }}</span>
  </UiNotice>
</template>
