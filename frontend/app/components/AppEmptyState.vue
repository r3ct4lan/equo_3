<script setup lang="ts">
defineProps<{
  title: string
  message: string
}>()
</script>

<template>
  <div class="state-message state-message--empty">
    <div>
      <strong>{{ title }}</strong>
      <p>{{ message }}</p>
    </div>
  </div>
</template>
