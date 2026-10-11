<script setup lang="ts">
import type { TransferView } from '~/types/finance'
import { formatEuro } from '~/utils/money'

definePageMeta({ middleware: 'auth' })
useHead({ title: 'Operation · Equo' })
const route = useRoute()
const { state } = useCurrentUser()
const { item: transfer, pending, error, load } = useFinancialDetail<TransferView>(() => '/v1/transfers/' + encodeURIComponent(String(route.params.id)))
</script>

<template>
  <section
    class="stack"
    aria-labelledby="transfer-title"
  >
    <h1 id="transfer-title">
      Operation
    </h1>
    <AppLoadingState
      v-if="pending || state.status === 'unknown'"
      message="Loading operation…"
    />
    <div
      v-if="transfer && state.status === 'authenticated'"
      class="panel stack"
    >
      <h2>{{ transfer.type === 'DEBT_SHARE' ? 'Expense share' : 'Payment' }} · {{ formatEuro(transfer.amount) }}</h2>
      <p>{{ transfer.sender.name }} → {{ transfer.receiver.name }}</p>
      <p v-if="!transfer.sender.isActive || !transfer.receiver.isActive">
        One of these accounts is inactive.
      </p>
      <p v-if="transfer.createdBy">
        Recorded by {{ transfer.createdBy.name }}
      </p>
      <p
        v-if="transfer.isDeleted"
        role="status"
      >
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
      <NuxtLink
        class="button button--secondary"
        :to="'/connects/' + transfer.connectId"
      >Connection and history</NuxtLink>
    </div>
    <AppErrorState
      v-if="error"
      :title="error.code === 'TRANSFER_NOT_AVAILABLE' ? 'Operation unavailable' : 'Operation could not be loaded'"
      message="Return to your connections or try again."
      :request-id="error.requestId"
    />
    <button
      v-if="error && (error.status === null || error.status >= 500)"
      class="button button--secondary"
      type="button"
      @click="load()"
    >
      Try again
    </button>
    <NuxtLink to="/connects">Your connections</NuxtLink>
  </section>
</template>
