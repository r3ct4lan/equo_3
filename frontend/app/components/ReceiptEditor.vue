<script setup lang="ts">
import type { ReceiptDraft, EditableLine, PriceField } from '~/types/receipt'
import { editableLine, calculateField, receiptDifference } from '~/utils/receipt'
import { formatMoney } from '~/utils/money'

const props = defineProps<{ currency: string, total: string }>()
const draft = defineModel<ReceiptDraft>({ required: true })
const { t } = useTranslation()
const { locale } = useLocale()
const difference = computed(() => receiptDifference(draft.value, props.total, props.currency))
watch(difference, (amount) => {
  if (amount !== -draft.value.discount) draft.value.discount = 0
  if (amount !== null && amount <= 0) draft.value.remainder = {}
})
watch(() => props.currency, (currency) => {
  for (const row of draft.value.lines) {
    if (row.calculated) calculateField(row, row.calculated, currency)
  }
})
const edits = new WeakMap<EditableLine, PriceField[]>()
function updatePrice(row: EditableLine, field: PriceField, value: string) {
  delete row.calculated
  row[field] = value
  const recent = [field, ...(edits.get(row) ?? []).filter(key => key !== field)].slice(0, 2)
  edits.set(row, recent)
  if (!value) return
  const others: PriceField[] = field === 'amount'
    ? ['unitPrice', 'quantity']
    : field === 'quantity' ? ['amount', 'unitPrice'] : ['amount', 'quantity']
  const target = others.find(key => !row[key]) ?? others.find(key => !recent.includes(key))!
  calculateField(row, target, props.currency)
}
</script>

<template>
  <div class="stack">
    <fieldset
      v-for="(row, index) in draft.lines"
      :key="row.key"
      class="stack"
    >
      <legend>{{ t('Item') }} {{ index + 1 }}</legend>
      <UiField>
        <label :for="'name-' + row.key">{{ t('Item name') }}</label><UiInput
          :id="'name-' + row.key"
          v-model="row.name"
          maxlength="500"
        />
      </UiField>
      <UiField>
        <label :for="'qty-' + row.key">{{ t('Quantity') }}</label><UiInput
          :id="'qty-' + row.key"
          :model-value="row.quantity"
          inputmode="decimal"
          @update:model-value="updatePrice(row, 'quantity', $event)"
          @blur="!row.quantity && calculateField(row, 'quantity', currency)"
        />
      </UiField>
      <UiField>
        <label :for="'price-' + row.key">{{ t('Unit price') }}</label><UiInput
          :id="'price-' + row.key"
          :model-value="row.unitPrice"
          inputmode="decimal"
          @update:model-value="updatePrice(row, 'unitPrice', $event)"
          @blur="!row.unitPrice && calculateField(row, 'unitPrice', currency)"
        />
      </UiField>
      <UiField>
        <label :for="'amount-' + row.key">{{ t('Line total') }}</label><UiInput
          :id="'amount-' + row.key"
          :model-value="row.amount"
          inputmode="decimal"
          @update:model-value="updatePrice(row, 'amount', $event)"
          @blur="!row.amount && calculateField(row, 'amount', currency)"
        />
      </UiField>
      <slot
        name="assignment"
        :row="row"
        :index="index"
      />
      <UiButton
        variant="secondary"
        @click="draft.lines.splice(index, 1)"
      >
        {{ t('Remove item') }}
      </UiButton>
    </fieldset>
    <UiButton
      variant="secondary"
      @click="draft.lines.push(editableLine())"
    >
      {{ t('Add item') }}
    </UiButton>
    <slot name="total" />
    <UiText v-if="difference !== null && difference > 0">
      {{ t('Unnamed item') }} · {{ formatMoney(difference, currency, locale) }}
    </UiText>
    <template v-if="difference !== null && difference < 0">
      <UiText>{{ t('Items exceed the printed total by') }} {{ formatMoney(-difference, currency, locale) }}</UiText>
      <UiButton
        variant="secondary"
        :disabled="draft.discount === -difference"
        @click="draft.discount = -difference"
      >
        {{ t(draft.discount === -difference ? 'Discount confirmed' : 'Confirm discount') }}
      </UiButton>
    </template>
  </div>
</template>
