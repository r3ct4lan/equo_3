<script setup lang="ts">
import type { ReceiptDraft, EditableLine } from '~/types/receipt'
import { editableLine, extendedPrice, receiptDifference } from '~/utils/receipt'
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
function updatePrice(row: EditableLine) {
  const price = extendedPrice(row, props.currency)
  if (price !== null) row.amount = price
}
</script>

<template>
  <div class="stack">
    <UiText variant="heading2">
      {{ t('Check receipt items') }}
    </UiText>
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
          v-model="row.quantity"
          inputmode="decimal"
          @update:model-value="updatePrice(row)"
        />
      </UiField>
      <UiField>
        <label :for="'price-' + row.key">{{ t('Unit price') }}</label><UiInput
          :id="'price-' + row.key"
          v-model="row.unitPrice"
          inputmode="decimal"
          @update:model-value="updatePrice(row)"
        />
      </UiField>
      <UiField>
        <label :for="'amount-' + row.key">{{ t('Line total') }}</label><UiInput
          :id="'amount-' + row.key"
          v-model="row.amount"
          inputmode="decimal"
        />
      </UiField>
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
