<script setup lang="ts">
import type { ButtonVariant } from '~/types/ui'

withDefaults(defineProps<{
  variant?: ButtonVariant
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
  <UiButton
    :variant="variant"
    type="submit"
    :disabled="disabled"
    :pending="pending"
    :pending-label="pendingLabel"
  >
    <slot />
  </UiButton>
</template>
