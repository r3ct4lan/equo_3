<script setup lang="ts">
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
    <h1 id="connection-title">
      Connection
    </h1>
    <p
      v-if="recorded"
      role="status"
    >
      Payment recorded.
    </p>
    <AppLoadingState
      v-if="pending || state.status === 'unknown'"
      message="Loading connection…"
    />
    <div
      v-if="connect && state.status === 'authenticated'"
      class="panel stack"
    >
      <h2>{{ connect.otherUser.name }}</h2>
      <ConnectBalance :amount="connect.balanceAmount" />
      <p v-if="!connect.otherUser.isActive">
        This account is inactive. New expenses and transfers are unavailable until it is reactivated.
      </p>
      <NuxtLink
        v-if="connect.canCreateDebt"
        class="button"
        to="/debts/new"
      >New expense</NuxtLink>
      <CreateManualTransferForm
        v-if="connect.canCreateTransfer"
        :connect="connect"
        @created="refreshAfterPayment"
        @reload="load"
      />
      <TransferHistory :connect-id="connect.id" />
    </div>
    <AppErrorState
      v-if="error"
      :title="error.code === 'RESOURCE_NOT_FOUND' ? 'Connection unavailable' : 'Connection could not be loaded'"
      message="Return to your connections or try again."
      :request-id="error.requestId"
    />
    <button
      v-if="error && (error.status === null || error.status >= 500)"
      class="button button--secondary"
      type="button"
      @click="load()"
    >
      Try again
    </button>
    <NuxtLink
      class="button button--secondary"
      to="/connects"
    >Your connections</NuxtLink>
  </section>
</template>
