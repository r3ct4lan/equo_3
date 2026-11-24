<script setup lang="ts">
const props = defineProps<{ label: string }>()
const id = useId()
const currency = defineModel<string>({ required: true })
const { $auth } = useNuxtApp()
const { t } = useTranslation()
const { state } = useCurrentUser()
const codes = ref<string[]>([])
const pending = ref(false)
const error = ref(false)
const options = computed(() => (codes.value.length ? codes.value : [currency.value]).map(value => ({ label: value, value })))
let generation = 0
watch(() => [state.value.user?.id, state.value.user?.currency] as const, () => {
  ++generation
  codes.value = []
  error.value = false
  pending.value = false
}, { flush: 'sync' })

async function load(): Promise<void> {
  if (pending.value || codes.value.length) return
  const requestGeneration = generation
  pending.value = true
  error.value = false
  try {
    const ordered = await $auth.protectedRequest<string[]>('/v1/currencies/options')
    if (requestGeneration === generation) codes.value = ordered
  }
  catch {
    if (requestGeneration === generation) error.value = true
  }
  finally {
    if (requestGeneration === generation) pending.value = false
  }
}
</script>

<template>
  <UiField
    @focusin="load"
    @pointerdown="load"
  >
    <label :for="id">{{ label }}</label>
    <UiSelect
      :id="id"
      v-model="currency"
      :label="props.label"
      :options="options"
    />
    <UiText
      v-if="error"
      role="alert"
    >
      {{ t('Could not load currencies.') }}
    </UiText>
  </UiField>
</template>
