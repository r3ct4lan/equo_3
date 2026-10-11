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
    <h2 id="history-title">
      Operations
    </h2>
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
      <li
        v-for="transfer in items"
        :key="transfer.id"
        class="panel stack"
      >
        <NuxtLink :to="'/transfers/' + transfer.id">{{ transfer.type === 'DEBT_SHARE' ? 'Expense share' : 'Payment' }} · {{ formatEuro(transfer.amount) }}</NuxtLink>
        <p>{{ transfer.sender.name }} → {{ transfer.receiver.name }}</p>
        <p v-if="transfer.isDeleted">
          Removed · does not affect the balance
        </p>
        <NuxtLink
          v-if="transfer.debtReference?.isAccessible"
          :to="'/debts/' + transfer.debtReference.id"
        >{{ transfer.debtReference.title }}{{ transfer.debtReference.isDeleted ? ' (removed)' : '' }}</NuxtLink>
        <p v-else-if="transfer.debtReference">
          Related expense unavailable
        </p>
        <time :datetime="transfer.createdAt">{{ new Date(transfer.createdAt).toLocaleString() }}</time>
      </li>
    </ul>
    <button
      v-if="cursor"
      class="button button--secondary"
      type="button"
      :disabled="pending"
      @click="load(true)"
    >
      Load more operations
    </button>
    <AppErrorState
      v-if="error"
      title="Operations could not be loaded"
      message="Try again to load the history."
      :request-id="error.requestId"
    />
    <button
      v-if="error"
      class="button button--secondary"
      type="button"
      :disabled="pending"
      @click="load(items.length > 0)"
    >
      Try again
    </button>
  </section>
</template>
