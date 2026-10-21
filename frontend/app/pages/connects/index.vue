<script setup lang="ts">
import type { ConnectView } from '~/types/connects'
import { takeInvitationFeedback } from '~/utils/connect-invitations'

definePageMeta({ middleware: 'auth' })
useHead({ title: 'Connections · Equo' })
const { state } = useCurrentUser()
const { items, pending, loaded, cursor, error, load } = useFinancialList<ConnectView>(() => '/v1/connects')
const invitationFeedback = ref<ReturnType<typeof takeInvitationFeedback>>(null)
onMounted(() => {
  invitationFeedback.value = takeInvitationFeedback()
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
    <div
      v-if="invitationFeedback"
      class="invitation-toast"
      :role="invitationFeedback.kind === 'error' ? 'alert' : 'status'"
    >
      <p>{{ invitationFeedback.message }}</p>
      <button
        type="button"
        class="button button--secondary"
        @click="invitationFeedback = null"
      >
        Dismiss
      </button>
    </div>
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
