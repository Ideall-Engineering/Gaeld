<script setup>
import { computed } from 'vue'
import DropdownMenu from '@/Components/UI/DropdownMenu.vue'
import { useTranslations } from '@/lib/useTranslations'
import { ArrowDown, ArrowUp, Check, ChevronDown, Filter, X } from 'lucide-vue-next'

// A column head that sorts and filters like a Notion table (spec 010):
// click it, pick a sort direction or tick the values to show.
const props = defineProps({
  label: { type: String, required: true },
  column: { type: String, required: true },
  // { key, dir } of the whole list; this column is sorted when key matches.
  sort: { type: Object, default: () => ({ key: '', dir: 'asc' }) },
  sortable: { type: Boolean, default: true },
  // Values to filter by, [{ value, label }]; none means no filter.
  options: { type: Array, default: () => [] },
  selected: { type: Array, default: () => [] },
  align: { type: String, default: 'left' },
})

const emit = defineEmits(['sort', 'update:selected'])
const { t } = useTranslations()

const sorted = computed(() => props.sort.key === props.column)
const filtered = computed(() => props.selected.length > 0)

function toggle(value) {
  emit('update:selected', props.selected.includes(value)
    ? props.selected.filter(v => v !== value)
    : [...props.selected, value])
}
</script>

<template>
  <DropdownMenu :align="align === 'right' ? 'right' : 'left'" class="inline-block">
    <template #trigger="{ toggle: open, open: isOpen }">
      <button
        type="button"
        class="inline-flex items-center gap-1 rounded px-1 py-0.5 font-medium hover:bg-[hsl(var(--muted))]"
        :class="[sorted || filtered ? 'text-[hsl(var(--foreground))]' : '', align === 'right' ? 'flex-row-reverse' : '']"
        aria-haspopup="menu"
        :aria-expanded="isOpen"
        @click.stop="open"
      >
        <span>{{ label }}</span>
        <Filter v-if="filtered" class="h-3 w-3 text-[hsl(var(--primary))]" />
        <component :is="sort.dir === 'desc' ? ArrowDown : ArrowUp" v-if="sorted" class="h-3 w-3 text-[hsl(var(--primary))]" />
        <ChevronDown v-if="!sorted && !filtered" class="h-3 w-3 opacity-50" />
      </button>
    </template>
    <template #default="{ close }">
      <div class="text-left text-sm font-normal normal-case text-[hsl(var(--foreground))]">
        <template v-if="sortable">
          <button type="button" class="flex w-full items-center gap-2 rounded px-2 py-1.5 hover:bg-[hsl(var(--muted))]" @click="emit('sort', 'asc'); close()">
            <ArrowUp class="h-4 w-4" /> {{ t('column_sort_asc') }}
            <Check v-if="sorted && sort.dir === 'asc'" class="ml-auto h-4 w-4" />
          </button>
          <button type="button" class="flex w-full items-center gap-2 rounded px-2 py-1.5 hover:bg-[hsl(var(--muted))]" @click="emit('sort', 'desc'); close()">
            <ArrowDown class="h-4 w-4" /> {{ t('column_sort_desc') }}
            <Check v-if="sorted && sort.dir === 'desc'" class="ml-auto h-4 w-4" />
          </button>
        </template>
        <template v-if="options.length">
          <div v-if="sortable" class="my-1 border-t border-[hsl(var(--border))]" />
          <div class="px-2 py-1 text-xs text-[hsl(var(--muted-foreground))]">{{ t('column_filter_show') }}</div>
          <div class="max-h-72 overflow-y-auto">
            <label v-for="option in options" :key="option.value" class="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 hover:bg-[hsl(var(--muted))]">
              <input type="checkbox" class="h-4 w-4 rounded border-[hsl(var(--input))]" :checked="selected.includes(option.value)" @change="toggle(option.value)" />
              {{ option.label }}
            </label>
          </div>
          <button v-if="filtered" type="button" class="mt-1 flex w-full items-center gap-2 rounded px-2 py-1.5 text-[hsl(var(--muted-foreground))] hover:bg-[hsl(var(--muted))]" @click="emit('update:selected', []); close()">
            <X class="h-4 w-4" /> {{ t('column_filter_clear') }}
          </button>
        </template>
      </div>
    </template>
  </DropdownMenu>
</template>
