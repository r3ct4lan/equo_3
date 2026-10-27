<script setup lang="ts">
import type { BriefUser, DebtView } from '~/types/finance'
import { eligibleDebtUsers } from '~/utils/debts'
import { euroInput, updateDebtCommand, validDebtEtag } from '~/utils/debt-update'
import { formatEuro, parseEuroCents, splitShare } from '~/utils/money'
import { toApiClientError, type ApiClientError } from '~/utils/api-error'

const props = defineProps<{ debt: DebtView, etag: string }>()
const emit = defineEmits<{ saved: [debt: DebtView], reload: [], cancel: [] }>()
const { $auth } = useNuxtApp()
const { state } = useCurrentUser()
const values = reactive({
  title: props.debt.title, description: props.debt.description ?? '', amount: euroInput(props.debt.totalAmount),
  participantIds: props.debt.participants.filter(p => !p.isPayer).map(p => p.user.id), includePayerInSplit: props.debt.includePayerInSplit
})
const people = ref<BriefUser[]>([])
const loading = ref(false)
const optionsError = ref(false)
const pending = ref(false)
const blocked = ref(false)
const invalid = ref(false)
const error = ref<ApiClientError | null>(null)
let generation = 0
const currentIds = computed(() => new Set(props.debt.participants.filter(p => !p.isPayer).map(p => p.user.id)))
const share = computed(() => splitShare(parseEuroCents(values.amount) ?? 0, values.participantIds.length + (values.includePayerInSplit ? 1 : 0)))
const message = computed(() => {
  if (error.value?.code === 'VERSION_CONFLICT') return 'Someone changed this expense. Reload it and review the current values before saving again. Your draft has not been applied.'
  if (error.value && (error.value.status === null || error.value.status >= 500)) return 'The result is not confirmed. Reload the expense and review its current values before saving again.'
  if (['DEBT_FROZEN', 'DEBT_DELETED', 'DEBT_NOT_AVAILABLE', 'FORBIDDEN'].includes(error.value?.code ?? '')) return 'This expense can no longer be edited. Reload it to check its current state.'
  if (error.value?.code === 'INACTIVE_USER' || error.value?.code === 'INVALID_PARTICIPANT') return 'A selected person is unavailable. Reload the people and review your selections.'
  if (invalid.value || error.value?.code === 'VALIDATION_ERROR') return 'Enter a title, a positive EUR amount and at least one participant other than the payer. The author must remain in the split when someone else paid.'
  return error.value ? 'The changes could not be saved. Check your session and try again.' : null
})
async function loadPeople(): Promise<void> {
  const viewer = state.value.user
  if (!viewer || state.value.status !== 'authenticated') return
  const requestGeneration = ++generation
  loading.value = true
  optionsError.value = false
  try {
    const available = await eligibleDebtUsers($auth, viewer, () => requestGeneration === generation && state.value.status === 'authenticated' && state.value.user?.id === viewer.id)
    if (requestGeneration !== generation) return
    const users = new Map(available.map(user => [user.id, user]))
    for (const p of props.debt.participants) users.set(p.user.id, p.user)
    people.value = [...users.values()].filter(user => user.id !== props.debt.payer.id).sort((a, b) => a.name.localeCompare(b.name) || a.id.localeCompare(b.id))
    const permitted = new Set(people.value.map(user => user.id))
    values.participantIds = values.participantIds.filter(id => permitted.has(id))
  }
  catch {
    if (requestGeneration === generation) optionsError.value = true
  }
  finally {
    if (requestGeneration === generation) loading.value = false
  }
}
async function submit(): Promise<void> {
  const viewer = state.value.user?.id
  if (!viewer || state.value.status !== 'authenticated' || pending.value || blocked.value || loading.value || optionsError.value || !validDebtEtag(props.etag)) return
  const command = updateDebtCommand(props.debt, values, viewer)
  invalid.value = !command
  if (!command) return
  const requestGeneration = generation
  pending.value = true
  error.value = null
  try {
    const result = await $auth.protectedRequest<DebtView>('/v1/debts/' + encodeURIComponent(props.debt.id), { method: 'PUT', body: command, headers: { 'If-Match': props.etag } })
    if (requestGeneration !== generation || state.value.status !== 'authenticated' || state.value.user?.id !== viewer) return
    blocked.value = true
    emit('saved', result)
  }
  catch (failure) {
    if (requestGeneration !== generation) return
    error.value = toApiClientError(failure)
    blocked.value = error.value.status === null || error.value.status >= 500 || ['VERSION_CONFLICT', 'PRECONDITION_REQUIRED', 'DEBT_FROZEN', 'DEBT_DELETED', 'DEBT_NOT_AVAILABLE', 'FORBIDDEN'].includes(error.value.code)
  }
  finally {
    if (requestGeneration === generation) pending.value = false
  }
}
function clear(): void {
  ++generation
  people.value = []
  values.title = ''
  values.description = ''
  values.amount = ''
  values.participantIds = []
  values.includePayerInSplit = false
  pending.value = false
  loading.value = false
  error.value = null
  blocked.value = true
}
function reset(): void {
  clear()
  if (state.value.status !== 'authenticated' || state.value.user?.id !== props.debt.createdBy.id || !props.debt.canEdit || props.debt.isDeleted) return
  values.title = props.debt.title
  values.description = props.debt.description ?? ''
  values.amount = euroInput(props.debt.totalAmount)
  values.participantIds = props.debt.participants.filter(p => !p.isPayer).map(p => p.user.id)
  values.includePayerInSplit = props.debt.includePayerInSplit
  blocked.value = false
  invalid.value = false
  optionsError.value = false
  void loadPeople()
}
watch(() => [state.value.status, state.value.user?.id, props.debt.id, props.debt.version, props.etag], reset)
onMounted(reset)
onBeforeUnmount(clear)
</script>

