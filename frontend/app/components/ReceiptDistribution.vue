<script setup lang="ts">
import type { BriefUser } from '~/types/finance'
import type { Assignments, ReceiptDraft } from '~/types/receipt'
import { prepareReceipt, receiptAmount, receiptDifference } from '~/utils/receipt'
import { formatMoney } from '~/utils/money'

const props = defineProps<{ people: BriefUser[], currency: string, total: string, savedShares?: Record<string, number> }>()
const draft = defineModel<ReceiptDraft>({ required: true })
const { t } = useTranslation()
const { locale } = useLocale()
const ids = computed(() => props.people.map(p => p.id))
const gap = computed(() => receiptDifference(draft.value, props.total, props.currency))
const preview = computed(() => prepareReceipt(draft.value, props.total, props.currency, ids.value))
function all() {
  const assignments = Object.fromEntries(ids.value.map(id => [id, null]))
  for (const row of draft.value.lines) {
    row.assignments = { ...assignments }
    row.review = false
    delete row.mergedSeats
  }
  draft.value.remainder = gap.value && gap.value > 0 ? { ...assignments } : {}
  draft.value.remainderReview = false
  delete draft.value.remainderSeats
}
watch(ids, (allowed) => {
  function prune(assigned: Assignments) {
    const removed = Object.keys(assigned).filter(id => !allowed.includes(id))
    for (const id of removed) Reflect.deleteProperty(assigned, id)
    return removed.length > 0
  }
  for (const row of draft.value.lines) {
    if (prune(row.assignments)) {
      row.review = true
      delete row.mergedSeats
    }
  }
  if (prune(draft.value.remainder)) {
    draft.value.remainderReview = true
    delete draft.value.remainderSeats
  }
})
</script>

<template>
  <div class="stack">
    <UiText variant="heading2">
      {{ t('Assign purchases') }}
    </UiText>
    <UiButton
      variant="secondary"
      :disabled="!people.length"
      @click="all"
    >
      {{ t('Split equally') }}
    </UiButton>
    <ReceiptAssignees
      v-for="(row, index) in draft.lines"
      :key="row.key"
      v-model="row.assignments"
      :label="(row.name || t('Item') + ' ' + (index + 1)) + ' · ' + (currency && receiptAmount(row.amount, currency) !== null ? formatMoney(receiptAmount(row.amount, currency)!, currency, locale) : row.amount)"
      :people="people"
      :quantity="row.quantity"
      :review="row.review"
      @confirm="row.review = false; delete row.mergedSeats"
    />
    <ReceiptAssignees
      v-if="gap !== null && gap > 0"
      v-model="draft.remainder"
      :label="t('Unnamed item') + ' · ' + formatMoney(gap, currency, locale)"
      :people="people"
      quantity="1"
      :review="draft.remainderReview"
      @confirm="draft.remainderReview = false; delete draft.remainderSeats"
    />
    <template v-if="preview">
      <UiText
        v-for="person in people"
        :key="person.id"
      >
        {{ person.name }} · {{ formatMoney((savedShares ?? preview.shares)[person.id] ?? 0, currency, locale) }}
      </UiText>
    </template>
    <UiText
      v-else
      variant="help"
    >
      {{ t('Check the currency, total and assignments for every item.') }}
    </UiText>
  </div>
</template>
