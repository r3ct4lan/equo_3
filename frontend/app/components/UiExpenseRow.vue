<script setup lang="ts">
import { formatDate, formatPeople } from '~/utils/locale'
import type { LinkTo } from '~/types/ui'
import { formatMoney } from '~/utils/money'

const { locale } = useLocale()
const { t } = useTranslation()

const props = defineProps<{
  to: LinkTo
  title: string
  amount: number
  currency?: string
  viewerCurrency?: string
  equivalentAmount?: number | null
  payerName: string
  createdAt: string
  isDeleted?: boolean
  payerInactive?: boolean
  split?: { participantCount: number, shareAmount: number | null }
}>()
const showOriginal = computed(() => props.viewerCurrency && props.viewerCurrency !== (props.currency ?? 'EUR'))
</script>

<template>
  <UiCard
    as="li"
    class="dashboard-expense"
    :class="{ 'dashboard-expense--deleted': isDeleted }"
  >
    <div class="dashboard-expense-head">
      <div class="dashboard-expense-cost">
        <strong v-if="showOriginal && equivalentAmount != null">{{ formatMoney(equivalentAmount, viewerCurrency, locale) }}</strong>
        <strong v-else-if="showOriginal">{{ t('Exchange rate unavailable') }}</strong>
        <strong v-else>{{ formatMoney(amount, currency, locale) }}</strong>
        <UiText
          v-if="showOriginal"
          as="small"
          variant="caption"
        >
          {{ formatMoney(amount, currency, locale) }}
        </UiText>
      </div>
      <time
        class="ui-caption"
        :datetime="createdAt"
      >{{ formatDate(new Date(createdAt), locale) }}</time>
    </div>
    <UiLink
      class="dashboard-expense-title"
      :to="to"
    >
      {{ title }}
    </UiLink>
    <UiText
      as="span"
      variant="caption"
    >
      {{ t('Paid by') }} {{ payerName }}
    </UiText>
    <UiText
      v-if="split"
      as="span"
      variant="caption"
    >
      {{ formatPeople(split.participantCount, locale) }} ·
      {{ split.shareAmount === null ? t('Different share amounts') : formatMoney(split.shareAmount, currency, locale) + ' ' + t('each') }}
    </UiText>
    <UiText
      v-if="isDeleted"
      as="small"
      variant="caption"
      class="dashboard-expense-status"
    >
      {{ t('Removed') }}
    </UiText>
    <UiText
      v-if="payerInactive"
      as="span"
      variant="caption"
    >
      {{ t('Payer inactive') }}
    </UiText>
  </UiCard>
</template>
