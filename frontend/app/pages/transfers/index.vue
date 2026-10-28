<script setup lang="ts">
import type { TransferView } from '~/types/finance'
import { formatEuro } from '~/utils/money'

definePageMeta({ middleware: 'auth' })
useHead({ title: 'Transfers · Equo' })

const route = useRoute()
const direction = computed(() => route.query.direction === 'outgoing' ? 'outgoing' : 'incoming')
const { items, pending, loaded, cursor, error, load } = useFinancialList<TransferView>(
  () => '/v1/transfers',
  () => ({ direction: direction.value })
)
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
        Transfers
      </UiText>
      <UiText>Payments and expense shares across your connections.</UiText>
    </div>
    <nav
      class="form-actions"
      aria-label="Transfer direction"
    >
      <UiButton
        :variant="direction === 'incoming' ? 'primary' : 'secondary'"
        :to="{ path: '/transfers', query: { direction: 'incoming' } }"
        :aria-current="direction === 'incoming' ? 'page' : undefined"
      >
        Incoming
      </UiButton>
      <UiButton
        :variant="direction === 'outgoing' ? 'primary' : 'secondary'"
        :to="{ path: '/transfers', query: { direction: 'outgoing' } }"
        :aria-current="direction === 'outgoing' ? 'page' : undefined"
      >
        Outgoing
      </UiButton>
    </nav>
    <AppLoadingState
      v-if="pending && !loaded"
      message="Loading transfers…"
    />
    <AppEmptyState
      v-if="loaded && !items.length && !error"
      title="No transfers yet"
      message="Transfers in this direction will appear here."
    />
    <ul
      v-if="items.length"
      class="stack"
      aria-label="Transfers"
    >
      <UiCard
        v-for="transfer in items"
        :key="transfer.id"
        as="li"
        class="stack"
      >
        <UiLink :to="'/transfers/' + transfer.id">
          {{ transfer.type === 'DEBT_SHARE' ? 'Expense share' : 'Payment' }} · {{ formatEuro(transfer.amount) }}
        </UiLink>
        <UiText>{{ transfer.sender.name }} → {{ transfer.receiver.name }}</UiText>
        <UiNotice
          v-if="transfer.isDeleted"
          tone="neutral"
        >
          Removed · does not affect the balance
        </UiNotice>
        <time :datetime="transfer.createdAt">{{ new Date(transfer.createdAt).toLocaleString() }}</time>
      </UiCard>
    </ul>
    <UiButton
      v-if="cursor"
      variant="secondary"
      :disabled="pending"
      @click="load(true)"
    >
      Load more transfers
    </UiButton>
    <AppErrorState
      v-if="error"
      title="Transfers could not be loaded"
      message="Try again to load the transfers."
      :request-id="error.requestId"
      retry-label="Try again"
      @retry="load(items.length > 0)"
    />
  </section>
</template>
