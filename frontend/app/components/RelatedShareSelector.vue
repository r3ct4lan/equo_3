<script setup lang="ts">
import type { TransferView } from '~/types/finance'
import { formatEuro } from '~/utils/money'

const props = defineProps<{ connectId: string }>()
const emit = defineEmits<{ selected: [share: TransferView] }>()
const model = defineModel<string>({ default: '' })
const { items, pending, loaded, cursor, error, load } = useFinancialList<TransferView>(
  () => '/v1/connects/' + encodeURIComponent(props.connectId) + '/transfers'
)
const shares = computed(() => items.value.filter(item => item.type === 'DEBT_SHARE' && !item.isDeleted))
const missingSelected = computed(() => !!model.value && !shares.value.some(item => item.id === model.value))
function onSelection(event: Event): void {
  const selected = shares.value.find(share => share.id === (event.target as HTMLSelectElement).value)
  if (selected) emit('selected', selected)
}
</script>

<template>
  <UiField>
    <label for="related-share">Related expense share (optional)</label>
    <UiText
      id="related-share-help"
      variant="help"
    >
      This link is informational. It does not settle an expense or limit the payment amount.
    </UiText>
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
    <UiText
      v-if="pending"
      variant="help"
    >
      Loading expense shares…
    </UiText>
    <UiText
      v-else-if="loaded && !shares.length"
      variant="help"
    >
      No available expense shares in the loaded history.
    </UiText>
    <UiText
      v-if="error"
      role="alert"
    >
      Expense shares could not be loaded.
    </UiText>
    <UiButton
      v-if="error"
      variant="secondary"
      @click="load(items.length > 0)"
    >
      Try again
    </UiButton>
    <UiButton
      v-if="cursor"
      variant="secondary"
      :disabled="pending"
      @click="load(true)"
    >
      Load more operations
    </UiButton>
  </UiField>
</template>
