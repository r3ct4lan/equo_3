<script setup lang="ts">
import type { CreateManualTransferBody, TransferView } from '~/types/finance'
import { ApiClientError, toApiClientError, type ApiClientError as ApiError } from '~/utils/api-error'
import { euroInput } from '~/utils/debt-update'
import { manualTransferUpdateCommand, validTransferEtag } from '~/utils/manual-transfer'

const props = defineProps<{ transfer: TransferView, etag: string }>()
const emit = defineEmits<{ reload: [] }>()
const { $auth } = useNuxtApp()
const { state } = useCurrentUser()
const mode = ref<'idle' | 'edit' | 'delete'>('idle')
const senderId = ref(props.transfer.sender.id)
const amount = ref(euroInput(props.transfer.amount))
const pending = ref(false)
const blocked = ref(false)
const invalid = ref(false)
const error = ref<ApiError | null>(null)
const editTrigger = ref<HTMLButtonElement | null>(null)
const deleteTrigger = ref<HTMLButtonElement | null>(null)
const directionInput = ref<HTMLSelectElement | null>(null)
const deleteConfirm = ref<HTMLButtonElement | null>(null)
let generation = 0
const sides = computed(() => [props.transfer.sender, props.transfer.receiver])
const updateBody = computed(() => manualTransferUpdateCommand(props.transfer, state.value.user?.id ?? '', senderId.value, amount.value))
const message = computed(() => {
  if (error.value?.code === 'VERSION_CONFLICT') return 'Someone changed this payment. Reload it and review the current values before trying again.'
  if (error.value && (error.value.status === null || error.value.status >= 500)) return 'The result is not confirmed. Reload the payment before trying anything else.'
  if (['TRANSFER_FROZEN', 'TRANSFER_DELETED', 'TRANSFER_NOT_AVAILABLE', 'FORBIDDEN', 'PRECONDITION_REQUIRED', 'AUTOMATIC_TRANSFER_IMMUTABLE'].includes(error.value?.code ?? '')) return 'This payment can no longer be changed. Reload it to check its current state.'
  if (invalid.value || error.value?.code === 'VALIDATION_ERROR') return 'Choose one of the original directions and enter a positive EUR amount with up to two decimal places.'
  return error.value ? 'The payment could not be changed. Check your session and try again.' : null
})

async function openEdit(): Promise<void> {
  if (pending.value || blocked.value) return
  mode.value = 'edit'
  error.value = null
  await nextTick()
  directionInput.value?.focus()
}

async function openDelete(): Promise<void> {
  if (pending.value || blocked.value) return
  mode.value = 'delete'
  error.value = null
  await nextTick()
  deleteConfirm.value?.focus()
}

async function cancel(target: 'edit' | 'delete'): Promise<void> {
  mode.value = 'idle'
  error.value = null
  invalid.value = false
  senderId.value = props.transfer.sender.id
  amount.value = euroInput(props.transfer.amount)
  await nextTick()
  const targetButton = target === 'edit' ? editTrigger.value : deleteTrigger.value
  targetButton?.focus()
}

async function save(): Promise<void> {
  const viewer = state.value.user?.id
  if (!viewer || state.value.status !== 'authenticated' || pending.value || blocked.value || !validTransferEtag(props.etag)) return
  invalid.value = !updateBody.value
  if (!updateBody.value) return
  await command('PUT', updateBody.value, 200, viewer)
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
    mode.value = 'idle'
    emit('reload')
  }
  catch (failure) {
    if (requestGeneration !== generation) return
    error.value = toApiClientError(failure)
    blocked.value = error.value.status === null || error.value.status >= 500 || ['VERSION_CONFLICT', 'PRECONDITION_REQUIRED', 'TRANSFER_FROZEN', 'TRANSFER_DELETED', 'TRANSFER_NOT_AVAILABLE', 'FORBIDDEN', 'AUTOMATIC_TRANSFER_IMMUTABLE', 'TRANSFER_CHANGE_UNCONFIRMED'].includes(error.value.code)
  }
  finally {
    if (requestGeneration === generation) pending.value = false
  }
}

