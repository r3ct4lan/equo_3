<script setup lang="ts">
import { NativeSelect as RegistrySelect } from '~/components/ui/native-select'

const model = defineModel<string>({ default: '' })
const select = useTemplateRef<{ $el: HTMLElement }>('select')
defineExpose({ focus: () => select.value?.$el.querySelector('select')?.focus() })
</script>

<template>
  <RegistrySelect
    ref="select"
    :model-value="model"
    class="w-full"
    @update:model-value="model = String($event)"
  >
    <slot />
  </RegistrySelect>
</template>
