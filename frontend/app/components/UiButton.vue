<script setup lang="ts">
import { NuxtLink } from '#components'
import { RiAddLine, RiCloseLine } from '@remixicon/vue'
import { Button as RegistryButton } from '~/components/ui/button'
import type { ButtonSize, ButtonVariant, LinkTo } from '~/types/ui'

const props = withDefaults(defineProps<{
  variant?: ButtonVariant
  size?: ButtonSize
  type?: 'button' | 'submit' | 'reset'
  to?: LinkTo
  disabled?: boolean
  pending?: boolean
  pendingLabel?: string
  compact?: boolean
  icon?: 'add' | 'close'
}>(), {
  variant: 'primary',
  size: 'default',
  type: 'button',
  to: undefined,
  disabled: false,
  pending: false,
  pendingLabel: undefined
})

const root = useTemplateRef<HTMLElement | { $el: HTMLElement }>('root')
const blocked = computed(() => props.disabled || props.pending)
const registryVariants = { primary: 'default', secondary: 'secondary', danger: 'destructive', link: 'link' } as const
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
  <RegistryButton
    ref="root"
    :as="to !== undefined ? NuxtLink : 'button'"
    :variant="registryVariants[variant]"
    :size="size"
    class="button"
    :class="['button--' + variant, { 'button--compact': compact }]"
    :to="to !== undefined && !blocked ? to : undefined"
    :type="to === undefined ? type : undefined"
    :disabled="to === undefined ? blocked : undefined"
    :aria-disabled="to !== undefined && blocked ? true : undefined"
    :tabindex="to !== undefined && blocked ? -1 : undefined"
    :aria-busy="pending"
    @click.capture="guardClick"
  >
    <RiAddLine
      v-if="icon === 'add'"
      aria-hidden="true"
      focusable="false"
    />
    <RiCloseLine
      v-if="icon === 'close'"
      aria-hidden="true"
      focusable="false"
    />
    <span v-if="pending && pendingLabel">{{ pendingLabel }}</span>
    <slot v-else />
  </RegistryButton>
</template>
