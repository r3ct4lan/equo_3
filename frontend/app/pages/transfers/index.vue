<script setup lang="ts">
import { formatDateTime } from '~/utils/locale'
import type { TransferView } from '~/types/finance'
import { formatEuro as formatEuroBase } from '~/utils/money'

const { locale } = useLocale()
const formatEuro = (cents: number) => formatEuroBase(cents, locale.value)

definePageMeta({ middleware: 'auth' })
useHead({ title: 'Transfers · Equo' })

const route = useRoute()
const direction = computed(() => route.query.direction === 'outgoing' ? 'outgoing' : 'incoming')
const { items, pending, loaded, cursor, error, load } = useFinancialList<TransferView>(
  () => '/v1/transfers',
  () => ({ direction: direction.value })
)

const { t } = useTranslation()
</script>

<template>
  <section
    class="stack stack--large"
    aria-labelledby="transfers-title"
  >
    <div>
      <UiText
        id="transfers-title"
        variant="heading1"
      >
        {{ t('Transfers') }}
      </UiText>
      <UiText>{{ t('Payments and expense shares across your connections.') }}</UiText>
    </div>
    <nav
      class="form-actions"
      :aria-label="t('Transfer direction')"
    >
      <UiButton
        :variant="direction === 'incoming' ? 'primary' : 'secondary'"
        :to="{ path: '/transfers', query: { direction: 'incoming' } }"
        :aria-current="direction === 'incoming' ? 'page' : undefined"
      >
        {{ t('Incoming') }}
      </UiButton>
      <UiButton
        :variant="direction === 'outgoing' ? 'primary' : 'secondary'"
        :to="{ path: '/transfers', query: { direction: 'outgoing' } }"
        :aria-current="direction === 'outgoing' ? 'page' : undefined"
      >
        {{ t('Outgoing') }}
      </UiButton>
    </nav>
    <AppLoadingState
      v-if="pending && !loaded"
      :message="t('Loading transfers…')"
    />
    <AppEmptyState
      v-if="loaded && !items.length && !error"
      :title="t('No transfers yet')"
      :message="t('Transfers in this direction will appear here.')"
    />
    <ul
      v-if="items.length"
      class="stack"
      :aria-label="t('Transfers')"
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
        <time :datetime="transfer.createdAt">{{ formatDateTime(new Date(transfer.createdAt), locale) }}</time>
      </UiCard>
    </ul>
    <UiButton
      v-if="cursor"
      variant="secondary"
      :disabled="pending"
      @click="load(true)"
    >
      {{ t('Load more transfers') }}
    </UiButton>
    <AppErrorState
      v-if="error"
      :title="t('Transfers could not be loaded')"
      :message="t('Try again to load the transfers.')"
      :request-id="error.requestId"
      :retry-label="t('Try again')"
      @retry="load(items.length > 0)"
    />
  </section>
</template>
