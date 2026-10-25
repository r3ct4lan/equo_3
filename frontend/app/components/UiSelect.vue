<script setup lang="ts">
const model = defineModel<string>({ default: '' })
const select = useTemplateRef<HTMLSelectElement>('select')
defineExpose({ focus: () => select.value?.focus() })
</script>

<template>
  <select
    ref="select"
    v-model="model"
    class="ui-control"
  >
    <slot />
  </select>
</template>
