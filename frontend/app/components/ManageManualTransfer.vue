<script setup lang="ts">
import type { DialogTarget, FocusTarget } from '~/types/ui'
import type { CreateManualTransferBody, TransferView } from '~/types/finance'
import { ApiClientError, toApiClientError, type ApiClientError as ApiError } from '~/utils/api-error'
import { moneyInput, currencyDigits, parseMinorUnits, settlementPreview, formatMoney } from '~/utils/money'
import { manualTransferUpdateCommand, validTransferEtag } from '~/utils/manual-transfer'

const props = defineProps<{ transfer: TransferView, etag: string }>()
const emit = defineEmits<{ reload: [], removed: [direction: 'incoming' | 'outgoing'] }>()
const { $auth } = useNuxtApp()
const { state } = useCurrentUser()
const mode = ref<'idle' | 'edit' | 'delete'>('idle')
const senderId = ref(props.transfer.sender.id)
const amount = ref(moneyInput(props.transfer.amount, props.transfer.currency))
const relatedShare = ref(props.transfer.relatedTransferId ?? '')
const relatedSelectorOpen = ref(false)
const pending = ref(false)
const blocked = ref(false)
const invalid = ref(false)
const error = ref<ApiError | null>(null)
const editTrigger = ref<FocusTarget | null>(null)
const deleteTrigger = ref<FocusTarget | null>(null)
const directionInput = ref<FocusTarget | null>(null)
const deleteConfirm = ref<FocusTarget | null>(null)
const dialog = useTemplateRef<DialogTarget>('dialog')
let generation = 0
const sides = computed(() => [props.transfer.sender, props.transfer.receiver])
const { locale } = useLocale()
const currency = computed(() => props.transfer.currency ?? 'EUR')
const manualRate = ref(props.transfer.manualRate ?? null)
const quote = ref<string | null>(null)
const selectedShare = ref<TransferView | null>(null)
const existingShare = computed(() => !!relatedShare.value && relatedShare.value === props.transfer.relatedTransferId)
const target = computed(() => selectedShare.value?.currency ?? (existingShare.value ? props.transfer.shareCurrency : null) ?? props.transfer.rateCurrency ?? state.value.user?.currency ?? 'EUR')
const available = computed(() => selectedShare.value?.remainingAmount ?? (existingShare.value && props.transfer.shareRemainingAmount != null ? props.transfer.availableBeforePayment ?? props.transfer.shareRemainingAmount + (props.transfer.creditedAmount ?? 0) : null))
const allocation = computed(() => {
  const paid = parseMinorUnits(amount.value, currency.value)
  return available.value != null && paid != null && quote.value ? settlementPreview(paid, available.value, currency.value, target.value, quote.value) : null
})
function selectShare(share: TransferView): void {
  selectedShare.value = share
  if ((share.currency ?? 'EUR') === currency.value) amount.value = moneyInput(share.remainingAmount ?? share.amount, currency.value)
}
watch(senderId, () => {
  relatedShare.value = ''
  selectedShare.value = null
})
watch(relatedShare, (id) => {
  if (!id) selectedShare.value = null
})
watch(target, () => {
  manualRate.value = null
})
const updateBody = computed(() => manualTransferUpdateCommand(props.transfer, state.value.user?.id ?? '', senderId.value, amount.value))
const message = computed(() => {
  if (error.value?.code === 'EXCHANGE_RATE_UNAVAILABLE') return 'The exchange rate is unavailable. Enter a rate or try again later.'
  if (error.value?.code === 'VERSION_CONFLICT') return 'Someone changed this payment. Reload it and review the current values before trying again.'
  if (error.value && (error.value.status === null || error.value.status >= 500)) return 'The result is not confirmed. Reload the payment before trying anything else.'
  if (['TRANSFER_FROZEN', 'TRANSFER_DELETED', 'TRANSFER_NOT_AVAILABLE', 'FORBIDDEN', 'PRECONDITION_REQUIRED', 'AUTOMATIC_TRANSFER_IMMUTABLE'].includes(error.value?.code ?? '')) return 'This payment can no longer be changed. Reload it to check its current state.'
  if (invalid.value || error.value?.code === 'VALIDATION_ERROR') return 'Choose a payment direction and enter a positive amount in the original currency.'
  return error.value ? 'The payment could not be changed. Check your session and try again.' : null
})

async function openEdit(): Promise<void> {
  if (pending.value || blocked.value) return
  mode.value = 'edit'
  error.value = null
  await nextTick()
  dialog.value?.showModal()
  directionInput.value?.focus()
}

async function openDelete(): Promise<void> {
  if (pending.value || blocked.value) return
  mode.value = 'delete'
  error.value = null
  await nextTick()
  dialog.value?.showModal()
  deleteConfirm.value?.focus()
}

