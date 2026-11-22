<script setup lang="ts">
const props = defineProps<{ currency: string, target: string, savedRate?: string | null, inverse?: boolean }>()
const manual = defineModel<string | null>({ default: null })
const emit = defineEmits<{ rate: [value: string | null] }>()
const { $auth } = useNuxtApp()
const { t } = useTranslation()
const automatic = ref<string | null>(null)
const expanded = ref(false)
const pending = ref(false)
const failed = ref(false)
const inputId = useId()
watch(() => [props.currency, props.target, props.savedRate] as const, async ([from, to, saved], _, cleanup) => {
  let current = true
  cleanup(() => {
    current = false
  })
  expanded.value = !!manual.value
  automatic.value = from === to ? '1' : saved ?? null
  failed.value = false
  pending.value = false
  if (from === to || saved) return
  pending.value = true
  try {
    const result = await $auth.protectedRequest<{ rate: string }>(`/v1/currencies/${props.inverse ? to : from}/${props.inverse ? from : to}`)
    if (current) automatic.value = result.rate
  }
  catch {
    if (current) failed.value = true
  }
  finally {
    if (current) pending.value = false
  }
}, { immediate: true })
watchEffect(() => emit('rate', manual.value ?? automatic.value))
</script>

<template>
  <UiField v-if="currency !== target">
    <UiButton
      v-if="!expanded"
      variant="link"
      @click="expanded = true"
    >
      {{ t('Change exchange rate') }}
    </UiButton>
    <template v-else>
      <label :for="inputId">{{ t('Exchange rate') }} · 1 {{ inverse ? target : currency }} = … {{ inverse ? currency : target }}</label>
      <UiInput
        :id="inputId"
        :model-value="manual ?? automatic ?? ''"
        inputmode="decimal"
        maxlength="31"
        :disabled="pending"
        @update:model-value="manual = $event.replace(',', '.') || null"
      />
      <UiText
        v-if="failed"
        role="status"
      >
        {{ t('Automatic rate unavailable. Enter a rate to continue.') }}
      </UiText>
      <UiButton
        variant="link"
        @click="manual = null; expanded = false"
      >
        {{ t('Use automatic rate') }}
      </UiButton>
    </template>
  </UiField>
</template>
