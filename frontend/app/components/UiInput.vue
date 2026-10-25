<script setup lang="ts">
import type { InputType } from '~/types/ui'

withDefaults(defineProps<{ type?: InputType }>(), { type: 'text' })
const model = defineModel<string>({ default: '' })
const input = useTemplateRef<HTMLInputElement>('input')
defineExpose({ focus: () => input.value?.focus() })
</script>

<template>
  <input
    ref="input"
    v-model="model"
    class="ui-control"
    :type="type"
  >
</template>
