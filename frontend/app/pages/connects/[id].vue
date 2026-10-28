<script setup lang="ts">
import type { DialogTarget, FocusTarget } from '~/types/ui'
import type { ConnectView } from '~/types/connects'
import { toApiClientError, type ApiClientError } from '~/utils/api-error'

definePageMeta({ middleware: 'auth' })
useHead({ title: 'Connection · Equo' })
const route = useRoute()
const { $auth } = useNuxtApp()
const { state } = useCurrentUser()
const connect = ref<ConnectView | null>(null)
let generation = 0
const pending = ref(false)
const error = ref<ApiClientError | null>(null)
const recorded = ref(false)
const paymentDialog = useTemplateRef<DialogTarget>('paymentDialog')
const paymentTrigger = useTemplateRef<FocusTarget>('paymentTrigger')
async function openPayment(): Promise<void> {
  if (!connect.value?.canCreateTransfer || paymentDialog.value?.open) return
  paymentDialog.value?.showModal()
  await nextTick()
  paymentDialog.value?.focusFirst('#payment-direction')
}
function closePayment(restoreFocus = true): void {
  if (!paymentDialog.value?.open) return
  paymentDialog.value.close()
  if (restoreFocus) void nextTick(() => paymentTrigger.value?.focus())
}
async function load(): Promise<void> {
  if (state.value.status !== 'authenticated') return
  const requestGeneration = ++generation
  const viewer = state.value.user?.id
  pending.value = true
  error.value = null
  connect.value = null
  try {
    const result = await $auth.protectedRequest<ConnectView>('/v1/connects/' + encodeURIComponent(String(route.params.id)))
    if (requestGeneration === generation && state.value.user?.id === viewer) connect.value = result
  }
  catch (failure) {
    if (requestGeneration === generation) error.value = toApiClientError(failure)
  }
  finally {
    if (requestGeneration === generation) pending.value = false
  }
}
async function refreshAfterPayment(): Promise<void> {
  closePayment(false)
  recorded.value = true
  await load()
}
onBeforeUnmount(() => {
  ++generation
})
onMounted(() => {
  void load()
})
watch(() => [state.value.status, state.value.user?.id, route.params.id], () => {
  ++generation
  connect.value = null
  recorded.value = false
  if (state.value.status === 'authenticated') void load()
})
</script>

<template>
  <section
    class="stack"
    aria-labelledby="connection-title"
  >
    <UiText
      id="connection-title"
      variant="heading1"
    >
      Connection
    </UiText>
    <UiText
      v-if="recorded"
      role="status"
    >
      Payment recorded.
    </UiText>
    <AppLoadingState
      v-if="pending || state.status === 'unknown'"
      message="Loading connection…"
    />
    <UiCard
      v-if="connect && state.status === 'authenticated'"
      as="div"
      class="stack"
    >
      <UiText variant="heading2">
        {{ connect.otherUser.name }}
      </UiText>
      <ConnectBalance :amount="connect.balanceAmount" />
      <UiText v-if="!connect.otherUser.isActive">
        This account is inactive. New expenses and transfers are unavailable until it is reactivated.
      </UiText>
      <UiButton
        v-if="connect.canCreateDebt"
        icon="add"
        to="/debts/new"
      >
        New expense
      </UiButton>
      <template v-if="connect.canCreateTransfer">
        <UiButton
          ref="paymentTrigger"
          @click="openPayment"
        >
          Make a payment
        </UiButton>
        <UiDialog
          ref="paymentDialog"
          aria-labelledby="payment-title"
          @cancel.prevent="closePayment()"
        >
          <template #actions>
            <UiButton
              variant="secondary"
              icon="close"
              class="ui-dialog__close"
              aria-label="Close"
              @click="closePayment()"
            />
          </template>
          <CreateManualTransferForm
            :connect="connect"
            @created="refreshAfterPayment"
            @reload="load"
          />
        </UiDialog>
      </template>
      <TransferHistory :connect-id="connect.id" />
    </UiCard>
    <AppErrorState
      v-if="error"
      :title="error.code === 'RESOURCE_NOT_FOUND' ? 'Connection unavailable' : 'Connection could not be loaded'"
      message="Return to your connections or try again."
      :request-id="error.requestId"
    />
    <UiButton
      v-if="error && (error.status === null || error.status
        >= 500)"
      variant="secondary"

      @click="load()"
    >
      Try again
    </UiButton>
    <UiButton
      variant="secondary"
      to="/connects"
    >
      Your connections
    </UiButton>
  </section>
</template>
