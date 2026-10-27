<script setup lang="ts">
import { formatEuro } from '~/utils/money'

defineProps<{ amount: number }>()
</script>

<template>
  <div class="connect-balance">
    <UiText class="connect-balance__amount">
      Balance: <strong
        class="connect-balance__value"
        :class="{ 'connect-balance__value--positive': amount > 0, 'connect-balance__value--negative': amount < 0 }"
      >{{ formatEuro(amount) }}</strong>
    </UiText>
    <UiText v-if="amount > 0">
      They owe you.
    </UiText>
    <UiText v-else-if="amount < 0">
      You owe them.
    </UiText>
    <UiText v-else>
      You are settled up.
    </UiText>
  </div>
</template>
