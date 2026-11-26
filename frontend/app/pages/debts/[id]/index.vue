<script setup lang="ts">
import { formatDateTime, formatPeople } from '~/utils/locale'
import type { DebtView } from '~/types/finance'
import { formatMoney } from '~/utils/money'
import { validDebtEtag } from '~/utils/debt-update'

const { locale } = useLocale()

definePageMeta({ middleware: 'auth' })
useHead({ title: 'Expense · Equo' })
const route = useRoute()
const { state } = useCurrentUser()
const { item: debt, etag, pending, error, load } = useFinancialDetail<DebtView>(() => '/v1/debts/' + encodeURIComponent(String(route.params.id)))

const { t } = useTranslation()
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
      {{ debt?.title ?? t('Expense') }}
    </UiText>
    <AppLoadingState
      v-if="pending || state.status === 'unknown'"
      :message="t('Loading expense…')"
    />
    <UiCard
      v-if="debt && state.status === 'authenticated'"
      as="div"
      class="stack"
    >
      <UiNotice
        v-if="debt.isDeleted"
        tone="warning"
      >
        {{ t('Removed expense · the saved participant list is shown below.') }}
      </UiNotice>
      <UiText v-if="debt.description">
        {{ debt.description }}
      </UiText>
      <UiText v-if="debt.viewerCurrency !== debt.currency && debt.equivalentAmount != null">
        ≈ {{ formatMoney(debt.equivalentAmount, debt.viewerCurrency, locale) }}
      </UiText>
      <UiDescriptionList>
        <div><dt>{{ t('Total') }}</dt><dd>{{ formatMoney(debt.totalAmount, debt.currency, locale) }}</dd></div>
        <div><dt>{{ t('Paid by') }}</dt><dd>{{ debt.payer.name }}{{ debt.payer.isActive ? '' : t(' (inactive)') }}</dd></div>
        <div><dt>{{ t('Recorded by') }}</dt><dd>{{ debt.createdBy.name }}{{ debt.createdBy.isActive ? '' : t(' (inactive)') }}</dd></div>
        <div><dt>{{ t('Split') }}</dt><dd>{{ formatPeople(debt.participantCount, locale) }} · {{ debt.shareAmount === null ? t('Different share amounts') : formatMoney(debt.shareAmount, debt.currency, locale) + ' ' + t('each') }}</dd></div>
      </UiDescriptionList>
      <template v-if="debt.receipt">
        <UiText variant="heading2">
          {{ t('Receipt items') }}
        </UiText>
        <ul class="stack">
          <li
            v-for="(line, index) in debt.receipt.lines"
            :key="index"
          >
            {{ line.name || t('Unnamed item') }} · {{ line.quantity }} · {{ formatMoney(line.amount, debt.currency, locale) }}
            <span
              v-for="participant in debt.participants.filter(p => Object.hasOwn(line.assignments, p.user.id))"
              :key="participant.user.id"
            >
              · {{ participant.user.name }} {{ line.assignments[participant.user.id] ?? '' }}
            </span>
          </li>
          <li v-if="debt.totalAmount > debt.receipt.lines.reduce((sum, line) => sum + line.amount, 0)">
            {{ t('Unnamed item') }} · {{ formatMoney(debt.totalAmount - debt.receipt.lines.reduce((sum, line) => sum + line.amount, 0), debt.currency, locale) }}
          </li>
        </ul>
        <UiText v-if="debt.receipt.discount">
          {{ t('Discount') }} · {{ formatMoney(debt.receipt.discount, debt.currency, locale) }}
        </UiText>
        <ReceiptPhotos
          :debt="debt"
          :etag="etag"
          removable
          @reload="load"
        />
      </template>
      <UiButton
        v-if="debt.canEdit && !debt.isDeleted && debt.createdBy.id === state.user?.id"
        :to="'/debts/' + debt.id + '/edit'"
      >
        {{ t('Edit expense') }}
      </UiButton>
      <DeleteDebtForm
        v-if="debt.canDelete && !debt.isDeleted && debt.createdBy.id === state.user?.id && etag && validDebtEtag(etag)"
        :debt-id="debt.id"
        :etag="etag"
        @removed="navigateTo('/debts')"
        @reload="load"
      />
      <UiText variant="heading2">
        {{ t('Participants') }}
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
          <span v-else>{{ participant.user.name }}</span>{{ participant.user.id === state.user?.id ? t(' (you)') : '' }} · {{ formatMoney(participant.shareAmount, debt.currency, locale) }}
          <span v-if="debt.viewerCurrency !== debt.currency && participant.equivalentAmount != null"> · ≈ {{ formatMoney(participant.equivalentAmount, debt.viewerCurrency, locale) }}</span>
          <span v-if="participant.remainingAmount != null && !debt.isDeleted"> · {{ t('Remaining share') }} {{ formatMoney(participant.remainingAmount, debt.currency, locale) }}</span>
          <span v-if="participant.isPayer"> · {{ t('payer') }}</span><span v-if="participant.isAuthor"> · {{ t('author') }}</span><span v-if="!participant.user.isActive"> · {{ t('inactive') }}</span>
          <UiLink
            v-if="participant.transferId && (participant.user.id === state.user?.id || debt.payer.id === state.user?.id)"
            :to="'/transfers/' + participant.transferId"
          >
            {{ t('View operation') }}
          </UiLink>
        </li>
      </ul>
      <UiText v-if="!debt.includePayerInSplit">
        {{ t('The payer is excluded from the split.') }}
      </UiText>
      <UiText variant="help">
        {{ t('Shares round up to the smallest currency unit.') }}
      </UiText>
      <time :datetime="debt.createdAt">{{ formatDateTime(new Date(debt.createdAt), locale) }}</time>
    </UiCard>
    <AppErrorState
      v-if="error"
      :title="error.code === 'DEBT_NOT_AVAILABLE' ? 'Expense unavailable' : 'Expense could not be loaded'"
      :message="t('Return to your expenses or try again.')"
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
      to="/debts"
    >
      {{ t('Your expenses') }}
    </UiButton>
  </section>
</template>
