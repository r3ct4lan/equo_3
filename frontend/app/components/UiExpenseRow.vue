<script setup lang="ts">
import type { LinkTo } from '~/types/ui'
import { formatEuro } from '~/utils/money'

defineProps<{ to: LinkTo, title: string, amount: number, payerName: string, createdAt: string }>()
const formatter = new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium' })
</script>

<template>
  <UiCard
    as="li"
    class="dashboard-expense"
  >
    <div class="dashboard-expense-details">
      <UiLink
        class="dashboard-expense-title"
        :to="to"
      >
        {{ title }}
      </UiLink>
      <time
        class="ui-caption"
        :datetime="createdAt"
      >{{ formatter.format(new Date(createdAt)) }}</time>
    </div>
    <div class="dashboard-expense-cost">
      <strong>{{ formatEuro(amount) }}</strong>
      <UiText
        as="span"
        variant="caption"
      >
        Paid by {{ payerName }}
      </UiText>
    </div>
  </UiCard>
</template>
