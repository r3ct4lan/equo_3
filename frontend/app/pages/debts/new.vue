<script setup lang="ts">
definePageMeta({ middleware: 'auth' })
useHead({ title: 'New expense · Equo' })
</script>

<template>
  <section
    class="stack"
    aria-labelledby="new-debt-title"
  >
    <h1 id="new-debt-title">
      New expense
    </h1>
    <p>Record what was paid and share the cost equally.</p>
    <CreateDebtForm />
    <NuxtLink to="/debts">Your expenses</NuxtLink>
  </section>
</template>
