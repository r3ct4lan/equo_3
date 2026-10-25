<script setup lang="ts">
import type { HTMLAttributes } from 'vue'
import type { CardTag } from '~/types/ui'
import { cn } from 'cn'

const props = withDefaults(defineProps<{
  class?: HTMLAttributes['class']
  size?: 'default' | 'sm'
  as?: CardTag
}>(), {
  size: 'default',
  as: 'div'
})
</script>

<template>
  <component
    :is="as"
    data-slot="card"
    :data-size="size"
    :class="cn('bg-card text-card-foreground ring-foreground/5 dark:ring-foreground/10 gap-6 overflow-hidden rounded-4xl py-6 text-sm shadow-md ring-1 has-[>img:first-child]:pt-0 data-[size=sm]:gap-4 data-[size=sm]:py-4 *:[img:first-child]:rounded-t-4xl *:[img:last-child]:rounded-b-4xl group/card flex flex-col', props.class)"
  >
    <slot />
  </component>
</template>
