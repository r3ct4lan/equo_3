<script setup lang="ts">
import { NuxtLink } from '#components'
import type { LinkTo, LinkVariant } from '~/types/ui'

withDefaults(defineProps<{
  to?: LinkTo
  href?: string
  variant?: LinkVariant
}>(), { to: undefined, href: undefined, variant: 'text' })
</script>

<template>
  <component
    :is="to !== undefined ? NuxtLink : 'a'"
    :to="to"
    :href="href"
    :class="{ 'ui-link': variant === 'text', 'nav-link': variant === 'nav', 'brand': variant === 'brand', 'skip-link': variant === 'skip' }"
  >
    <slot />
  </component>
</template>
