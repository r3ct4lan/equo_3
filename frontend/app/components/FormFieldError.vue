<script setup lang="ts">
defineProps<{
  id: string
  messages?: readonly string[]
}>()
</script>

<template>
  <ul
    v-if="messages?.length"
    :id="id"
    class="field-errors"
    role="alert"
    aria-live="polite"
  >
    <li v-for="message in messages" :key="message">
      {{ message }}
    </li>
  </ul>
</template>
