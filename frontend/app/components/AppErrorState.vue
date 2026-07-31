<script setup lang="ts">
withDefaults(defineProps<{
  title?: string
  message: string
  retryLabel?: string
}>(), {
  title: 'Something went wrong',
  retryLabel: undefined
})

defineEmits<{
  retry: []
}>()
</script>

<template>
  <div class="state-message state-message--error" role="alert">
    <div>
      <strong>{{ title }}</strong>
      <p>{{ message }}</p>
    </div>
    <button
      v-if="retryLabel"
      class="button button--secondary"
      type="button"
      @click="$emit('retry')"
    >
      {{ retryLabel }}
    </button>
  </div>
</template>
