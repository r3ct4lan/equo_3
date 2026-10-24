<script setup lang="ts">
import { formatEuro } from '~/utils/money'

const { data, pending, error, load } = useDashboard()
const dateFormatter = new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium' })
function expenseDate(value: string): string {
  return dateFormatter.format(new Date(value))
}
</script>

<template>
  <section
    class="stack stack--large"
    aria-labelledby="dashboard-title"
  >
    <div class="hero">
      <p class="eyebrow">
        Your shared expenses
      </p>
      <h1 id="dashboard-title">
        Your overview
      </h1>
      <p class="hero__lede">
        What you owe, what you are owed, and your latest expenses.
      </p>
    </div>

    <AppLoadingState
      v-if="pending || (!data && !error)"
      message="Loading your overview…"
    />
    <AppErrorState
      v-else-if="error"
      title="Your overview could not be loaded"
      message="Try again to see your balances and latest expenses."
      retry-label="Try again"
      @retry="load"
    />
    <template v-else-if="data">
      <div class="dashboard-balances">
        <section
          class="panel dashboard-balance"
          aria-labelledby="owed-title"
        >
          <h2 id="owed-title">
            You owe
          </h2>
          <p class="dashboard-amount">
            {{ formatEuro(data.balances.totalOwedAmount) }}
          </p>
          <p class="dashboard-caption">
            Across all your connections
          </p>
        </section>
        <section
          class="panel dashboard-balance"
          aria-labelledby="receivable-title"
        >
          <h2 id="receivable-title">
            You are owed
          </h2>
          <p class="dashboard-amount">
            {{ formatEuro(data.balances.totalReceivableAmount) }}
          </p>
          <p class="dashboard-caption">
            Across all your connections
          </p>
        </section>
      </div>

      <section
        class="stack"
        aria-labelledby="recent-expenses-title"
      >
        <div class="dashboard-section-heading">
          <h2 id="recent-expenses-title">
            Latest expenses
          </h2>
          <NuxtLink
            class="button"
            to="/debts/new"
          >New expense</NuxtLink>
        </div>
        <AppEmptyState
          v-if="!data.expenses.length"
          title="No expenses yet"
          message="Expenses you pay for or take part in will appear here."
        />
        <ol
          v-else
          class="dashboard-expenses stack"
          aria-label="Latest expenses"
        >
          <li
            v-for="expense in data.expenses"
            :key="expense.id"
            class="panel dashboard-expense"
          >
            <div class="dashboard-expense-details">
              <NuxtLink
                class="dashboard-expense-title"
                :to="'/debts/' + expense.id"
              >{{ expense.title }}</NuxtLink>
              <time
                class="dashboard-caption"
                :datetime="expense.createdAt"
              >{{ expenseDate(expense.createdAt) }}</time>
            </div>
            <div class="dashboard-expense-cost">
              <strong>{{ formatEuro(expense.totalAmount) }}</strong>
              <span class="dashboard-caption">Paid by {{ expense.payer.name }}</span>
            </div>
          </li>
        </ol>
      </section>
    </template>
  </section>
</template>

<style scoped>
.dashboard-balances {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 1rem;
}

.dashboard-balance {
  min-width: 0;
}

.dashboard-amount {
  margin-block: 1rem 0.5rem;
  overflow-wrap: anywhere;
  font-size: clamp(1.75rem, 5vw, 2.75rem);
  font-weight: 750;
  font-variant-numeric: tabular-nums;
}

.dashboard-caption {
  margin: 0;
  color: var(--color-muted);
  font-size: 0.95rem;
}

.dashboard-expenses {
  padding: 0;
  margin: 0;
  list-style: none;
}

.dashboard-section-heading {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
}

.dashboard-section-heading h2 {
  margin: 0;
}

.dashboard-expense {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
}

.dashboard-expense-details,
.dashboard-expense-cost {
  display: flex;
  min-width: 0;
  flex-direction: column;
  gap: 0.35rem;
  overflow-wrap: anywhere;
}

.dashboard-expense-title {
  font-weight: 650;
}

.dashboard-expense-cost {
  text-align: right;
  font-variant-numeric: tabular-nums;
}

@media (width <= 36rem) {
  .dashboard-balances {
    grid-template-columns: 1fr;
  }

  .dashboard-section-heading {
    align-items: stretch;
    flex-direction: column;
  }

  .dashboard-expense {
    flex-direction: column;
  }

  .dashboard-expense-cost {
    text-align: left;
  }
}
</style>
