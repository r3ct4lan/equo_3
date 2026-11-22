<script setup lang="ts">
import { formatDateTime } from '~/utils/locale'
import type { TransferView } from '~/types/finance'
import { formatMoney } from '~/utils/money'
import { validTransferEtag } from '~/utils/manual-transfer'

const { locale } = useLocale()

definePageMeta({ middleware: 'auth' })
useHead({ title: 'Operation · Equo' })
const route = useRoute()
const { state } = useCurrentUser()
const { item: transfer, etag, pending, error, load } = useFinancialDetail<TransferView>(() => '/v1/transfers/' + encodeURIComponent(String(route.params.id)))

const { t } = useTranslation()
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
      {{ t('Operation') }}
    </UiText>
    <AppLoadingState
      v-if="pending || state.status === 'unknown'"
      :message="t('Loading operation…')"
    />
    <UiCard
      v-if="transfer && state.status === 'authenticated'"
      as="div"
      class="stack"
    >
      <UiText variant="heading2">
        {{ transfer.type === 'DEBT_SHARE' ? t('Expense share') : t('Payment') }} · {{ formatMoney(transfer.amount, transfer.currency, locale) }}
      </UiText>
      <UiText v-if="transfer.viewerCurrency !== transfer.currency && transfer.equivalentAmount != null">
        ≈ {{ formatMoney(transfer.equivalentAmount, transfer.viewerCurrency, locale) }}
      </UiText>
      <UiText v-if="transfer.remainingAmount != null && !transfer.isDeleted">
        {{ t('Remaining share') }} · {{ formatMoney(transfer.remainingAmount, transfer.currency, locale) }}
      </UiText>
      <UiText v-if="transfer.excessAmount != null && transfer.shareCurrency">
        {{ t('Credited') }} · {{ formatMoney(transfer.creditedAmount ?? 0, transfer.shareCurrency, locale) }}.
        {{ t('Overpayment') }} · {{ formatMoney(transfer.excessAmount, transfer.currency, locale) }}.
      </UiText>
      <UiText>{{ transfer.sender.name }} → {{ transfer.receiver.name }}</UiText>
      <UiText v-if="!transfer.sender.isActive || !transfer.receiver.isActive">
        {{ t('One of these accounts is inactive.') }}
      </UiText>
      <UiText v-if="transfer.createdBy">
        {{ t('Recorded by') }} {{ transfer.createdBy.name }}
      </UiText>
      <UiNotice
        v-if="transfer.isDeleted"
        tone="warning"
      >
        {{ t('Removed') }}
      </UiNotice>
      <UiLink
        v-if="transfer.debtReference?.isAccessible"
        :to="'/debts/' + transfer.debtReference.id"
      >
        {{ transfer.debtReference.title }}{{ transfer.debtReference.isDeleted ? t(' (removed)') : '' }}
      </UiLink>
      <UiText v-else-if="transfer.debtReference">
        {{ t('Related expense unavailable') }}
      </UiText>
      <UiLink
        v-if="transfer.relatedTransferId"
        :to="'/transfers/' + transfer.relatedTransferId"
      >
        {{ t('Related expense share') }}
      </UiLink>
      <time :datetime="transfer.createdAt">{{ formatDateTime(new Date(transfer.createdAt), locale) }}</time>
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
        {{ t('Connection and history') }}
      </UiButton>
    </UiCard>
    <AppErrorState
      v-if="error"
      :title="error.code === 'TRANSFER_NOT_AVAILABLE' ? 'Operation unavailable' : 'Operation could not be loaded'"
      :message="t('Return to your connections or try again.')"
      :request-id="error.requestId"
    />
    <UiButton
      v-if="error && (error.status === null || error.status
        >= 500)"
      variant="secondary"

      @click="load()"
    >
      {{ t('Try again') }}
    </UiButton>
    <UiButton
      variant="secondary"
      icon="back"
      to="/connects"
    >
      {{ t('Your connections') }}
    </UiButton>
  </section>
</template>
