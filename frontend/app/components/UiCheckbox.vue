<script setup lang="ts" generic="T extends boolean | string[]">
defineProps<{ value?: string }>()
const model = defineModel<T>({ required: true })
</script>

<template>
  <input
    v-model="model"
    class="ui-checkbox"
    type="checkbox"
    :value="value"
  >
</template>
