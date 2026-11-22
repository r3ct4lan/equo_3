<script setup lang="ts">
import { formatMoney } from '~/utils/money'

const { locale } = useLocale()

defineProps<{ amount: number, currency?: string }>()

const { t } = useTranslation()
</script>

<template>
  <div class="connect-balance">
    <UiText class="connect-balance__amount">
      <strong
        class="connect-balance__value"
        :class="{ 'connect-balance__value--positive': amount > 0, 'connect-balance__value--negative': amount < 0 }"
      >{{ formatMoney(amount, currency, locale) }}</strong>
    </UiText>
    <UiText v-if="amount > 0">
      {{ t('They owe you') }}
    </UiText>
    <UiText v-else-if="amount < 0">
      {{ t('You owe them') }}
    </UiText>
    <UiText v-else>
      {{ t('You are settled up') }}
    </UiText>
  </div>
</template>
