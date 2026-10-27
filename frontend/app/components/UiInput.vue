<script setup lang="ts">
import type { InputType } from '~/types/ui'
import { Input as RegistryInput } from '~/components/ui/input'
import { limitFractionDigits } from '~/utils/money'

const props = withDefaults(defineProps<{ type?: InputType, money?: boolean }>(), { type: 'text', money: false })
const model = defineModel<string>({ default: '' })
const input = useTemplateRef<{ $el: HTMLInputElement }>('input')
defineExpose({ focus: () => input.value?.$el.focus() })

function limitMoneyInput(event: Event): void {
  if (!props.money || (event instanceof InputEvent && event.isComposing)) return
  const element = event.target as HTMLInputElement
  const original = element.value
  const corrected = limitFractionDigits(original)
  if (corrected === original) return
  const start = element.selectionStart ?? original.length
  const end = element.selectionEnd ?? start
  element.value = corrected
  element.setSelectionRange(limitFractionDigits(original.slice(0, start)).length, limitFractionDigits(original.slice(0, end)).length)
}

function preserveMoneyOnInsert(event: InputEvent): void {
  if (!props.money || event.isComposing || event.inputType !== 'insertText' || event.data?.length !== 1) return
  const element = event.target as HTMLInputElement
  const start = element.selectionStart ?? element.value.length
  const end = element.selectionEnd ?? start
  const candidate = element.value.slice(0, start) + event.data + element.value.slice(end)
  if (limitFractionDigits(candidate) !== candidate) event.preventDefault()
}
</script>

<template>
  <RegistryInput
    ref="input"
    :model-value="model"
    class="ui-control"
    :type="type"
    @beforeinput="preserveMoneyOnInsert"
    @input.capture="limitMoneyInput"
    @update:model-value="model = String($event)"
  />
</template>
