<script setup lang="ts">
import type { DebtView } from '~/types/finance'
import { validDebtEtag } from '~/utils/debt-update'

definePageMeta({ middleware: 'auth' })
useHead({ title: 'Edit expense · Equo' })
const route = useRoute()
const { state } = useCurrentUser()
const { item: debt, etag, pending, error, load } = useFinancialDetail<DebtView>(() => '/v1/debts/' + encodeURIComponent(String(route.params.id)))
const canEdit = computed(() => debt.value?.canEdit && !debt.value.isDeleted && debt.value.createdBy.id === state.value.user?.id)
async function saved(): Promise<void> {
  await navigateTo('/debts/' + encodeURIComponent(String(route.params.id)))
}
</script>

<template>
  <section
    class="stack"
    aria-labelledby="edit-expense-title"
  >
    <h1 id="edit-expense-title">
      Edit expense
    </h1>
    <AppLoadingState
      v-if="pending || state.status === 'unknown'"
      message="Loading expense…"
    />
    <EditDebtForm
      v-if="debt && canEdit && etag && validDebtEtag(etag)"
      :key="debt.id + ':' + etag"
      :debt="debt"
      :etag="etag"
      @saved="saved"
      @reload="load"
      @cancel="saved"
    />
    <AppErrorState
      v-if="error"
      title="Expense unavailable"
      message="Return to your expenses or reload the expense."
      :request-id="error.requestId"
    />
    <AppErrorState
      v-if="debt && !canEdit"
      title="Editing unavailable"
      message="Only the author can edit an active expense when the author and payer are active."
    />
    <AppErrorState
      v-if="debt && canEdit && !validDebtEtag(etag)"
      title="Editing unavailable"
      message="Reload the expense before saving changes."
    />
    <button
      v-if="error || (debt && canEdit && !validDebtEtag(etag))"
      class="button button--secondary"
      type="button"
      :disabled="pending"
      @click="load()"
    >
      Reload expense
    </button>
    <NuxtLink
      class="button button--secondary"
      :to="'/debts/' + encodeURIComponent(String(route.params.id))"
    >Back to expense</NuxtLink>
  </section>
</template>
