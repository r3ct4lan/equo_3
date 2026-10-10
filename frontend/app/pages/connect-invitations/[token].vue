<script setup lang="ts">
useHead({ title: 'Invitation · Equo', meta: [{ name: 'referrer', content: 'no-referrer' }] })
const route = useRoute()
const token = computed(() => typeof route.params.token === 'string' ? route.params.token : null)
</script>

<template>
  <InvitationPreview :token="token" />
</template>
