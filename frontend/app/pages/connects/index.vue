<script setup lang="ts">
import type { ConnectPage, ConnectView } from '~/types/connects'
import { toApiClientError, type ApiClientError } from '~/utils/api-error'

definePageMeta({ middleware: 'auth' })
useHead({ title: 'Connections · Equo' })
const { $auth } = useNuxtApp()
const { state } = useCurrentUser()
const items = ref<ConnectView[]>([])
let generation = 0
const pending = ref(false)
const loaded = ref(false)
const cursor = ref<string | null>(null)
const error = ref<ApiClientError | null>(null)
async function load(more = false): Promise<void> {
  if (state.value.status !== 'authenticated') return
  const requestGeneration = ++generation
  const viewer = state.value.user?.id
  pending.value = true
  error.value = null
  try {
    const result = await $auth.protectedRequest<ConnectPage>('/v1/connects', { query: more && cursor.value ? { cursor: cursor.value } : {} })
    if (requestGeneration !== generation || state.value.user?.id !== viewer) return
    items.value = more ? [...items.value, ...result.items] : result.items
    cursor.value = result.pagination.nextCursor
    loaded.value = true
  }
  catch (failure) {
    if (requestGeneration === generation) error.value = toApiClientError(failure)
  }
  finally {
    if (requestGeneration === generation) pending.value = false
  }
}
onMounted(() => {
  void load()
})
watch(() => [state.value.status, state.value.user?.id], () => {
  ++generation
  const status = state.value.status
  if (status === 'authenticated') void load()
  else {
    items.value = []
    loaded.value = false
    cursor.value = null
  }
})
</script>

<template>
  <section
    class="stack"
    aria-labelledby="connects-title"
  >
    <h1 id="connects-title">
      Your connections
    </h1>
    <template v-if="state.status === 'authenticated'">
      <CreateInvitation />
      <AppLoadingState
        v-if="pending"
        message="Loading connections…"
      />
      <AppEmptyState
        v-if="loaded && items.length === 0"
        title="No connections yet"
        message="Send an invitation to someone you share expenses with."
      />
      <ul
        v-if="items.length"
        class="stack"
      >
        <li
          v-for="connect in items"
          :key="connect.id"
          class="panel stack"
        >
          <NuxtLink :to="'/connects/' + connect.id">{{ connect.otherUser.name }}</NuxtLink>
          <ConnectBalance :amount="connect.balanceAmount" />
          <p v-if="!connect.otherUser.isActive">
            This account is inactive.
          </p>
        </li>
      </ul>
      <button
        v-if="cursor"
        class="button button--secondary"
        type="button"
        :disabled="pending"
        @click="load(true)"
      >
        Load more
      </button>
      <AppErrorState
        v-if="error"
        title="Connections could not be loaded"
        message="Try again to load your connections."
        :request-id="error.requestId"
      />
      <button
        v-if="error"
        class="button button--secondary"
        type="button"
        :disabled="pending"
        @click="load(items.length > 0)"
      >
        Try again
      </button>
    </template>
    <AppLoadingState
      v-else
      message="Checking your session…"
    />
  </section>
</template>
