<script setup>
import { useFormatters } from '@/lib/useFormatters'

// A date in a list row: a date field while the document is a draft,
// plain text otherwise. Emits the new date (or '' when cleared).
defineProps({
  modelValue: { type: String, default: '' },
  editable: { type: Boolean, default: false },
  label: { type: String, default: '' },
  invalid: { type: Boolean, default: false },
})

const emit = defineEmits(['change'])
const { formatDate } = useFormatters()
</script>

<template>
  <input
    v-if="editable"
    type="date"
    :value="modelValue ? modelValue.slice(0, 10) : ''"
    :aria-label="label"
    :title="label"
    class="h-8 rounded-md border border-transparent bg-transparent px-1.5 text-sm tabular-nums hover:border-[hsl(var(--input))] focus:border-[hsl(var(--input))] focus:outline-none focus-visible:ring-2 focus-visible:ring-[hsl(var(--ring))]"
    :class="invalid ? 'text-[hsl(var(--destructive))]' : ''"
    @click.stop
    @change="emit('change', $event.target.value)"
  />
  <span v-else :class="invalid ? 'text-[hsl(var(--destructive))]' : ''">{{ modelValue ? formatDate(modelValue) : '—' }}</span>
</template>
