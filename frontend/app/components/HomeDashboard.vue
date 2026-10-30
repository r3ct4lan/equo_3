<script setup lang="ts">
const { data, pending, error, load } = useDashboard()

const { t } = useTranslation()
</script>

<template>
  <section
    class="stack stack--large"
    aria-labelledby="dashboard-title"
  >
    <div class="hero">
      <UiText variant="eyebrow">
        {{ t('Your shared expenses') }}
      </UiText>
      <UiText
        id="dashboard-title"
        variant="heading1"
      >
        {{ t('Your overview') }}
      </UiText>
      <UiText variant="lede">
        {{ t('What you owe, what you are owed, and your latest expenses.') }}
      </UiText>
    </div>

    <AppLoadingState
      v-if="pending || (!data && !error)"
      :message="t('Loading your overview…')"
    />
    <AppErrorState
      v-else-if="error"
      :title="t('Your overview could not be loaded')"
      :message="t('Try again to see your balances and latest expenses.')"
      :retry-label="t('Try again')"
      @retry="load"
    />
    <template v-else-if="data">
      <div class="dashboard-balances">
        <UiSummaryCard
          title-id="owed-title"
          :title="t('You owe')"
          :amount="data.balances.totalOwedAmount"
          to="/connects"
          :link-label="t('Across all your connections')"
        />
        <UiSummaryCard
          title-id="receivable-title"
          :title="t('You are owed')"
          :amount="data.balances.totalReceivableAmount"
          to="/connects"
          :link-label="t('Across all your connections')"
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
            {{ t('Latest expenses') }}
          </UiText>
          <UiButton
            icon="add"
            to="/debts/new"
          >
            {{ t('New expense') }}
          </UiButton>
        </div>
        <AppEmptyState
          v-if="!data.expenses.length"
          :title="t('No expenses yet')"
          :message="t('Expenses you pay for or take part in will appear here.')"
        />
        <ol
          v-else
          class="dashboard-expenses stack"
          :aria-label="t('Latest expenses')"
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
        <UiLink
          v-if="data.expenses.length"
          to="/debts"
        >
          {{ t('View all') }}
        </UiLink>
      </section>
    </template>
  </section>
</template>
