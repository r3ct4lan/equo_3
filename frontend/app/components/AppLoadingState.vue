<script setup lang="ts">
withDefaults(defineProps<{
  message?: string
}>(), {
  message: 'Loading…'
})
</script>

<template>
  <div
    class="state-message"
    role="status"
    aria-live="polite"
    aria-busy="true"
  >
    <span
      class="spinner"
      aria-hidden="true"
    />
    <span>{{ message }}</span>
  </div>
</template>
