<script setup lang="ts">
import {formatDate} from '~/utils/locale'
import type {LinkTo} from '~/types/ui'
import {formatEuro as formatEuroBase} from '~/utils/money'
import {useLocale} from "~/composables/useLocale.ts";
import {useTranslation} from "~/composables/useTranslation.ts";
import UiCard from "~/components/UiCard.vue";

const {locale} = useLocale()
const {t} = useTranslation()
const formatEuro = (cents: number) => formatEuroBase(cents, locale.value)

defineProps<{
  to: LinkTo,
  title: string,
  amount: number,
  payerName: string,
  createdAt: string,
  isDeleted?: boolean,
  payerInactive?: boolean
}>()
</script>

<template>
  <UiCard
    as="a"
    class="dashboard-expense"
    :class="{'dashboard-expense--deleted': isDeleted}"
  >
    <div class="dashboard-expense-head">
      <div class="dashboard-expense-cost">
        <strong>{{ formatEuro(amount) }}</strong>
      </div>
      <time
        class="ui-caption"
        :datetime="createdAt"
      >{{ formatDate(new Date(createdAt), locale) }}
      </time>
    </div>
    <div
      class="dashboard-expense-title"
      :to="to"
    >
      {{ title }}
    </div>
    <UiText
      class="dashboard-expense-payer"
      as="span"
      variant="caption"
    >
      {{ t('Paid by') }} {{ payerName }}
    </UiText>
    <small
      class="dashboard-expense-status"
      v-if="isDeleted"
    >
      {{ t('Removed') }}
    </small>
  </UiCard>
</template>