function reset(): void {
  ++generation
  mode.value = 'idle'
  senderId.value = props.transfer.sender.id
  amount.value = euroInput(props.transfer.amount)
  pending.value = false
  blocked.value = false
  invalid.value = false
  error.value = null
}

watch(() => [props.transfer.id, props.transfer.version, props.etag, state.value.status, state.value.user?.id], reset)
onBeforeUnmount(() => ++generation)
</script>

<template>
  <section
    class="stack"
    aria-labelledby="manage-payment-title"
  >
    <h2 id="manage-payment-title">
      Manage payment
    </h2>
    <div v-if="mode === 'idle' && !blocked">
      <button
        ref="editTrigger"
        class="button"
        type="button"
        @click="openEdit"
      >
        Edit payment
      </button>
      <button
        ref="deleteTrigger"
        class="button button--danger"
        type="button"
        @click="openDelete"
      >
        Remove payment
      </button>
    </div>
    <form
      v-if="mode === 'edit'"
      class="panel stack"
      aria-labelledby="edit-payment-title"
      @submit.prevent="save"
    >
      <h3 id="edit-payment-title">
        Edit payment
      </h3>
      <FormErrorSummary :message="message" />
      <fieldset
        class="stack"
        :disabled="pending || blocked"
      >
        <legend class="visually-hidden">
          Payment changes
        </legend>
        <div class="form-field">
          <label for="edit-payment-direction">Who transferred money?</label>
          <select
            id="edit-payment-direction"
            ref="directionInput"
            v-model="senderId"
          >
            <option
              v-for="side in sides"
              :key="side.id"
              :value="side.id"
            >
              {{ side.name }} → {{ sides.find(other => other.id !== side.id)?.name }}
            </option>
          </select>
        </div>
        <div class="form-field">
          <label for="edit-payment-amount">Payment amount in EUR</label>
          <input
            id="edit-payment-amount"
            v-model="amount"
            type="text"
            inputmode="decimal"
            required
            :aria-invalid="invalid"
          >
        </div>
      </fieldset>
      <button
        class="button"
        type="submit"
        :disabled="pending || blocked"
        :aria-busy="pending"
      >
        {{ pending ? 'Saving changes…' : 'Save payment changes' }}
      </button>
      <button
        v-if="!blocked"
        class="button button--secondary"
        type="button"
        :disabled="pending"
        @click="cancel('edit')"
      >
        Cancel editing
      </button>
    </form>
    <div
      v-if="mode === 'delete'"
      class="panel stack"
      role="alertdialog"
      aria-labelledby="remove-payment-title"
      aria-describedby="remove-payment-description"
    >
      <h3 id="remove-payment-title">
        Remove this payment permanently?
      </h3>
      <p id="remove-payment-description">
        This cannot be undone. The payment will remain in history but will stop affecting the balance.
      </p>
      <FormErrorSummary :message="message" />
      <button
        ref="deleteConfirm"
        class="button button--danger"
        type="button"
        :disabled="pending || blocked"
        :aria-busy="pending"
        @click="remove"
      >
        {{ pending ? 'Removing payment…' : 'Yes, remove payment' }}
      </button>
      <button
        v-if="!blocked"
        class="button button--secondary"
        type="button"
        :disabled="pending"
        @click="cancel('delete')"
      >
        Keep payment
      </button>
    </div>
    <p
      v-if="error?.requestId"
      class="support-reference"
    >
      Support reference: {{ error.requestId }}
    </p>
    <button
      v-if="blocked"
      class="button button--secondary"
      type="button"
      :disabled="pending"
      @click="emit('reload')"
    >
      Reload payment
    </button>
  </section>
</template>
