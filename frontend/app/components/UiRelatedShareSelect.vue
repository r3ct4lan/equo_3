<script setup lang="ts">
import type { TransferView } from '~/types/finance'
import { formatMoney } from '~/utils/money'

const { locale } = useLocale()

const props = defineProps<{ shares: TransferView[] }>()
const emit = defineEmits<{ selected: [share: TransferView] }>()
const model = defineModel<string>({ default: '' })
const missingSelected = computed(() => !!model.value && !props.shares.some(item => item.id === model.value))
const options = computed(() => [
  { value: '', label: t('No related expense') },
  ...(missingSelected.value ? [{ value: model.value, label: t('Previously linked expense share') }] : []),
  ...props.shares.map(share => ({ value: share.id, label: `${share.debtReference?.isAccessible ? share.debtReference.title : t('Expense unavailable')} · ${formatMoney(share.amount, share.currency, locale.value)}` }))
])
function onSelection(value: string): void {
  const selected = props.shares.find(share => share.id === value)
  if (selected) emit('selected', selected)
}

const { t } = useTranslation()
</script>

<template>
  <UiSelect
    id="related-share"
    v-model="model"
    :label="t('Related expense share (optional)')"
    :options="options"
    aria-describedby="related-share-help"
    @change="onSelection"
  />
</template>
