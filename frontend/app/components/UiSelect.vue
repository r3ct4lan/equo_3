<script setup lang="ts">
import type { FocusTarget, SelectOption } from '~/types/ui'

defineProps<{ label: string, options: SelectOption[], selectedLabel?: string }>()
const model = defineModel<string>({ default: '' })
const emit = defineEmits<{ change: [value: string] }>()
const select = useTemplateRef<FocusTarget>('select')
function update(option: SelectOption | SelectOption[] | null): void {
  const value = option && !Array.isArray(option) ? option.value : ''
  model.value = value
  emit('change', value)
}
defineExpose({ focus: () => select.value?.focus() })
</script>

<template>
  <UiMultiselect
    ref="select"
    :label="label"
    :options="options"
    :selected-label="selectedLabel"
    :model-value="options.find(option => option.value === model) ?? null"
    @update:model-value="update"
  />
</template>
