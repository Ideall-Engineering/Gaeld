<script setup>
import { computed } from 'vue'
import { useTranslations } from '@/lib/useTranslations'

// Status column beside a document list: every group with its count, each one
// switched on or off on its own. Below 1536px the table needs the full width,
// so the groups become a row of chips above it.
const props = defineProps({
  states: { type: Array, required: true }, // [{ value, label, count }]
  selected: { type: String, default: '' }, // "draft,sent,…"; empty shows all
})

const emit = defineEmits(['change'])
const { t } = useTranslations()

const active = computed(() => new Set(props.selected ? props.selected.split(',') : []))

// Switching off the last group would leave an empty list, so it shows all instead.
function toggle(value) {
  const next = new Set(active.value)
  if (next.has(value)) next.delete(value)
  else next.add(value)
  emit('change', props.states.map(s => s.value).filter(v => next.has(v)).join(','))
}
</script>

<template>
  <nav :aria-label="t('list_state_filter')" class="2xl:w-36 2xl:shrink-0">
    <p class="mb-2 hidden text-xs font-medium uppercase tracking-wide text-[hsl(var(--muted-foreground))] 2xl:block">
      {{ t('status') }}
    </p>
    <ul class="flex flex-wrap gap-2 2xl:flex-col 2xl:gap-0">
      <li v-for="state in states" :key="state.value">
        <button
          type="button"
          :aria-pressed="active.has(state.value)"
          :title="t('list_state_toggle', { name: state.label })"
          class="flex w-full items-center gap-1.5 rounded-full border px-3 py-1 text-sm transition-colors 2xl:rounded-none 2xl:border-0 2xl:border-l-4 2xl:px-2.5 2xl:py-2 2xl:text-left"
          :class="active.has(state.value)
            ? 'border-[hsl(var(--primary))] bg-[hsl(var(--primary)/0.08)] font-semibold 2xl:bg-transparent'
            : 'border-[hsl(var(--border))] text-[hsl(var(--muted-foreground))] hover:text-[hsl(var(--foreground))]'"
          @click="toggle(state.value)"
        >
          <span>{{ state.label }}</span>
          <span class="text-xs font-normal tabular-nums text-[hsl(var(--muted-foreground))]">({{ state.count }})</span>
        </button>
      </li>
    </ul>
  </nav>
</template>
