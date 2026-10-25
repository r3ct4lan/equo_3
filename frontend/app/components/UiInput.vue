<script setup lang="ts">
import type { InputType } from '~/types/ui'
import { Input as RegistryInput } from '~/components/ui/input'

withDefaults(defineProps<{ type?: InputType }>(), { type: 'text' })
const model = defineModel<string>({ default: '' })
const input = useTemplateRef<{ $el: HTMLInputElement }>('input')
defineExpose({ focus: () => input.value?.$el.focus() })
</script>

<template>
  <RegistryInput
    ref="input"
    :model-value="model"
    class="ui-control"
    :type="type"
    @update:model-value="model = String($event)"
  />
</template>
