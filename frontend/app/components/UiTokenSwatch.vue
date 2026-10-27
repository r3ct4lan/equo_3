<script setup lang="ts">
defineProps<{ name: string, value: string }>()
</script>

<template>
  <div class="ui-kit-swatch">
    <span
      class="ui-kit-swatch__color"
      :style="{ backgroundColor: `var(${name})` }"
      aria-hidden="true"
    />
    <code>{{ name }}</code>
    <UiText variant="caption">
      {{ value }}
    </UiText>
  </div>
</template>
