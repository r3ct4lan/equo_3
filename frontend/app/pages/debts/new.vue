<script setup lang="ts">
definePageMeta({ middleware: 'auth' })
useHead({ title: 'New expense · Equo' })

const { t } = useTranslation()
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
      {{ t('New expense') }}
    </UiText>
    <UiText>{{ t('Record what was paid and share the cost equally.') }}</UiText>
    <CreateDebtForm />
    <UiLink to="/debts">
      {{ t('Your expenses') }}
    </UiLink>
  </section>
</template>
