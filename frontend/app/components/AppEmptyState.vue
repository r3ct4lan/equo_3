<script setup lang="ts">
defineProps<{
  title: string
  message: string
}>()
</script>

<template>
  <UiNotice tone="neutral">
    <div>
      <strong>{{ title }}</strong>
      <UiText>{{ message }}</UiText>
    </div>
  </UiNotice>
</template>
