<script setup lang="ts">
import type { ConnectView } from '~/types/connects'

definePageMeta({ middleware: 'auth' })
useHead({ title: 'Connections · Equo' })
const { state } = useCurrentUser()
const { items, pending, loaded, cursor, error, load } = useFinancialList<ConnectView>(() => '/v1/connects')
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
