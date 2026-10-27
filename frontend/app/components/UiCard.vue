<script setup lang="ts">
import type { CardTag } from '~/types/ui'
import { Card as RegistryCard } from '~/components/ui/card'

withDefaults(defineProps<{ as?: CardTag }>(), { as: 'section' })
const root = useTemplateRef<{ $el: HTMLElement }>('root')
defineExpose({ focus: () => root.value?.$el.focus() })
</script>

<template>
  <RegistryCard
    ref="root"
    :as="as"
    class="panel gap-0 px-6"
  >
    <slot />
  </RegistryCard>
</template>
