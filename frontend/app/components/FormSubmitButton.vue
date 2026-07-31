<script setup lang="ts">
withDefaults(defineProps<{
  pending?: boolean
  disabled?: boolean
  pendingLabel?: string
}>(), {
  pending: false,
  disabled: false,
  pendingLabel: 'Submitting…'
})
</script>

<template>
  <button
    class="button"
    type="submit"
    :disabled="disabled || pending"
    :aria-busy="pending"
  >
    <span v-if="pending">{{ pendingLabel }}</span>
    <slot v-else />
  </button>
</template>
