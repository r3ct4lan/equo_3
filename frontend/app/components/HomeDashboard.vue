<script setup lang="ts">
const { data, pending, error, load } = useDashboard()
</script>

<template>
  <section
    class="stack stack--large"
    aria-labelledby="dashboard-title"
  >
    <div class="hero">
      <UiText variant="eyebrow">
        Your shared expenses
      </UiText>
      <UiText
        id="dashboard-title"
        variant="heading1"
      >
        Your overview
      </UiText>
      <UiText variant="lede">
        What you owe, what you are owed, and your latest expenses.
      </UiText>
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
        <UiSummaryCard
          title-id="owed-title"
          title="You owe"
          :amount="data.balances.totalOwedAmount"
          to="/connects"
          link-label="Across all your connections"
        />
        <UiSummaryCard
          title-id="receivable-title"
          title="You are owed"
          :amount="data.balances.totalReceivableAmount"
          to="/connects"
          link-label="Across all your connections"
        />
      </div>

      <section
        class="stack"
        aria-labelledby="recent-expenses-title"
      >
        <div class="dashboard-section-heading">
          <UiText
            id="recent-expenses-title"
            variant="heading2"
          >
            Latest expenses
          </UiText>
          <UiButton
            to="/debts/new"
          >
            New expense
          </UiButton>
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
          <UiExpenseRow
            v-for="expense in data.expenses"
            :key="expense.id"
            :to="'/debts/' + expense.id"
            :title="expense.title"
            :amount="expense.totalAmount"
            :payer-name="expense.payer.name"
            :created-at="expense.createdAt"
          />
        </ol>
      </section>
    </template>
  </section>
</template>
