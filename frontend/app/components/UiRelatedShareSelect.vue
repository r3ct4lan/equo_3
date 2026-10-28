<script setup lang="ts">
import type { TransferView } from '~/types/finance'
import { formatEuro } from '~/utils/money'

const props = defineProps<{ shares: TransferView[] }>()
const emit = defineEmits<{ selected: [share: TransferView] }>()
const model = defineModel<string>({ default: '' })
const missingSelected = computed(() => !!model.value && !props.shares.some(item => item.id === model.value))
const options = computed(() => [
  { value: '', label: 'No related expense' },
  ...(missingSelected.value ? [{ value: model.value, label: 'Previously linked expense share' }] : []),
  ...props.shares.map(share => ({ value: share.id, label: `${share.debtReference?.isAccessible ? share.debtReference.title : 'Expense unavailable'} · ${formatEuro(share.amount)}` }))
])
function onSelection(value: string): void {
  const selected = props.shares.find(share => share.id === value)
  if (selected) emit('selected', selected)
}
</script>

<template>
  <UiSelect
    id="related-share"
    v-model="model"
    label="Related expense share (optional)"
    :options="options"
    aria-describedby="related-share-help"
    @change="onSelection"
  />
</template>
