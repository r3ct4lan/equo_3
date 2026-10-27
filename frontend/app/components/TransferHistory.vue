<script setup lang="ts">
import type { TransferView } from '~/types/finance'
import { formatEuro } from '~/utils/money'

const props = defineProps<{ connectId: string }>()
const { items, pending, loaded, cursor, error, load } = useFinancialList<TransferView>(() => '/v1/connects/' + encodeURIComponent(props.connectId) + '/transfers')
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
      Operations
    </UiText>
    <AppLoadingState
      v-if="pending"
      message="Loading operations…"
    />
    <AppEmptyState
      v-if="loaded && !items.length"
      title="No shared expenses yet"
      message="Your connection is ready."
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
          {{ transfer.type === 'DEBT_SHARE' ? 'Expense share' : 'Payment' }} · {{ formatEuro(transfer.amount) }}
        </UiLink>
        <UiText>{{ transfer.sender.name }} → {{ transfer.receiver.name }}</UiText>
        <UiText v-if="transfer.isDeleted">
          Removed · does not affect the balance
        </UiText>
        <UiLink
          v-if="transfer.debtReference?.isAccessible"
          :to="'/debts/' + transfer.debtReference.id"
        >
          {{ transfer.debtReference.title }}{{ transfer.debtReference.isDeleted ? ' (removed)' : '' }}
        </UiLink>
        <UiText v-else-if="transfer.debtReference">
          Related expense unavailable
        </UiText>
        <time :datetime="transfer.createdAt">{{ new Date(transfer.createdAt).toLocaleString() }}</time>
      </UiCard>
    </ul>
    <UiButton
      v-if="cursor"
      variant="secondary"
      :disabled="pending"
      @click="load(true)"
    >
      Load more operations
    </UiButton>
    <AppErrorState
      v-if="error"
      title="Operations could not be loaded"
      message="Try again to load the history."
      :request-id="error.requestId"
    />
    <UiButton
      v-if="error"
      variant="secondary"
      :disabled="pending"
      @click="load(items.length
        > 0)"
    >
      Try again
    </UiButton>
  </section>
</template>
