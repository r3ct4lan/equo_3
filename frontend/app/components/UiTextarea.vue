<script setup lang="ts">
import { Textarea as RegistryTextarea } from '~/components/ui/textarea'

const model = defineModel<string>({ default: '' })
const input = useTemplateRef<{ $el: HTMLTextAreaElement }>('input')
defineExpose({ focus: () => input.value?.$el.focus() })
</script>

<template>
  <RegistryTextarea
    ref="input"
    :model-value="model"
    class="ui-control"
    @update:model-value="model = String($event)"
  />
</template>
