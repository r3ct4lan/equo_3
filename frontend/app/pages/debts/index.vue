<script setup lang="ts">
import type { DebtSummary } from '~/types/finance'
import { formatEuro as formatEuroBase } from '~/utils/money'
import { formatPeople } from '~/utils/locale'

const { locale } = useLocale()
const formatEuro = (cents: number) => formatEuroBase(cents, locale.value)

definePageMeta({ middleware: 'auth' })
useHead({ title: 'Expenses · Equo' })
const { state } = useCurrentUser()
const { items, pending, loaded, cursor, error, load } = useFinancialList<DebtSummary>(() => '/v1/debts')

const { t } = useTranslation()
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
      {{ t('Your expenses') }}
    </UiText>
    <template v-if="state.status === 'authenticated'">
      <UiButton
        icon="add"
        to="/debts/new"
      >
        {{ t('New expense') }}
      </UiButton>
      <AppLoadingState
        v-if="pending"
        :message="t('Loading expenses…')"
      />
      <AppEmptyState
        v-if="loaded && !items.length"
        :title="t('No expenses yet')"
        :message="t('Add an expense to share its cost with your connections.')"
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
          <UiText>{{ formatEuro(debt.totalAmount) }} · {{ t('paid by') }} {{ debt.payer.name }}</UiText>
          <UiText>{{ formatPeople(debt.participantCount, locale) }} · {{ formatEuro(debt.shareAmount) }} {{ t('each') }}</UiText>
          <UiNotice
            v-if="debt.isDeleted"
            tone="warning"
          >
            {{ t('Removed · does not affect the balance') }}
          </UiNotice>
          <UiText v-if="!debt.payer.isActive">
            {{ t('Payer inactive') }}
          </UiText>
        </UiCard>
      </ul>
      <UiButton
        v-if="cursor"
        variant="secondary"
        :disabled="pending"
        @click="load(true)"
      >
        {{ t('Load more expenses') }}
      </UiButton>
      <AppErrorState
        v-if="error"
        :title="t('Expenses could not be loaded')"
        :message="t('Try again to load your expenses.')"
        :request-id="error.requestId"
      />
      <UiButton
        v-if="error"
        variant="secondary"
        :disabled="pending"
        @click="load(items.length
          > 0)"
      >
        {{ t('Try again') }}
      </UiButton>
    </template>
    <AppLoadingState
      v-else
      :message="t('Checking your session…')"
    />
  </section>
</template>
