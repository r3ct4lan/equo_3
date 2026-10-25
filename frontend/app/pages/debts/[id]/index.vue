<script setup lang="ts">
import type { DebtView } from '~/types/finance'
import { formatEuro } from '~/utils/money'
import { validDebtEtag } from '~/utils/debt-update'

definePageMeta({ middleware: 'auth' })
useHead({ title: 'Expense · Equo' })
const route = useRoute()
const { state } = useCurrentUser()
const { item: debt, etag, pending, error, load } = useFinancialDetail<DebtView>(() => '/v1/debts/' + encodeURIComponent(String(route.params.id)))
</script>

<template>
  <section
    class="stack"
    aria-labelledby="debt-title"
  >
    <h1 id="debt-title">
      {{ debt?.title ?? 'Expense' }}
    </h1>
    <AppLoadingState
      v-if="pending || state.status === 'unknown'"
      message="Loading expense…"
    />
    <div
      v-if="debt && state.status === 'authenticated'"
      class="panel stack"
    >
      <p
        v-if="debt.isDeleted"
        role="status"
      >
        Removed expense · the saved participant list is shown below.
      </p>
      <p v-if="debt.description">
        {{ debt.description }}
      </p>
      <dl class="profile-list">
        <div><dt>Total</dt><dd>{{ formatEuro(debt.totalAmount) }}</dd></div>
        <div><dt>Paid by</dt><dd>{{ debt.payer.name }}{{ debt.payer.isActive ? '' : ' (inactive)' }}</dd></div>
        <div><dt>Recorded by</dt><dd>{{ debt.createdBy.name }}{{ debt.createdBy.isActive ? '' : ' (inactive)' }}</dd></div>
        <div><dt>Split</dt><dd>{{ debt.participantCount }} people · {{ formatEuro(debt.shareAmount) }} each</dd></div>
      </dl>
      <NuxtLink
        v-if="debt.canEdit && !debt.isDeleted && debt.createdBy.id === state.user?.id"
        class="button"
        :to="'/debts/' + debt.id + '/edit'"
      >Edit expense</NuxtLink>
      <DeleteDebtForm
        v-if="debt.canDelete && !debt.isDeleted && debt.createdBy.id === state.user?.id && etag && validDebtEtag(etag)"
        :debt-id="debt.id"
        :etag="etag"
        @removed="load"
        @reload="load"
      />
      <h2>Participants</h2>
      <ul class="stack">
        <li
          v-for="participant in debt.participants"
          :key="participant.debtParticipantId"
        >
          <NuxtLink
            v-if="participant.user.id !== state.user?.id && participant.connectId"
            :to="'/connects/' + participant.connectId"
          >{{ participant.user.name }}</NuxtLink>
          <span v-else>{{ participant.user.name }}</span>{{ participant.user.id === state.user?.id ? ' (you)' : '' }} · {{ formatEuro(participant.shareAmount) }}
          <span v-if="participant.isPayer"> · payer</span><span v-if="participant.isAuthor"> · author</span><span v-if="!participant.user.isActive"> · inactive</span>
          <NuxtLink
            v-if="participant.transferId && (participant.user.id === state.user?.id || debt.payer.id === state.user?.id)"
            :to="'/transfers/' + participant.transferId"
          >View operation</NuxtLink>
        </li>
      </ul>
      <p v-if="!debt.includePayerInSplit">
        The payer is excluded from the split.
      </p>
      <p class="form-help">
        Shares round up to the next cent.
      </p>
      <time :datetime="debt.createdAt">{{ new Date(debt.createdAt).toLocaleString() }}</time>
    </div>
    <AppErrorState
      v-if="error"
      :title="error.code === 'DEBT_NOT_AVAILABLE' ? 'Expense unavailable' : 'Expense could not be loaded'"
      message="Return to your expenses or try again."
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
      to="/debts"
    >Your expenses</NuxtLink>
  </section>
</template>
