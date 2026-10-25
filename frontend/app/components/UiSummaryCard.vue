<script setup lang="ts">
import type { LinkTo } from '~/types/ui'
import { formatEuro } from '~/utils/money'

defineProps<{ titleId: string, title: string, amount: number, to: LinkTo, linkLabel: string }>()
</script>

<template>
  <UiCard
    class="dashboard-balance"
    :aria-labelledby="titleId"
  >
    <UiText
      :id="titleId"
      variant="heading2"
    >
      {{ title }}
    </UiText>
    <UiText variant="amount">
      {{ formatEuro(amount) }}
    </UiText>
    <UiLink
      class="ui-caption dashboard-summary-link"
      :to="to"
    >
      {{ linkLabel }}
    </UiLink>
  </UiCard>
</template>
