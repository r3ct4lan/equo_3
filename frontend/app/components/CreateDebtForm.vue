<script setup lang="ts">
import type {
  BriefUser, CreateDebtBody, DebtView
} from '~/types/finance'
import {
  debtCommand, eligibleDebtUsers, uncertainDebtResult
} from '~/utils/debts'
import {
  formatEuro, parseEuroCents, splitShare
} from '~/utils/money'
import {
  toApiClientError, type ApiClientError
} from '~/utils/api-error'

const {
  $auth
} = useNuxtApp()
const {
  state
} = useCurrentUser()
const users = ref<BriefUser[]>([])
const loading = ref(false)
const optionsError = ref<ApiClientError | null>(null)
const values = reactive({
  title: '', description: '', amount: '', payerId: '', participantIds: [] as string[], includePayerInSplit: true
})
const pending = ref(false)
const error = ref<ApiClientError | null>(null)
const invalid = ref(false)
const uncertain = ref(false)
let generation = 0
let attempt: {
  body: CreateDebtBody
  key: string
} | null = null
const participantOptions = computed(() => users.value.filter(user => user.id !== values.payerId))
const share = computed(() => splitShare(parseEuroCents(values.amount) ?? 0, values.participantIds.length + (values.includePayerInSplit ? 1 : 0)))
const errorMessage = computed(() => {
  if (uncertain.value) return 'The result is not confirmed. Keep this page open and retry the same expense to check it safely.'
  if (error.value?.code === 'INACTIVE_USER' || error.value?.code === 'INVALID_PARTICIPANT') return 'Someone selected is no longer available. Reload the available people and check the expense.'
  if (error.value?.code === 'VALIDATION_ERROR') return 'Check the title, amount and selected people.'
  if (error.value?.code === 'IDEMPOTENCY_KEY_REUSED') return 'This expense could not be confirmed. Open your expenses before starting another.'
  return error.value ? 'The expense could not be saved. Check your session and try again.' : null
})
async function loadPeople(): Promise<void> {
  const self = state.value.user
  if (state.value.status !== 'authenticated' || !self) return
  const requestGeneration = ++generation
  loading.value = true
  optionsError.value = null
  users.value = []
  try {
    const result = await eligibleDebtUsers($auth, self, () => requestGeneration === generation && state.value.status === 'authenticated' && state.value.user?.id === self.id)
    if (requestGeneration === generation && state.value.user?.id === self.id) {
      users.value = result
      const available = new Set(result.map(user => user.id))
      if (!available.has(values.payerId)) values.payerId = self.id
      values.participantIds = values.participantIds.filter(id => available.has(id) && id !== values.payerId)
    }
  }
  catch (failure) {
    if (requestGeneration === generation) optionsError.value = toApiClientError(failure)
  }
  finally {
    if (requestGeneration === generation) loading.value = false
  }
}
async function submit(): Promise<void> {
  const viewer = state.value.user?.id
  if (!viewer || state.value.status !== 'authenticated' || pending.value || loading.value || optionsError.value) return
  if (!attempt) {
    const body = debtCommand(values, viewer)
    invalid.value = body === null
    if (!body) return
    attempt = {
      body, key: crypto.randomUUID()
    }
  }
  const requestGeneration = generation
  pending.value = true
  error.value = null
  try {
    const debt = await $auth.protectedRequest<DebtView>('/v1/debts', {
      method: 'POST', body: attempt.body, headers: {
        'Idempotency-Key': attempt.key
      }
    })
    if (requestGeneration !== generation || state.value.user?.id !== viewer || state.value.status !== 'authenticated') return
    attempt = null
    uncertain.value = false
    await navigateTo('/debts/' + debt.id)
  }
  catch (failure) {
    if (requestGeneration !== generation) return
    error.value = toApiClientError(failure)
    uncertain.value = uncertainDebtResult(error.value)
    if (!uncertain.value) attempt = null
  }
  finally {
    if (requestGeneration === generation) pending.value = false
  }
}
watch(() => values.payerId, () => {
  values.participantIds = values.participantIds.filter(id => id !== values.payerId)
})
onMounted(() => {
  values.payerId = state.value.user?.id ?? ''
  void loadPeople()
})
watch(() => [state.value.status, state.value.user?.id], () => {
  ++generation
  attempt = null
  uncertain.value = false
  pending.value = false
  loading.value = false
  error.value = null
  users.value = []
  values.title = ''
  values.description = ''
  values.amount = ''
  values.participantIds = []
  values.payerId = state.value.user?.id ?? ''
  void loadPeople()
})
onBeforeUnmount(() => {
  ++generation
  attempt = null
})
</script>

