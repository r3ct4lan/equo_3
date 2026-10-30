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

const { t } = useTranslation()
</script>

<template>
  <section
    class="stack"
    aria-labelledby="edit-expense-title"
  >
    <UiText
      id="edit-expense-title"
      variant="heading1"
    >
      {{ t('Edit expense') }}
    </UiText>
    <AppLoadingState
      v-if="pending || state.status === 'unknown'"
      :message="t('Loading expense…')"
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
      :title="t('Expense unavailable')"
      :message="t('Return to your expenses or reload the expense.')"
      :request-id="error.requestId"
    />
    <AppErrorState
      v-if="debt && !canEdit"
      :title="t('Editing unavailable')"
      :message="t('Only the author can edit an active expense when the author and payer are active.')"
    />
    <AppErrorState
      v-if="debt && canEdit && !validDebtEtag(etag)"
      :title="t('Editing unavailable')"
      :message="t('Reload the expense before saving changes.')"
    />
    <UiButton
      v-if="error || (debt && canEdit && !validDebtEtag(etag))"
      variant="secondary"
      :disabled="pending"
      @click="load()"
    >
      {{ t('Reload expense') }}
    </UiButton>
    <UiButton
      variant="secondary"
      :to="'/debts/' + encodeURIComponent(String(route.params.id))"
    >
      {{ t('Back to expense') }}
    </UiButton>
  </section>
</template>
