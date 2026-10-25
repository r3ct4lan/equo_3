<script setup lang="ts">
import type { TextTag, TextVariant } from '~/types/ui'

const props = withDefaults(defineProps<{ variant?: TextVariant, as?: TextTag }>(), {
  variant: 'body', as: undefined
})
const tags: Record<TextVariant, TextTag> = {
  body: 'p', heading1: 'h1', heading2: 'h2', heading3: 'h3', lede: 'p',
  eyebrow: 'p', help: 'p', caption: 'p', amount: 'p'
}
const classes: Record<TextVariant, string> = {
  body: 'ui-text', heading1: 'ui-heading-1', heading2: 'ui-heading-2', heading3: 'ui-heading-3',
  lede: 'hero__lede', eyebrow: 'eyebrow', help: 'form-help', caption: 'ui-caption', amount: 'ui-amount'
}
</script>

<template>
  <component
    :is="props.as ?? tags[variant]"
    :class="classes[variant]"
  >
    <slot />
  </component>
</template>
