<script setup lang="ts">
import type { FocusTarget } from '~/types/ui'
import { ApiClientError, toApiClientError, type ApiClientError as ApiError } from '~/utils/api-error'
import { validDebtEtag } from '~/utils/debt-update'

const props = defineProps<{ debtId: string, etag: string }>()
const emit = defineEmits<{ removed: [], reload: [] }>()
const { $auth } = useNuxtApp()
const confirming = ref(false)
const pending = ref(false)
const blocked = ref(false)
const error = ref<ApiError | null>(null)
const trigger = ref<FocusTarget | null>(null)
const confirmButton = ref<FocusTarget | null>(null)
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
    <UiText
      id="remove-expense-title"
      variant="heading2"
    >
      Remove expense
    </UiText>
    <UiText>This removes its automatic shares from every balance. Payment history remains available.</UiText>
    <UiButton
      v-if="!confirming && !blocked"
      ref="trigger"
      variant="danger"
      @click="openConfirmation"
    >
      Remove expense
    </UiButton>
    <UiCard
      v-if="confirming"
      as="div"
      class="stack"
      role="alertdialog"
      aria-labelledby="remove-confirm-title"
      aria-describedby="remove-confirm-description"
    >
      <UiText
        id="remove-confirm-title"
        variant="heading3"
      >
        Remove this expense permanently?
      </UiText>
      <UiText id="remove-confirm-description">
        This cannot be undone. The saved expense and participant list will remain read-only.
      </UiText>
      <FormErrorSummary
        :message="message"
        :request-id="error?.requestId"
      />
      <UiButton
        ref="confirmButton"
        variant="danger"
        :disabled="pending || blocked"
        :pending="pending"
        @click="remove"
      >
        {{ pending ? 'Removing expense…' : 'Yes, remove expense' }}
      </UiButton>
      <UiButton
        v-if="!blocked"
        variant="secondary"
        :disabled="pending"
        @click="cancel"
      >
        Keep expense
      </UiButton>
    </UiCard>
    <UiButton
      v-if="blocked"
      variant="secondary"
      :disabled="pending"
      @click="emit('reload')"
    >
      Reload expense
    </UiButton>
  </section>
</template>
