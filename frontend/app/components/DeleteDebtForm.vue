<script setup lang="ts">
import { ApiClientError, toApiClientError, type ApiClientError as ApiError } from '~/utils/api-error'
import { validDebtEtag } from '~/utils/debt-update'

const props = defineProps<{ debtId: string, etag: string }>()
const emit = defineEmits<{ removed: [], reload: [] }>()
const { $auth } = useNuxtApp()
const confirming = ref(false)
const pending = ref(false)
const blocked = ref(false)
const error = ref<ApiError | null>(null)
const trigger = ref<HTMLButtonElement | null>(null)
const confirmButton = ref<HTMLButtonElement | null>(null)
const message = computed(() => {
  if (error.value?.code === 'VERSION_CONFLICT') return 'Someone changed this expense. Reload it and review the current version before removing it.'
  if (error.value && (error.value.status === null || error.value.status >= 500)) return 'The result is not confirmed. Reload the expense before trying anything else.'
  if (['DEBT_FROZEN', 'DEBT_DELETED', 'DEBT_NOT_AVAILABLE', 'FORBIDDEN', 'PRECONDITION_REQUIRED'].includes(error.value?.code ?? '')) return 'This expense can no longer be removed. Reload it to check its current state.'
  return error.value ? 'The expense could not be removed. Check your session and try again.' : null
})

async function openConfirmation(): Promise<void> {
  if (pending.value || blocked.value) return
  confirming.value = true
  error.value = null
  await nextTick()
  confirmButton.value?.focus()
}

async function cancel(): Promise<void> {
  confirming.value = false
  error.value = null
  await nextTick()
  trigger.value?.focus()
}

async function remove(): Promise<void> {
  if (pending.value || blocked.value || !validDebtEtag(props.etag)) return
  pending.value = true
  error.value = null
  try {
    await $auth.protectedRequest<unknown>('/v1/debts/' + encodeURIComponent(props.debtId), {
      method: 'DELETE',
      headers: { 'If-Match': props.etag },
      onResponse({ response }) {
        if (response.status >= 200 && response.status < 300 && response.status !== 204) {
          throw new ApiClientError({ kind: 'unknown', code: 'DEBT_DELETE_UNCONFIRMED', message: 'Expense removal was not confirmed.' })
        }
      }
    })
    blocked.value = true
    confirming.value = false
    emit('removed')
  }
  catch (failure) {
    error.value = toApiClientError(failure)
    blocked.value = error.value.status === null || error.value.status >= 500 || ['VERSION_CONFLICT', 'PRECONDITION_REQUIRED', 'DEBT_FROZEN', 'DEBT_DELETED', 'DEBT_NOT_AVAILABLE', 'FORBIDDEN', 'DEBT_DELETE_UNCONFIRMED'].includes(error.value.code)
  }
  finally {
    pending.value = false
  }
}
</script>

<template>
  <section
    class="stack"
    aria-labelledby="remove-expense-title"
  >
    <h2 id="remove-expense-title">
      Remove expense
    </h2>
    <p>This removes its automatic shares from every balance. Payment history remains available.</p>
    <button
      v-if="!confirming && !blocked"
      ref="trigger"
      class="button button--danger"
      type="button"
      @click="openConfirmation"
    >
      Remove expense
    </button>
    <div
      v-if="confirming"
      class="panel stack"
      role="alertdialog"
      aria-labelledby="remove-confirm-title"
      aria-describedby="remove-confirm-description"
    >
      <h3 id="remove-confirm-title">
        Remove this expense permanently?
      </h3>
      <p id="remove-confirm-description">
        This cannot be undone. The saved expense and participant list will remain read-only.
      </p>
      <FormErrorSummary :message="message" />
      <p
        v-if="error?.requestId"
        class="support-reference"
      >
        Support reference: {{ error.requestId }}
      </p>
      <button
        ref="confirmButton"
        class="button button--danger"
        type="button"
        :disabled="pending || blocked"
        :aria-busy="pending"
        @click="remove"
      >
        {{ pending ? 'Removing expense…' : 'Yes, remove expense' }}
      </button>
      <button
        v-if="!blocked"
        class="button button--secondary"
        type="button"
        :disabled="pending"
        @click="cancel"
      >
        Keep expense
      </button>
    </div>
    <button
      v-if="blocked"
      class="button button--secondary"
      type="button"
      :disabled="pending"
      @click="emit('reload')"
    >
      Reload expense
    </button>
  </section>
</template>
