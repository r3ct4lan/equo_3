<script setup lang="ts">
import { formatEuro as formatEuroBase } from '~/utils/money'

const { locale } = useLocale()
const formatEuro = (cents: number) => formatEuroBase(cents, locale.value)

defineProps<{ amount: number }>()

const { t } = useTranslation()
</script>

<template>
  <div class="connect-balance">
    <UiText class="connect-balance__amount">
      {{ t('Balance:') }} <strong
        class="connect-balance__value"
        :class="{ 'connect-balance__value--positive': amount > 0, 'connect-balance__value--negative': amount < 0 }"
      >{{ formatEuro(amount) }}</strong>
    </UiText>
    <UiText v-if="amount > 0">
      {{ t('They owe you.') }}
    </UiText>
    <UiText v-else-if="amount < 0">
      {{ t('You owe them.') }}
    </UiText>
    <UiText v-else>
      {{ t('You are settled up.') }}
    </UiText>
  </div>
</template>
