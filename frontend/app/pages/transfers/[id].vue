<script setup lang="ts">
import type { TransferView } from '~/types/finance'
import { formatEuro } from '~/utils/money'
import { validTransferEtag } from '~/utils/manual-transfer'

definePageMeta({ middleware: 'auth' })
useHead({ title: 'Operation · Equo' })
const route = useRoute()
const { state } = useCurrentUser()
const { item: transfer, etag, pending, error, load } = useFinancialDetail<TransferView>(() => '/v1/transfers/' + encodeURIComponent(String(route.params.id)))
</script>

<template>
  <section
    class="stack"
    aria-labelledby="transfer-title"
  >
    <UiText
      id="transfer-title"
      variant="heading1"
    >
      Operation
    </UiText>
    <AppLoadingState
      v-if="pending || state.status === 'unknown'"
      message="Loading operation…"
    />
    <UiCard
      v-if="transfer && state.status === 'authenticated'"
      as="div"
      class="stack"
    >
      <UiText variant="heading2">
        {{ transfer.type === 'DEBT_SHARE' ? 'Expense share' : 'Payment' }} · {{ formatEuro(transfer.amount) }}
      </UiText>
      <UiText>{{ transfer.sender.name }} → {{ transfer.receiver.name }}</UiText>
      <UiText v-if="!transfer.sender.isActive || !transfer.receiver.isActive">
        One of these accounts is inactive.
      </UiText>
      <UiText v-if="transfer.createdBy">
        Recorded by {{ transfer.createdBy.name }}
      </UiText>
      <UiNotice
        v-if="transfer.isDeleted"
        tone="info"
      >
        Removed · does not affect the balance
      </UiNotice>
      <UiLink
        v-if="transfer.debtReference?.isAccessible"
        :to="'/debts/' + transfer.debtReference.id"
      >
        {{ transfer.debtReference.title }}{{ transfer.debtReference.isDeleted ? ' (removed)' : '' }}
      </UiLink>
      <UiText v-else-if="transfer.debtReference">
        Related expense unavailable
      </UiText>
      <UiLink
        v-if="transfer.relatedTransferId"
        :to="'/transfers/' + transfer.relatedTransferId"
      >
        Related expense share
      </UiLink>
      <time :datetime="transfer.createdAt">{{ new Date(transfer.createdAt).toLocaleString() }}</time>
      <ManageManualTransfer
        v-if="transfer.type === 'MANUAL' && transfer.canEdit && transfer.canDelete && transfer.createdBy?.id === state.user?.id && etag && validTransferEtag(etag)"
        :transfer="transfer"
        :etag="etag"
        @reload="load"
        @removed="direction => navigateTo({ path: '/transfers', query: { direction } })"
      />
      <UiButton
        variant="secondary"
        :to="'/connects/' + transfer.connectId"
      >
        Connection and history
      </UiButton>
    </UiCard>
    <AppErrorState
      v-if="error"
      :title="error.code === 'TRANSFER_NOT_AVAILABLE' ? 'Operation unavailable' : 'Operation could not be loaded'"
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
    <UiLink to="/connects">
      Your connections
    </UiLink>
  </section>
</template>