async function cancel(target: 'edit' | 'delete'): Promise<void> {
  dialog.value?.close()
  mode.value = 'idle'
  error.value = null
  invalid.value = false
  senderId.value = props.transfer.sender.id
  amount.value = moneyInput(props.transfer.amount, props.transfer.currency)
  relatedShare.value = props.transfer.relatedTransferId ?? ''
  relatedSelectorOpen.value = false
  selectedShare.value = null
  manualRate.value = props.transfer.manualRate ?? null
  await nextTick()
  const targetButton = target === 'edit' ? editTrigger.value : deleteTrigger.value
  targetButton?.focus()
}

async function save(): Promise<void> {
  const viewer = state.value.user?.id
  if (!viewer || state.value.status !== 'authenticated' || pending.value || blocked.value || !validTransferEtag(props.etag)) return
  invalid.value = !updateBody.value
  if (!updateBody.value) return
  const relation = relatedShare.value === (props.transfer.relatedTransferId ?? '') ? {} : { relatedTransferId: relatedShare.value || null }
  const rate = manualRate.value !== (props.transfer.manualRate ?? null) ? { manualRate: manualRate.value } : {}
  await command('PUT', { ...updateBody.value, ...relation, ...rate }, 200, viewer)
}

async function remove(): Promise<void> {
  const viewer = state.value.user?.id
  if (!viewer || state.value.status !== 'authenticated' || pending.value || blocked.value || !validTransferEtag(props.etag)) return
  await command('DELETE', undefined, 204, viewer)
}

async function command(method: 'PUT' | 'DELETE', body: CreateManualTransferBody | undefined, expectedStatus: number, viewer: string): Promise<void> {
  const requestGeneration = generation
  const transferId = props.transfer.id
  pending.value = true
  error.value = null
  try {
    await $auth.protectedRequest<unknown>('/v1/transfers/' + encodeURIComponent(transferId), {
      method,
      ...(body ? { body } : {}),
      headers: { 'If-Match': props.etag },
      onResponse({ response }) {
        if (response.status >= 200 && response.status < 300 && response.status !== expectedStatus) {
          throw new ApiClientError({ kind: 'unknown', code: 'TRANSFER_CHANGE_UNCONFIRMED', message: 'Payment change was not confirmed.' })
        }
      }
    })
    if (requestGeneration !== generation || state.value.status !== 'authenticated' || state.value.user?.id !== viewer || props.transfer.id !== transferId) return
    blocked.value = true
    dialog.value?.close()
    mode.value = 'idle'
    if (method === 'DELETE') emit('removed', props.transfer.sender.id === viewer ? 'outgoing' : 'incoming')
    else emit('reload')
  }
  catch (failure) {
    if (requestGeneration !== generation) return
    error.value = toApiClientError(failure)
    blocked.value = (error.value.code !== 'EXCHANGE_RATE_UNAVAILABLE' && (error.value.status === null || error.value.status >= 500)) || ['VERSION_CONFLICT', 'PRECONDITION_REQUIRED', 'TRANSFER_FROZEN', 'TRANSFER_DELETED', 'TRANSFER_NOT_AVAILABLE', 'FORBIDDEN', 'AUTOMATIC_TRANSFER_IMMUTABLE', 'TRANSFER_CHANGE_UNCONFIRMED'].includes(error.value.code)
  }
  finally {
    if (requestGeneration === generation) pending.value = false
  }
}

function reset(): void {
  ++generation
  mode.value = 'idle'
  senderId.value = props.transfer.sender.id
  amount.value = moneyInput(props.transfer.amount, props.transfer.currency)
  relatedShare.value = props.transfer.relatedTransferId ?? ''
  relatedSelectorOpen.value = false
  selectedShare.value = null
  manualRate.value = props.transfer.manualRate ?? null
  pending.value = false
  blocked.value = false
  invalid.value = false
  error.value = null
}

watch(() => [props.transfer.id, props.transfer.version, props.etag, state.value.status, state.value.user?.id], reset)
onBeforeUnmount(() => ++generation)

const { t } = useTranslation()
</script>

