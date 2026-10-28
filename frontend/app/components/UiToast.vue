<script setup lang="ts">
import type { NoticeTone } from '~/types/ui'

withDefaults(defineProps<{ tone?: NoticeTone }>(), { tone: 'info' })
defineEmits<{ dismiss: [] }>()
</script>

<template>
  <UiNotice
    class="invitation-toast"
    :tone="tone"
  >
    <div class="toast-content stack">
      <slot />
    </div>
    <UiButton
      variant="link"
      icon="close"
      class="ui-toast__close"
      aria-label="Dismiss notification"
      @click="$emit('dismiss')"
    />
  </UiNotice>
</template>
