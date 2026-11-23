<script setup lang="ts">
const props = defineProps<{ label: string }>()
const id = useId()
const currency = defineModel<string>({ required: true })
const { $auth } = useNuxtApp()
const { t } = useTranslation()
const codes = ref<string[]>([])
const pending = ref(false)
const error = ref(false)
const options = computed(() => [...new Set([currency.value, ...codes.value])].map(value => ({ label: value, value })))

async function load(): Promise<void> {
  if (pending.value || codes.value.length) return
  pending.value = true
  error.value = false
  try {
    const catalog = await $auth.protectedRequest<Record<string, number>>('/v1/currencies')
    codes.value = Object.keys(catalog).sort()
  }
  catch {
    error.value = true
  }
  finally {
    pending.value = false
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
