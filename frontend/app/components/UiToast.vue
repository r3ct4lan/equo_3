<script setup lang="ts">
import type { NoticeTone } from '~/types/ui'
import { RiInformationFill, RiCheckDoubleFill, RiErrorWarningFill, RiChat3Line, RiErrorWarningLine } from '@remixicon/vue'

withDefaults(defineProps<{ tone?: NoticeTone }>(), { tone: 'info' })
defineEmits<{ dismiss: [] }>()
const icons = { info: RiInformationFill, success: RiCheckDoubleFill, error: RiErrorWarningFill, neutral: RiChat3Line, warning: RiErrorWarningLine }
</script>

<template>
  <UiNotice
    class="invitation-toast"
    :tone="tone"
  >
    <template #marker>
      <component
        :is="icons[tone]"
        class="ui-toast__icon"
        aria-hidden="true"
      />
    </template>
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
