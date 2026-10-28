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
    <UiText
      id="debt-title"
      variant="heading1"
    >
      {{ debt?.title ?? 'Expense' }}
    </UiText>
    <AppLoadingState
      v-if="pending || state.status === 'unknown'"
      message="Loading expense…"
    />
    <UiCard
      v-if="debt && state.status === 'authenticated'"
      as="div"
      class="stack"
    >
      <UiNotice
        v-if="debt.isDeleted"
        tone="info"
      >
        Removed expense · the saved participant list is shown below.
      </UiNotice>
      <UiText v-if="debt.description">
        {{ debt.description }}
      </UiText>
      <UiDescriptionList>
        <div><dt>Total</dt><dd>{{ formatEuro(debt.totalAmount) }}</dd></div>
        <div><dt>Paid by</dt><dd>{{ debt.payer.name }}{{ debt.payer.isActive ? '' : ' (inactive)' }}</dd></div>
        <div><dt>Recorded by</dt><dd>{{ debt.createdBy.name }}{{ debt.createdBy.isActive ? '' : ' (inactive)' }}</dd></div>
        <div><dt>Split</dt><dd>{{ debt.participantCount }} people · {{ formatEuro(debt.shareAmount) }} each</dd></div>
      </UiDescriptionList>
      <UiButton
        v-if="debt.canEdit && !debt.isDeleted && debt.createdBy.id === state.user?.id"
        :to="'/debts/' + debt.id + '/edit'"
      >
        Edit expense
      </UiButton>
      <DeleteDebtForm
        v-if="debt.canDelete && !debt.isDeleted && debt.createdBy.id === state.user?.id && etag && validDebtEtag(etag)"
        :debt-id="debt.id"
        :etag="etag"
        @removed="navigateTo('/debts')"
        @reload="load"
      />
      <UiText variant="heading2">
        Participants
      </UiText>
      <ul class="stack">
        <li
          v-for="participant in debt.participants"
          :key="participant.debtParticipantId"
        >
          <UiLink
            v-if="participant.user.id !== state.user?.id && participant.connectId"
            :to="'/connects/' + participant.connectId"
          >
            {{ participant.user.name }}
          </UiLink>
          <span v-else>{{ participant.user.name }}</span>{{ participant.user.id === state.user?.id ? ' (you)' : '' }} · {{ formatEuro(participant.shareAmount) }}
          <span v-if="participant.isPayer"> · payer</span><span v-if="participant.isAuthor"> · author</span><span v-if="!participant.user.isActive"> · inactive</span>
          <UiLink
            v-if="participant.transferId && (participant.user.id === state.user?.id || debt.payer.id === state.user?.id)"
            :to="'/transfers/' + participant.transferId"
          >
            View operation
          </UiLink>
        </li>
      </ul>
      <UiText v-if="!debt.includePayerInSplit">
        The payer is excluded from the split.
      </UiText>
      <UiText variant="help">
        Shares round up to the next cent.
      </UiText>
      <time :datetime="debt.createdAt">{{ new Date(debt.createdAt).toLocaleString() }}</time>
    </UiCard>
    <AppErrorState
      v-if="error"
      :title="error.code === 'DEBT_NOT_AVAILABLE' ? 'Expense unavailable' : 'Expense could not be loaded'"
      message="Return to your expenses or try again."
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
      to="/debts"
    >
      Your expenses
    </UiButton>
  </section>
</template>