<template>
  <UiCard
    as="form"
    class="stack"
    aria-labelledby="edit-fields-title"
    @submit.prevent="submit"
  >
    <UiText
      id="edit-fields-title"
      variant="heading2"
    >
      Change expense
    </UiText>
    <UiText>Paid by {{ debt.payer.name }}. The payer cannot be changed.</UiText>
    <FormErrorSummary :message="message" />
    <AppLoadingState
      v-if="loading"
      message="Loading available people…"
    />
    <AppErrorState
      v-if="optionsError"
      title="People could not be loaded"
      message="Reload the people before saving."
    />
    <fieldset
      class="stack edit-fields"
      :disabled="pending || blocked || loading || optionsError"
    >
      <legend class="visually-hidden">
        Expense details
      </legend>
      <UiField>
        <label for="edit-title">Title</label><UiInput
          id="edit-title"
          v-model="values.title"
          required
        />
      </UiField>
      <UiField>
        <label for="edit-description">Description (optional)</label><UiTextarea
          id="edit-description"
          v-model="values.description"
          rows="3"
        />
      </UiField>
      <UiField>
        <label for="edit-amount">Amount in EUR</label>
        <UiInput
          id="edit-amount"
          v-model="values.amount"
          money
          type="text"
          inputmode="decimal"
          required
          aria-describedby="edit-amount-help"
        />
        <UiText
          id="edit-amount-help"
          variant="help"
        >
          Use up to two decimal places, with a dot or comma. Maximum €21,474,836.47.
        </UiText>
      </UiField>
      <fieldset class="stack">
        <legend>Share with</legend>
        <UiText>Keep or remove current participants. Adding a previously removed person restores their original record.</UiText>
        <label
          v-for="user in people"
          :key="user.id"
          class="checkbox-label"
        >
          <UiCheckbox
            v-model="values.participantIds"
            :value="user.id"
            :disabled="user.id === debt.createdBy.id && user.id !== debt.payer.id"
          />
          {{ user.name }}{{ user.id === state.user?.id ? ' (you)' : '' }}{{ user.isActive ? '' : ' (inactive)' }}
          · {{ currentIds.has(user.id) ? values.participantIds.includes(user.id) ? 'Current participant' : 'Will be removed' : values.participantIds.includes(user.id) ? 'Will be added or restored' : 'Add or restore' }}
        </label>
      </fieldset>
      <label class="checkbox-label"><UiCheckbox
        v-model="values.includePayerInSplit"
      /> Include the payer in the split</label>
      <UiText v-if="share">
        Each share: {{ formatEuro(share) }}. Every current share will be recalculated.
      </UiText>
    </fieldset>
    <UiText
      v-if="error?.requestId"
      class="support-reference"
    >
      Support reference: {{ error.requestId }}
    </UiText>
    <UiButton
      type="submit"
      :disabled="pending || blocked || loading || optionsError || !validDebtEtag(etag)"
      :pending="pending"
    >
      {{ pending ? 'Saving changes…' : 'Save changes' }}
    </UiButton>
    <UiButton
      v-if="blocked"
      variant="secondary"
      :disabled="pending"
      @click="emit('reload')"
    >
      Reload expense and discard draft
    </UiButton>
    <UiButton
      v-if="optionsError || ['INACTIVE_USER', 'INVALID_PARTICIPANT'].includes(error?.code ?? '')"
      variant="secondary"
      :disabled="pending || blocked"
      @click="loadPeople()"
    >
      Reload people
    </UiButton>
    <UiButton
      variant="secondary"
      :disabled="pending"
      @click="emit('cancel')"
    >
      Cancel editing
    </UiButton>
  </UiCard>
</template>
