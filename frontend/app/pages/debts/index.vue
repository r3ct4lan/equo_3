<script setup lang="ts">
import type { DebtSummary } from '~/types/finance'
import { formatEuro } from '~/utils/money'

definePageMeta({ middleware: 'auth' })
useHead({ title: 'Expenses · Equo' })
const { state } = useCurrentUser()
const { items, pending, loaded, cursor, error, load } = useFinancialList<DebtSummary>(() => '/v1/debts')
</script>

<template>
  <section
    class="stack"
    aria-labelledby="debts-title"
  >
    <h1 id="debts-title">
      Your expenses
    </h1>
    <template v-if="state.status === 'authenticated'">
      <NuxtLink
        class="button"
        to="/debts/new"
      >New expense</NuxtLink>
      <AppLoadingState
        v-if="pending"
        message="Loading expenses…"
      />
      <AppEmptyState
        v-if="loaded && !items.length"
        title="No expenses yet"
        message="Add an expense to share its cost with your connections."
      />
      <ul
        v-if="items.length"
        class="stack"
      >
        <li
          v-for="debt in items"
          :key="debt.id"
          class="panel stack"
        >
          <NuxtLink :to="'/debts/' + debt.id">{{ debt.title }}</NuxtLink>
          <p>{{ formatEuro(debt.totalAmount) }} · paid by {{ debt.payer.name }}</p>
          <p>{{ debt.participantCount }} people · {{ formatEuro(debt.shareAmount) }} each</p>
          <p v-if="!debt.payer.isActive">
            Payer inactive
          </p>
        </li>
      </ul>
      <button
        v-if="cursor"
        class="button button--secondary"
        type="button"
        :disabled="pending"
        @click="load(true)"
      >
        Load more expenses
      </button>
      <AppErrorState
        v-if="error"
        title="Expenses could not be loaded"
        message="Try again to load your expenses."
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
    </template>
    <AppLoadingState
      v-else
      message="Checking your session…"
    />
  </section>
</template>
