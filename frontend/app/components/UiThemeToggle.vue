<script setup lang="ts">
import { RiMoonClearLine, RiSunLine } from '@remixicon/vue'
import type { ButtonVariant } from '~/types/ui'
import UiButton from '~/components/UiButton.vue'
import { useTheme } from '~/composables/useTheme.ts'

withDefaults(defineProps<{ variant?: ButtonVariant }>(), { variant: 'secondary' })
const { dark, toggle } = useTheme()
</script>

<template>
  <UiButton
    :variant="variant"
    class="theme-toggle"
    :aria-label="dark ? 'Switch to light theme' : 'Switch to dark theme'"
    :aria-pressed="dark"
    @click="toggle"
  >
    <RiMoonClearLine
      v-if="dark"
      aria-hidden="true"
      focusable="false"
    />
    <RiSunLine
      v-else
      aria-hidden="true"
      focusable="false"
    />
  </UiButton>
</template>
