<script setup lang="ts">
import { NuxtLink } from '#components'
import type { ButtonVariant, LinkTo } from '~/types/ui'

const props = withDefaults(defineProps<{
  variant?: ButtonVariant
  type?: 'button' | 'submit' | 'reset'
  to?: LinkTo
  disabled?: boolean
  pending?: boolean
  pendingLabel?: string
}>(), {
  variant: 'primary',
  type: 'button',
  to: undefined,
  disabled: false,
  pending: false,
  pendingLabel: undefined
})

const root = useTemplateRef<HTMLElement | { $el: HTMLElement }>('root')
const blocked = computed(() => props.disabled || props.pending)
defineExpose({ focus: () => {
  const element = root.value
  if (element) ('focus' in element ? element : element.$el).focus()
} })

function guardClick(event: MouseEvent): void {
  if (blocked.value) {
    event.preventDefault()
    event.stopImmediatePropagation()
  }
}
</script>

<template>
  <component
    :is="to !== undefined ? NuxtLink : 'button'"
    ref="root"
    class="button"
    :class="'button--' + variant"
    :to="to !== undefined && !blocked ? to : undefined"
    :type="to === undefined ? type : undefined"
    :disabled="to === undefined ? blocked : undefined"
    :aria-disabled="to !== undefined && blocked ? true : undefined"
    :tabindex="to !== undefined && blocked ? -1 : undefined"
    :aria-busy="pending"
    @click.capture="guardClick"
  >
    <span v-if="pending && pendingLabel">{{ pendingLabel }}</span>
    <slot v-else />
  </component>
</template>
