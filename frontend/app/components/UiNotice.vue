<script setup lang="ts">
import type { NoticeTone } from '~/types/ui'

const props = withDefaults(defineProps<{ tone?: NoticeTone, busy?: boolean }>(), { tone: 'info', busy: false })
const root = useTemplateRef<HTMLElement>('root')
const role = computed(() => props.tone === 'error' ? 'alert' : props.tone === 'neutral' ? undefined : 'status')
defineExpose({ focus: () => root.value?.focus() })
</script>

<template>
  <div
    ref="root"
    class="state-message"
    :class="'state-message--' + tone"
    :role="role"
    :aria-busy="busy || undefined"
  >
    <slot name="marker">
      <span
        v-if="tone === 'success'"
        class="status-card__marker"
        aria-hidden="true"
      />
    </slot>
    <slot />
  </div>
</template>