<template>
  <section
    class="stack"
    aria-labelledby="manage-payment-title"
  >
    <UiText
      id="manage-payment-title"
      variant="heading2"
    >
      {{ t('Manage payment') }}
    </UiText>
    <div v-if="mode === 'idle' && !blocked">
      <UiButton
        ref="editTrigger"
        @click="openEdit"
      >
        {{ t('Edit payment') }}
      </UiButton>
      <UiButton
        ref="deleteTrigger"
        variant="danger"
        @click="openDelete"
      >
        {{ t('Remove payment') }}
      </UiButton>
    </div>
    <UiDialog
      v-if="mode === 'edit'"
      ref="dialog"
      aria-labelledby="edit-payment-title"
      @cancel.prevent
    >
      <UiCard
        as="form"
        class="stack dialog-confirm"
        @submit.prevent="save"
      >
        <UiText
          id="edit-payment-title"
          variant="heading3"
        >
          {{ t('Edit payment') }}
        </UiText>
        <FormErrorSummary
          :message="message"
          :request-id="error?.requestId"
        />
        <fieldset
          class="stack"
          :disabled="pending || blocked"
        >
          <legend class="visually-hidden">
            {{ t('Payment changes') }}
          </legend>
          <UiField>
            <label for="edit-payment-direction">{{ t('Who transferred money?') }}</label>
            <UiSelect
              id="edit-payment-direction"
              ref="directionInput"
              v-model="senderId"
              :label="t('Who transferred money?')"
              :options="sides.map(side => ({ value: side.id, label: `${side.name} → ${sides.find(other => other.id !== side.id)?.name}` }))"
              :disabled="pending || blocked"
            />
          </UiField>
          <UiButton
            v-if="!relatedSelectorOpen"
            variant="link"
            @click="relatedSelectorOpen = true"
          >
            {{ relatedShare ? t('Change related expense share') : t('Link an expense share (optional)') }}
          </UiButton>
          <RelatedShareSelector
            v-else
            v-model="relatedShare"
            :connect-id="transfer.connectId"
            :sender-id="senderId"
            @selected="selectShare"
          />
          <OperationRateField
            v-model="manualRate"
            :currency="currency"
            :target="target"
            :saved-rate="existingShare && !transfer.manualRate ? transfer.settlementRate : null"
            @rate="quote = $event"
          />
          <UiText v-if="allocation">
            {{ t('Remaining share') }} · {{ formatMoney(allocation.remaining, target, locale) }}.
            {{ t('Overpayment') }} · {{ formatMoney(allocation.excess, currency, locale) }}.
          </UiText>
          <UiField>
            <label for="edit-payment-amount">{{ currency === 'EUR' ? t('Payment amount in EUR') : t('Payment amount in') + ' ' + currency }}</label>
            <UiInput
              id="edit-payment-amount"
              v-model="amount"
              money
              :digits="currencyDigits(currency)"
              type="text"
              inputmode="decimal"
              required
              :aria-invalid="invalid"
            />
          </UiField>
        </fieldset>
        <UiButton
          type="submit"
          :disabled="pending || blocked"
          :pending="pending"
        >
          {{ pending ? t('Saving changes…') : t('Save payment changes') }}
        </UiButton>
        <UiButton
          v-if="!blocked"
          variant="secondary"
          :disabled="pending"
          @click="cancel('edit')"
        >
          {{ t('Cancel editing') }}
        </UiButton>
        <UiButton
          v-if="blocked"
          variant="secondary"
          :disabled="pending"
          @click="emit('reload')"
        >
          {{ t('Reload payment') }}
        </UiButton>
      </UiCard>
    </UiDialog>
    <UiDialog
      v-if="mode === 'delete'"
      ref="dialog"
      class="ui-dialog--confirm"
      role="alertdialog"
      aria-labelledby="remove-payment-title"
      aria-describedby="remove-payment-description"
      @cancel.prevent
    >
      <UiCard
        as="div"
        class="stack dialog-confirm"
      >
        <UiText
          id="remove-payment-title"
          variant="heading3"
        >
          {{ t('Remove this payment permanently?') }}
        </UiText>
        <UiText id="remove-payment-description">
          {{ t('This cannot be undone. The payment will remain in history but will stop affecting the balance.') }}
        </UiText>
        <FormErrorSummary
          :message="message"
          :request-id="error?.requestId"
        />
        <UiButton
          ref="deleteConfirm"
          variant="danger"
          :disabled="pending || blocked"
          :pending="pending"
          @click="remove"
        >
          {{ pending ? t('Removing payment…') : t('Yes, remove payment') }}
        </UiButton>
        <UiButton
          v-if="!blocked"
          variant="secondary"
          :disabled="pending"
          @click="cancel('delete')"
        >
          {{ t('Keep payment') }}
        </UiButton>
        <UiButton
          v-if="blocked"
          variant="secondary"
          :disabled="pending"
          @click="emit('reload')"
        >
          {{ t('Reload payment') }}
        </UiButton>
      </UiCard>
    </UiDialog>
    <UiButton
      v-if="blocked && mode === 'idle'"
      variant="secondary"
      :disabled="pending"
      @click="emit('reload')"
    >
      {{ t('Reload payment') }}
    </UiButton>
  </section>
</template>
