<script setup lang="ts">
import type { BriefUser } from '~/types/finance'
import type { Assignments } from '~/types/receipt'

const props = defineProps<{ people: BriefUser[], quantity: string, label: string, review?: boolean }>()
const assignments = defineModel<Assignments>({ required: true })
const emit = defineEmits<{ confirm: [] }>()
const { t } = useTranslation()
const id = useId()
const byQuantity = computed(() => Object.values(assignments.value).some(v => v !== null))
const selected = computed({
  get: () => props.people.filter(person => Object.hasOwn(assignments.value, person.id)),
  set: (people: BriefUser[]) => {
    assignments.value = Object.fromEntries(people.map(person => [person.id, byQuantity.value ? (assignments.value[person.id] ?? '') : null]))
    emit('confirm')
  }
})
function toggle(enabled: boolean) {
  assignments.value = Object.fromEntries(selected.value.map(person => [person.id, enabled ? '' : null]))
  emit('confirm')
}
</script>

<template>
  <div
    class="stack"
    data-testid="receipt-assignment"
  >
    <DebtPeoplePicker
      v-model="selected"
      :label="label"
      :choices="people"
    />
    <label
      v-if="selected.length && (quantity !== '1' || byQuantity)"
      class="checkbox-label"
    >
      <UiCheckbox
        :model-value="byQuantity"
        @update:model-value="toggle(Boolean($event))"
      /> {{ t('Assign by quantity') }}
    </label>
    <template v-if="byQuantity">
      <UiField
        v-for="person in selected"
        :key="person.id"
      >
        <label :for="id + person.id">{{ t('Quantity') }} · {{ person.name }}</label>
        <UiInput
          :id="id + person.id"
          :model-value="assignments[person.id] ?? ''"
          inputmode="decimal"
          @update:model-value="assignments = { ...assignments, [person.id]: $event }; emit('confirm')"
        />
      </UiField>
    </template>
    <UiButton
      v-if="review && selected.length"
      variant="secondary"
      @click="emit('confirm')"
    >
      {{ t('Confirm remaining recipients') }}
    </UiButton>
  </div>
</template>
