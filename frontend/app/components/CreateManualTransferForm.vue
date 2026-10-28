<script setup lang="ts">
import type { ConnectView } from '~/types/connects'
import type { CreateManualTransferBody, TransferView } from '~/types/finance'
import { manualTransferCommand, manualTransferPreview } from '~/utils/manual-transfer'
import { formatEuro } from '~/utils/money'
import { euroInput } from '~/utils/debt-update'
import { toApiClientError, type ApiClientError } from '~/utils/api-error'

const props = defineProps<{ connect: ConnectView }>()
const emit = defineEmits<{ created: [transfer: TransferView], reload: [] }>()
const { $auth } = useNuxtApp()
const { state } = useCurrentUser()
const direction = ref('you')
const amount = ref('')
const relatedShare = ref('')
const relatedSelectorOpen = ref(false)
const pending = ref(false)
const uncertain = ref(false)
const invalid = ref(false)
const error = ref<ApiClientError | null>(null)
let generation = 0
let attempt: { body: CreateManualTransferBody, key: string } | null = null
const command = computed(() => manualTransferCommand(props.connect, state.value.user?.id ?? '', direction.value, amount.value))
const preview = computed(() => manualTransferPreview(props.connect.balanceAmount, command.value, state.value.user?.id ?? ''))
const message = computed(() => {
  if (uncertain.value) return 'The result is not confirmed. Keep this page open and retry the same payment to check it safely.'
  if (invalid.value || error.value?.code === 'VALIDATION_ERROR') return 'Choose who transferred money and enter a positive EUR amount with up to two decimal places.'
  if (error.value?.code === 'INACTIVE_USER') return 'This payment is unavailable because an account is inactive. Reload the connection to check it.'
  if (error.value?.code === 'RESOURCE_NOT_FOUND') return 'This connection is no longer available.'
  if (error.value?.code === 'IDEMPOTENCY_KEY_REUSED') return 'This payment could not be confirmed. Check your operations before starting another.'
  return error.value ? 'The payment could not be saved. Check your session and try again.' : null
})
async function submit(): Promise<void> {
  const viewer = state.value.user?.id
  if (!viewer || state.value.status !== 'authenticated' || pending.value || !props.connect.canCreateTransfer) return
  if (!attempt) {
    invalid.value = !command.value
    if (!command.value) return
    attempt = { body: { ...command.value, ...(relatedShare.value ? { relatedTransferId: relatedShare.value } : {}) }, key: crypto.randomUUID() }
  }
  const requestGeneration = generation
  const connectId = props.connect.id
  pending.value = true
  error.value = null
  try {
    const result = await $auth.protectedRequest<TransferView>('/v1/connects/' + encodeURIComponent(connectId) + '/transfers', {
      method: 'POST', body: attempt.body, headers: { 'Idempotency-Key': attempt.key }
    })
    if (requestGeneration !== generation || state.value.status !== 'authenticated' || state.value.user?.id !== viewer || props.connect.id !== connectId) return
    attempt = null
    uncertain.value = false
    amount.value = ''
    relatedShare.value = ''
    relatedSelectorOpen.value = false
    emit('created', result)
  }
  catch (failure) {
    if (requestGeneration !== generation) return
    error.value = toApiClientError(failure)
    uncertain.value = error.value.status === null || error.value.status >= 500
    if (!uncertain.value) attempt = null
  }
  finally {
    if (requestGeneration === generation) pending.value = false
  }
}
function clear(): void {
  ++generation
  attempt = null
  pending.value = false
  uncertain.value = false
  invalid.value = false
  error.value = null
  amount.value = ''
  direction.value = 'you'
  relatedShare.value = ''
  relatedSelectorOpen.value = false
}
watch(() => [state.value.status, state.value.user?.id, props.connect.id], clear)
onBeforeUnmount(clear)
</script>

<template>
  <UiCard
    as="form"
    class="stack"
    aria-labelledby="payment-title"
    @submit.prevent="submit"
  >
    <UiText
      id="payment-title"
      variant="heading2"
    >
      Record a payment
    </UiText>
    <UiText>Record money already transferred between you. Either person can record either direction, including an overpayment.</UiText>
    <FormErrorSummary
      :message="message"
      :request-id="error?.requestId"
    />
    <fieldset
      class="stack"
      :disabled="pending || uncertain || !connect.canCreateTransfer"
    >
      <legend class="visually-hidden">
        Payment details
      </legend>
      <UiField>
        <label for="payment-direction">Who transferred money?</label>
        <UiSelect
          id="payment-direction"
          v-model="direction"
          required
        >
          <option value="you">
            You → {{ connect.otherUser.name }}
          </option>
          <option value="other">
            {{ connect.otherUser.name }} → You
          </option>
        </UiSelect>
      </UiField>
      <UiButton
        v-if="!relatedSelectorOpen"
        variant="link"
        @click="relatedSelectorOpen = true"
      >
        Link an expense share (optional)
      </UiButton>
      <RelatedShareSelector
        v-else
        v-model="relatedShare"
        :connect-id="connect.id"
        @selected="amount = euroInput($event.amount)"
      />
      <UiField>
        <label for="payment-amount">Payment amount in EUR</label>
        <UiInput
          id="payment-amount"
          v-model="amount"
          money
          type="text"
          inputmode="decimal"
          required
          placeholder="5.01"
          :aria-invalid="invalid"
          aria-describedby="payment-amount-help"
        />
        <UiText
          id="payment-amount-help"
          variant="help"
        >
          Use up to two decimal places, with a dot or comma. Maximum €21,474,836.47.
        </UiText>
      </UiField>
      <UiText
        v-if="preview !== null"
        variant="help"
      >
        Expected balance: {{ formatEuro(preview) }}.
        {{ preview > 0 ? 'They would owe you.' : preview < 0 ? 'You would owe them.' : 'You would be settled up.' }}
        Your balance and operations will be refreshed after the payment is confirmed.
      </UiText>
    </fieldset>
    <UiButton
      type="submit"
      :disabled="pending || !connect.canCreateTransfer"
      :pending="pending"
    >
      {{ pending ? 'Saving payment…' : uncertain ? 'Retry same payment' : 'Record payment' }}
    </UiButton>
    <UiButton
      v-if="error?.code === 'INACTIVE_USER' && !uncertain"
      variant="secondary"
      :disabled="pending"
      @click="emit('reload')"
    >
      Reload connection
    </UiButton>
  </UiCard>
</template>
