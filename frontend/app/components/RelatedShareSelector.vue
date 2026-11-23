<script setup lang="ts">
import type { TransferView } from '~/types/finance'

const props = defineProps<{ connectId: string, senderId: string }>()
const emit = defineEmits<{ selected: [share: TransferView] }>()
const model = defineModel<string>({ default: '' })
const { items, pending, loaded, cursor, error, load } = useFinancialList<TransferView>(
  () => '/v1/connects/' + encodeURIComponent(props.connectId) + '/transfers'
)
const shares = computed(() => items.value.filter(item => item.type === 'DEBT_SHARE' && !item.isDeleted && item.receiver.id === props.senderId))

const { t } = useTranslation()
</script>

<template>
  <UiField>
    <label for="related-share">{{ t('Related expense share (optional)') }}</label>
    <UiText
      id="related-share-help"
      variant="help"
    >
      {{ t('The payment reduces the remaining share. Any overpayment stays in the payment currency.') }}
    </UiText>
    <UiRelatedShareSelect
      v-model="model"
      :shares="shares"
      @selected="emit('selected', $event)"
    />
    <UiText
      v-if="pending"
      variant="help"
    >
      {{ t('Loading expense shares…') }}
    </UiText>
    <UiText
      v-else-if="loaded && !shares.length"
      variant="help"
    >
      {{ t('No available expense shares in the loaded history.') }}
    </UiText>
    <UiText
      v-if="error"
      role="alert"
    >
      {{ t('Expense shares could not be loaded.') }}
    </UiText>
    <UiButton
      v-if="error"
      variant="secondary"
      @click="load(items.length > 0)"
    >
      {{ t('Try again') }}
    </UiButton>
    <UiButton
      v-if="cursor"
      variant="secondary"
      :disabled="pending"
      @click="load(true)"
    >
      {{ t('Load more operations') }}
    </UiButton>
  </UiField>
</template>
