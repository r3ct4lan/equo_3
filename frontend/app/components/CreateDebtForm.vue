<script setup lang="ts">
import type {
  BriefUser, CreateDebtBody, DebtView
} from '~/types/finance'
import {
  debtCommand, uncertainDebtResult
} from '~/utils/debts'
import {
  formatEuro as formatEuroBase, parseEuroCents, splitShare
} from '~/utils/money'
import {
  toApiClientError, type ApiClientError
} from '~/utils/api-error'

const { locale } = useLocale()
const formatEuro = (cents: number) => formatEuroBase(cents, locale.value)

const {
  $auth
} = useNuxtApp()
const {
  state
} = useCurrentUser()
const values = reactive({
  title: '', description: '', amount: '', payerId: '', participantIds: [] as string[], includePayerInSplit: true
})
const selectedPeople = ref<BriefUser[]>([])
const payer = ref<BriefUser[]>([])
const pending = ref(false)
const error = ref<ApiClientError | null>(null)
const invalid = ref(false)
const uncertain = ref(false)
let generation = 0
let attempt: {
  body: CreateDebtBody
  key: string
} | null = null
const payerOptions = computed(() => selectedPeople.value)
const share = computed(() => splitShare(parseEuroCents(values.amount) ?? 0, selectedPeople.value.length))
const errorMessage = computed(() => {
  if (uncertain.value) return 'The result is not confirmed. Keep this page open and retry the same expense to check it safely.'
  if (error.value?.code === 'INACTIVE_USER' || error.value?.code === 'INVALID_PARTICIPANT') return 'Someone selected is no longer available. Remove them and check the expense.'
  if (error.value?.code === 'VALIDATION_ERROR') return 'Check the title, amount and selected people.'
  if (error.value?.code === 'IDEMPOTENCY_KEY_REUSED') return 'This expense could not be confirmed. Open your expenses before starting another.'
  return error.value ? 'The expense could not be saved. Check your session and try again.' : null
})
async function submit(): Promise<void> {
  const viewer = state.value.user?.id
  if (!viewer || state.value.status !== 'authenticated' || pending.value) return
  if (!attempt) {
    const body = selectedPeople.value.some(person => person.id === values.payerId)
      ? debtCommand({ ...values, participantIds: selectedPeople.value.map(person => person.id).filter(id => id !== values.payerId), includePayerInSplit: true }, viewer)
      : null
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
watch(selectedPeople, (selected) => {
  if (!selected.some(person => person.id === values.payerId)) {
    values.payerId = ''
    payer.value = []
  }
})
watch(payer, (selected) => {
  values.payerId = selected[0]?.id ?? ''
})
onMounted(() => {
  const self = state.value.user
  values.payerId = self?.id ?? ''
  selectedPeople.value = self ? [{ id: self.id, name: self.name, isActive: self.isActive }] : []
  payer.value = [...selectedPeople.value]
})
watch(() => [state.value.status, state.value.user?.id], () => {
  ++generation
  attempt = null
  uncertain.value = false
  pending.value = false
  error.value = null
  values.title = ''
  values.description = ''
  values.amount = ''
  values.participantIds = []
  values.payerId = state.value.user?.id ?? ''
  const self = state.value.user
  selectedPeople.value = self ? [{ id: self.id, name: self.name, isActive: self.isActive }] : []
  payer.value = [...selectedPeople.value]
})
onBeforeUnmount(() => {
  ++generation
  attempt = null
})

const { t } = useTranslation()
</script>

<template>
  <div class="stack">
    <UiCard
      as="form"
      class="stack"
      @submit.prevent="submit"
    >
      <FormErrorSummary
        :message="invalid ? 'Enter a title, a positive amount and at least one participant other than the payer. You must be the payer or a participant.' : errorMessage"
        :request-id="error?.requestId"
      />
      <fieldset
        class="stack"
        :disabled="pending || uncertain"
      >
        <legend class="visually-hidden">
          {{ t('Expense details') }}
        </legend>
        <UiField>
          <label for="debt-title">{{ t('Title') }}</label>
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
          <label for="debt-description">{{ t('Description (optional)') }}</label>
          <UiTextarea
            id="debt-description"
            v-model="values.description"
            rows="3"
          />
        </UiField>
        <UiField>
          <label for="debt-amount">{{ t('Amount in EUR') }}</label>
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
            {{ t('Use up to two decimal places, with a dot or comma. Maximum €21,474,836.47.') }}
          </UiText>
          <FormFieldError
            id="amount-errors"
            :messages="error?.fieldErrors.totalAmount"
          />
        </UiField>
        <DebtPeoplePicker
          v-model="selectedPeople"
          :label="t('Share with')"
          :disabled="pending || uncertain"
        />
        <DebtPeoplePicker
          v-model="payer"
          :label="t('Who paid?')"
          :disabled="pending || uncertain"
          :choices="payerOptions"
          single
        />
        <UiText variant="help">
          {{ t('Choose the payer from the selected participants. You must be included.') }}
        </UiText>
        <UiText v-if="share">
          {{ t('Each share:') }} {{ formatEuro(share) }}. {{ t('Shares round up to the next cent.') }}
        </UiText>
      </fieldset>
      <UiButton
        type="submit"
        :disabled="pending"
        :pending="pending"
      >
        {{ pending ? t('Saving expense…') : uncertain ? t('Retry same expense') : t('Create expense') }}
      </UiButton>
    </UiCard>
  </div>
</template>
