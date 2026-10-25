<script setup lang="ts">
definePageMeta({ middleware: 'auth' })
useHead({ title: 'New expense · Equo' })
</script>

<template>
  <section
    class="stack"
    aria-labelledby="new-debt-title"
  >
    <UiText
      id="new-debt-title"
      variant="heading1"
    >
      New expense
    </UiText>
    <UiText>Record what was paid and share the cost equally.</UiText>
    <CreateDebtForm />
    <UiLink to="/debts">
      Your expenses
    </UiLink>
  </section>
</template>
