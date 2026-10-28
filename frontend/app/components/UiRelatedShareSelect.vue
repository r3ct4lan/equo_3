<script setup lang="ts">
import type { TransferView } from '~/types/finance'
import { formatEuro } from '~/utils/money'

const props = defineProps<{ shares: TransferView[] }>()
const emit = defineEmits<{ selected: [share: TransferView] }>()
const model = defineModel<string>({ default: '' })
const missingSelected = computed(() => !!model.value && !props.shares.some(item => item.id === model.value))
function onSelection(event: Event): void {
  const selected = props.shares.find(share => share.id === (event.target as HTMLSelectElement).value)
  if (selected) emit('selected', selected)
}
</script>

<template>
  <UiSelect
    id="related-share"
    v-model="model"
    aria-describedby="related-share-help"
    @change="onSelection"
  >
    <option value="">
      No related expense
    </option>
    <option
      v-if="missingSelected"
      :value="model"
    >
      Previously linked expense share
    </option>
    <option
      v-for="share in shares"
      :key="share.id"
      :value="share.id"
    >
      {{ share.debtReference?.isAccessible ? share.debtReference.title : 'Expense unavailable' }} · {{ formatEuro(share.amount) }}
    </option>
  </UiSelect>
</template>
