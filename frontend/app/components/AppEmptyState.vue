<script setup lang="ts">
defineProps<{
  title: string
  message: string
}>()
const { t } = useTranslation()
</script>

<template>
  <UiNotice tone="neutral">
    <div>
      <strong>{{ t(title) }}</strong>
      <UiText>{{ t(message) }}</UiText>
    </div>
  </UiNotice>
</template>
