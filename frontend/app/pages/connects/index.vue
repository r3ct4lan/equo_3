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
    <UiText
      id="connects-title"
      variant="heading1"
    >
      Your connections
    </UiText>
    <UiToast
      v-if="invitationFeedback"
      :tone="invitationFeedback.kind === 'error' ? 'error' : 'info'"
    >
      <UiText>{{ invitationFeedback.message }}</UiText>
      <UiButton
        variant="secondary"
        @click="invitationFeedback = null"
      >
        Dismiss
      </UiButton>
    </UiToast>
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
        <UiCard
          v-for="connect in items"
          :key="connect.id"
          as="li"
          class="stack"
        >
          <UiLink :to="'/connects/' + connect.id">
            {{ connect.otherUser.name }}
          </UiLink>
          <ConnectBalance :amount="connect.balanceAmount" />
          <UiText v-if="!connect.otherUser.isActive">
            This account is inactive.
          </UiText>
        </UiCard>
      </ul>
      <UiButton
        v-if="cursor"
        variant="secondary"
        :disabled="pending"
        @click="load(true)"
      >
        Load more
      </UiButton>
      <AppErrorState
        v-if="error"
        title="Connections could not be loaded"
        message="Try again to load your connections."
        :request-id="error.requestId"
      />
      <UiButton
        v-if="error"
        variant="secondary"
        :disabled="pending"
        @click="load(items.length
          > 0)"
      >
        Try again
      </UiButton>
    </template>
    <AppLoadingState
      v-else
      message="Checking your session…"
    />
  </section>
</template>
