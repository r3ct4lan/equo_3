<script setup lang="ts">
import type { LinkTo } from '~/types/ui'
import { formatMoney } from '~/utils/money'

const { locale } = useLocale()

defineProps<{ titleId: string, title: string, amount: number, currency?: string, to: LinkTo, linkLabel: string }>()
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
      {{ formatMoney(amount, currency, locale) }}
    </UiText>
    <UiLink
      class="ui-caption dashboard-summary-link"
      :to="to"
    >
      {{ linkLabel }}
    </UiLink>
  </UiCard>
</template>
