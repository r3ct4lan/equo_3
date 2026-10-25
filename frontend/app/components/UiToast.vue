<script setup lang="ts">
import type { NoticeTone } from '~/types/ui'

withDefaults(defineProps<{ tone?: NoticeTone }>(), { tone: 'info' })
</script>

<template>
  <UiNotice
    class="invitation-toast"
    :tone="tone"
  >
    <div class="stack">
      <slot />
    </div>
  </UiNotice>
</template>
