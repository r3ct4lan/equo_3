<script setup lang="ts">
import type { TransferView } from '~/types/finance'

const props = defineProps<{ connectId: string }>()
const emit = defineEmits<{ selected: [share: TransferView] }>()
const model = defineModel<string>({ default: '' })
const { items, pending, loaded, cursor, error, load } = useFinancialList<TransferView>(
  () => '/v1/connects/' + encodeURIComponent(props.connectId) + '/transfers'
)
const shares = computed(() => items.value.filter(item => item.type === 'DEBT_SHARE' && !item.isDeleted))
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
    <UiRelatedShareSelect
      v-model="model"
      :shares="shares"
      @selected="emit('selected', $event)"
    />
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
