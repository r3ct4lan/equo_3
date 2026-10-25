<script setup lang="ts">
import type { CardTag } from '~/types/ui'

withDefaults(defineProps<{ as?: CardTag }>(), { as: 'section' })
const root = useTemplateRef<HTMLElement>('root')
defineExpose({ focus: () => root.value?.focus() })
</script>

<template>
  <component
    :is="as"
    ref="root"
    class="panel"
  >
    <slot />
  </component>
</template>
