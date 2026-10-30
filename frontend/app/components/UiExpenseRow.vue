<script setup lang="ts">
import { formatDate } from '~/utils/locale'
import type { LinkTo } from '~/types/ui'
import { formatEuro as formatEuroBase } from '~/utils/money'

const { locale } = useLocale()
const { t } = useTranslation()
const formatEuro = (cents: number) => formatEuroBase(cents, locale.value)

defineProps<{ to: LinkTo, title: string, amount: number, payerName: string, createdAt: string }>()
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
      >{{ formatDate(new Date(createdAt), locale) }}</time>
    </div>
    <div class="dashboard-expense-cost">
      <strong>{{ formatEuro(amount) }}</strong>
      <UiText
        as="span"
        variant="caption"
      >
        {{ t('Paid by') }} {{ payerName }}
      </UiText>
    </div>
  </UiCard>
</template>
