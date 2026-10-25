<script setup lang="ts">
const model = defineModel<string>({ default: '' })
const input = useTemplateRef<HTMLTextAreaElement>('input')
defineExpose({ focus: () => input.value?.focus() })
</script>

<template>
  <textarea
    ref="input"
    v-model="model"
    class="ui-control"
  />
</template>