<template>
  <div class="stack">
    <AppLoadingState
      v-if="loading"
      message="Loading available people…"
    />
    <AppErrorState
      v-if="optionsError"
      title="People could not be loaded"
      message="Try again before creating an expense."
      :request-id="optionsError.requestId"
    />
    <UiButton
      v-if="optionsError"
      variant="secondary"
      @click="loadPeople()"
    >
      Reload people
    </UiButton>
    <UiCard
      as="form"
      class="stack"
      @submit.prevent="submit"
    >
      <FormErrorSummary :message="invalid ? 'Enter a title, a positive amount and at least one participant other than the payer. You must be the payer or a participant.' : errorMessage" />
      <fieldset
        class="stack"
        :disabled="pending || uncertain || loading || !!optionsError"
      >
        <legend class="visually-hidden">
          Expense details
        </legend>
        <UiField>
          <label for="debt-title">Title</label>
          <UiInput
            id="debt-title"
            v-model="values.title"
            required
            :aria-invalid="invalid && !values.title.trim()"
            aria-describedby="title-errors"
          />
          <FormFieldError
            id="title-errors"
            :messages="error?.fieldErrors.title"
          />
        </UiField>
        <UiField>
          <label for="debt-description">Description (optional)</label>
          <UiTextarea
            id="debt-description"
            v-model="values.description"
            rows="3"
          />
        </UiField>
        <UiField>
          <label for="debt-amount">Amount in EUR</label>
          <UiInput
            id="debt-amount"
            v-model="values.amount"
            money
            type="text"
            inputmode="decimal"
            placeholder="10.01"
            required
            :aria-invalid="invalid && parseEuroCents(values.amount) === null"
            aria-describedby="amount-help amount-errors"
          />
          <UiText
            id="amount-help"
            variant="help"
          >
            Use up to two decimal places, with a dot or comma. Maximum €21,474,836.47.
          </UiText>
          <FormFieldError
            id="amount-errors"
            :messages="error?.fieldErrors.totalAmount"
          />
        </UiField>
        <UiField>
          <label for="debt-payer">Who paid?</label>
          <UiSelect
            id="debt-payer"
            v-model="values.payerId"
            required
          >
            <option
              v-for="user in users"
              :key="user.id"
              :value="user.id"
            >
              {{ user.name }}{{ user.id === state.user?.id ? ' (you)' : '' }}
            </option>
          </UiSelect>
        </UiField>
        <fieldset class="stack">
          <legend>Share with</legend>
          <UiText v-if="!participantOptions.length">
            Connect with someone before sharing an expense.
          </UiText>
          <label
            v-for="user in participantOptions"
            :key="user.id"
            class="checkbox-label"
          >
            <UiCheckbox
              v-model="values.participantIds"
              :value="user.id"
            />
            {{ user.name }}{{ user.id === state.user?.id ? ' (you)' : '' }}
          </label>
        </fieldset>
        <label class="checkbox-label"><UiCheckbox
          v-model="values.includePayerInSplit"
        /> Include the payer in the split</label>
        <UiText variant="help">
          If someone else paid, include yourself as a participant.
        </UiText>
        <UiText v-if="share">
          Each share: {{ formatEuro(share) }}. Shares round up to the next cent.
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
        :disabled="pending || loading || !!optionsError"
        :pending="pending"
      >
        {{ pending ? 'Saving expense…' : uncertain ? 'Retry same expense' : 'Create expense' }}
      </UiButton>
      <UiButton
        v-if="error && !uncertain && ['INACTIVE_USER', 'INVALID_PARTICIPANT'].includes(error.code)"
        variant="secondary"
        @click="loadPeople()"
      >
        Reload people
      </UiButton>
    </UiCard>
  </div>
</template>
