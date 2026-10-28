<script setup lang="ts">
import type { BriefUser, Page } from '~/types/finance'

type Person = BriefUser & { email?: string | null }
const props = withDefaults(defineProps<{ label: string, debtId?: string, excludeIds?: string[], lockedIds?: string[], single?: boolean, choices?: Person[] }>(), {
  debtId: undefined, excludeIds: () => [], lockedIds: () => [], single: false, choices: undefined
})
const selected = defineModel<Person[]>({ required: true })
const { $auth } = useNuxtApp()
const { state } = useCurrentUser()
const open = ref(false)
const query = ref('')
const results = ref<Person[]>([])
const cursor = ref<string | null>(null)
const loading = ref(false)
const failed = ref(false)
const choiceLimit = ref(5)
const panelId = useId()
const searchInput = ref<{ focus: () => void } | null>(null)
const toggleButton = ref<{ focus: () => void } | null>(null)
let controller: AbortController | null = null
let timer: ReturnType<typeof setTimeout> | undefined
let generation = 0
const matchingChoices = computed(() => (props.choices ?? []).filter(person => person.name.toLocaleLowerCase().includes(query.value.trim().toLocaleLowerCase()) || person.email?.toLocaleLowerCase().includes(query.value.trim().toLocaleLowerCase())))
const visible = computed(() => (props.choices ? matchingChoices.value.slice(0, choiceLimit.value) : results.value).filter(person => !props.excludeIds.includes(person.id)))
function cancel(): void {
  ++generation
  controller?.abort()
  clearTimeout(timer)
  loading.value = false
}
async function load(next: string | null = null): Promise<void> {
  if (props.choices || !open.value || state.value.status !== 'authenticated') return
  controller?.abort()
  const current = ++generation
  const viewer = state.value.user?.id
  controller = new AbortController()
  loading.value = true
  failed.value = false
  try {
    const page = await $auth.protectedRequest<Page<Person>>('/v1/debt-people', {
      query: { q: query.value.trim(), ...(props.debtId ? { debtId: props.debtId } : {}), ...(next ? { cursor: next } : {}) }, signal: controller.signal
    })
    if (current !== generation || viewer !== state.value.user?.id) return
    results.value = next ? [...results.value, ...page.items] : page.items
    cursor.value = page.pagination.nextCursor
    const updates = new Map(page.items.map(person => [person.id, person]))
    selected.value = selected.value.map(person => updates.get(person.id) ?? person)
  }
  catch {
    if (current === generation) failed.value = true
  }
  finally {
    if (current === generation) loading.value = false
  }
}
function toggle(person: Person): void {
  if (props.lockedIds.includes(person.id)) return
  if (props.single) selected.value = selected.value.some(item => item.id === person.id) ? [] : [person]
  else if (selected.value.some(item => item.id === person.id)) selected.value = selected.value.filter(item => item.id !== person.id)
  else selected.value = [...selected.value, person]
}
function toggleOpen(): void {
  open.value = !open.value
  if (open.value) {
    void load()
    void nextTick(() => searchInput.value?.focus())
  }
  else cancel()
}
function close(): void {
  if (!open.value) return
  open.value = false
  cancel()
  toggleButton.value?.focus()
}
watch(query, () => {
  cancel()
  results.value = []
  cursor.value = null
  choiceLimit.value = 5
  failed.value = false
  if (!props.choices && open.value) timer = setTimeout(() => {
    void load()
  }, 250)
})
watch(() => [state.value.status, state.value.user?.id, props.debtId], () => {
  cancel()
  results.value = []
  cursor.value = null
  open.value = false
})
onBeforeUnmount(cancel)
</script>

<template>
  <div
    class="people-picker"
    @keydown.esc.prevent="close"
  >
    <div>{{ label }}</div>
    <div
      v-if="selected.length"
      class="people-picker__selected"
    >
      <span
        v-for="person in selected"
        :key="person.id"
        class="people-picker__chip"
      >
        {{ person.id === state.user?.id ? 'You' : person.name }}{{ person.isActive ? '' : ' (inactive)' }}
        <UiButton
          v-if="!lockedIds.includes(person.id)"
          variant="link"
          compact
          :aria-label="`Remove ${person.name}`"
          @click="toggle(person)"
        >×</UiButton>
      </span>
    </div>
    <UiButton
      ref="toggleButton"
      variant="secondary"
      class="people-picker__toggle"
      :aria-expanded="open"
      :aria-controls="panelId"
      :aria-label="`${open ? 'Close' : 'Choose'} people for ${label}`"
      @click="toggleOpen"
    >
      {{ open ? 'Close people' : 'Choose people' }}
    </UiButton>
    <div
      v-if="open"
      :id="panelId"
      class="people-picker__panel"
    >
      <label>Search {{ label.toLowerCase() }}
        <UiInput
          ref="searchInput"
          v-model="query"
          type="search"
          maxlength="100"
          autocomplete="off"
        /></label>
      <div
        role="status"
        aria-live="polite"
      >
        <span v-if="loading">Loading people…</span>
        <span v-else-if="failed">People could not be loaded.</span>
        <span v-else-if="!visible.length">No people found.</span>
      </div>
      <UiButton
        v-if="failed"
        variant="secondary"
        @click="load()"
      >
        Retry
      </UiButton>
      <fieldset>
        <legend class="visually-hidden">
          {{ label }}
        </legend>
        <label
          v-for="person in visible"
          :key="person.id"
          class="people-picker__option"
        >
          <UiCheckbox
            :value="person.id"
            :model-value="selected.some(item => item.id === person.id)"
            :disabled="lockedIds.includes(person.id)"
            @update:model-value="toggle(person)"
          />
          <span>{{ person.id === state.user?.id ? 'You' : person.name }}{{ person.isActive ? '' : ' (inactive)' }}<UiText
            v-if="person.email"
            as="small"
            variant="caption"
          >{{ person.email }}</UiText></span>
        </label>
      </fieldset>
      <UiButton
        v-if="cursor && !loading"
        variant="secondary"
        @click="load(cursor)"
      >
        Load more
      </UiButton>
      <UiButton
        v-if="choices && matchingChoices.length > choiceLimit"
        variant="secondary"
        @click="choiceLimit += 5"
      >
        Load more
      </UiButton>
    </div>
  </div>
</template>
