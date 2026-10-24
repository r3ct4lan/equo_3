<script setup lang="ts">
import { formatEuro } from '~/utils/money'

defineProps<{ amount: number }>()
</script>

<template>
  <div class="connect-balance">
    <p
      class="connect-balance__amount"
      :class="{ 'connect-balance__amount--positive': amount > 0, 'connect-balance__amount--negative': amount < 0 }"
    >
      Balance: <strong>{{ formatEuro(amount) }}</strong>
    </p>
    <p v-if="amount > 0">
      They owe you.
    </p>
    <p v-else-if="amount < 0">
      You owe them.
    </p>
    <p v-else>
      You are settled up.
    </p>
  </div>
</template>

<style scoped>
.connect-balance p {
  margin-block: 0;
}

.connect-balance {
  display: grid;
  gap: 0.25rem;
}

.connect-balance__amount--positive {
  color: var(--color-success);
}

.connect-balance__amount--negative {
  color: var(--color-error);
}
</style>
