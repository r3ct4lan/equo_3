<script setup lang="ts">
defineProps<{ label: string, hint: string, accept: string, invalid: boolean }>()
const emit = defineEmits<{ select: [files: File[]] }>()
const input = useTemplateRef<{ chooseFile: () => void }>('input')
const dragging = ref(false)
function select(event: Event) {
  const field = event.target as HTMLInputElement
  const files = Array.from(field.files ?? [])
  field.value = ''
  emit('select', files)
}
function drop(event: DragEvent) {
  dragging.value = false
  emit('select', Array.from(event.dataTransfer?.files ?? []))
}
defineExpose({ chooseFile: () => input.value?.chooseFile() })
</script>

<template>
  <label
    class="relative grid cursor-pointer gap-1 rounded-lg border-2 border-dashed p-4 focus-within:outline"
    :class="{ 'border-primary': dragging, 'border-destructive': invalid }"
    @dragover.prevent="dragging = true"
    @dragleave="dragging = false"
    @drop.prevent="drop"
  >
    <strong>{{ label }}</strong>
    <span>{{ hint }}</span>
    <UiInput
      ref="input"
      type="file"
      class="absolute inset-0 h-full w-full cursor-pointer opacity-0"
      :aria-label="label"
      :aria-invalid="invalid"
      :accept="accept"
      multiple
      @change="select"
    />
  </label>
</template>
