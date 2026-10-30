<script setup lang="ts">
import { formatDateTime } from '~/utils/locale'
import type { TransferView } from '~/types/finance'
import { formatEuro as formatEuroBase } from '~/utils/money'

const { locale } = useLocale()
const formatEuro = (cents: number) => formatEuroBase(cents, locale.value)

const props = defineProps<{ connectId: string }>()
const { items, pending, loaded, cursor, error, load } = useFinancialList<TransferView>(() => '/v1/connects/' + encodeURIComponent(props.connectId) + '/transfers')

const { t } = useTranslation()
</script>

<template>
  <section
    class="stack"
    aria-labelledby="history-title"
  >
    <UiText
      id="history-title"
      variant="heading2"
    >
      {{ t('Operations') }}
    </UiText>
    <AppLoadingState
      v-if="pending"
      :message="t('Loading operations…')"
    />
    <AppEmptyState
      v-if="loaded && !items.length"
      :title="t('No shared expenses yet')"
      :message="t('Your connection is ready.')"
    />
    <ul
      v-if="items.length"
      class="stack"
    >
      <UiCard
        v-for="transfer in items"
        :key="transfer.id"
        as="li"
        class="stack"
      >
        <UiLink :to="'/transfers/' + transfer.id">
          {{ transfer.type === 'DEBT_SHARE' ? t('Expense share') : t('Payment') }} · {{ formatEuro(transfer.amount) }}
        </UiLink>
        <UiText>{{ transfer.sender.name }} → {{ transfer.receiver.name }}</UiText>
        <UiNotice
          v-if="transfer.isDeleted"
          tone="warning"
        >
          {{ t('Removed · does not affect the balance') }}
        </UiNotice>
        <UiLink
          v-if="transfer.debtReference?.isAccessible"
          :to="'/debts/' + transfer.debtReference.id"
        >
          {{ transfer.debtReference.title }}{{ transfer.debtReference.isDeleted ? t(' (removed)') : '' }}
        </UiLink>
        <UiText v-else-if="transfer.debtReference">
          {{ t('Related expense unavailable') }}
        </UiText>
        <time :datetime="transfer.createdAt">{{ formatDateTime(new Date(transfer.createdAt), locale) }}</time>
      </UiCard>
    </ul>
    <UiButton
      v-if="cursor"
      variant="secondary"
      :disabled="pending"
      @click="load(true)"
    >
      {{ t('Load more operations') }}
    </UiButton>
    <AppErrorState
      v-if="error"
      :title="t('Operations could not be loaded')"
      :message="t('Try again to load the history.')"
      :request-id="error.requestId"
    />
    <UiButton
      v-if="error"
      variant="secondary"
      :disabled="pending"
      @click="load(items.length
        > 0)"
    >
      {{ t('Try again') }}
    </UiButton>
  </section>
</template>
