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
    <UiText
      id="debts-title"
      variant="heading1"
    >
      Your expenses
    </UiText>
    <template v-if="state.status === 'authenticated'">
      <UiButton
        to="/debts/new"
      >
        New expense
      </UiButton>
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
        <UiCard
          v-for="debt in items"
          :key="debt.id"
          as="li"
          class="stack"
        >
          <UiLink :to="'/debts/' + debt.id">
            {{ debt.title }}
          </UiLink>
          <UiText>{{ formatEuro(debt.totalAmount) }} · paid by {{ debt.payer.name }}</UiText>
          <UiText>{{ debt.participantCount }} people · {{ formatEuro(debt.shareAmount) }} each</UiText>
          <UiText v-if="!debt.payer.isActive">
            Payer inactive
          </UiText>
        </UiCard>
      </ul>
      <UiButton
        v-if="cursor"
        variant="secondary"
        :disabled="pending"
        @click="load(true)"
      >
        Load more expenses
      </UiButton>
      <AppErrorState
        v-if="error"
        title="Expenses could not be loaded"
        message="Try again to load your expenses."
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
    </template>
    <AppLoadingState
      v-else
      message="Checking your session…"
    />
  </section>
</template>
