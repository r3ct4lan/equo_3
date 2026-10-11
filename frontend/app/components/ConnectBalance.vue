<script setup lang="ts">
import { formatEuro } from '~/utils/money'

defineProps<{ amount: number }>()
</script>

<template>
  <div>
    <p>Balance: {{ formatEuro(amount) }}</p>
    <p v-if="amount > 0">
      They owe you {{ formatEuro(amount) }}.
    </p>
    <p v-else-if="amount < 0">
      You owe them {{ formatEuro(-amount) }}.
    </p>
    <p v-else>
      You are settled up.
    </p>
  </div>
</template>
