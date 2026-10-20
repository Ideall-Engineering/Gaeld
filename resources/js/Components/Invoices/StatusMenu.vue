<script setup>
import { nextTick, onMounted, onUnmounted, ref } from 'vue'
import { ChevronDown } from 'lucide-vue-next'
import Badge from '@/Components/UI/Badge.vue'

// Status badge of a list row that opens the next steps of the document.
// The menu is placed on the page (Teleport), so the table's scroll box
// never cuts it off. Without options it stays a plain badge.
const props = defineProps({
  label: { type: String, required: true },
  variant: { type: String, default: 'secondary' },
  // [{ key, label, destructive? }]
  options: { type: Array, default: () => [] },
  ariaLabel: { type: String, default: '' },
})

const emit = defineEmits(['select'])

const open = ref(false)
const trigger = ref(null)
const menu = ref(null)
const position = ref({ top: 0, left: 0 })

async function toggle() {
  if (open.value) {
    open.value = false
    return
  }
  const rect = trigger.value.getBoundingClientRect()
  position.value = { top: rect.bottom + 4, left: rect.left }
  open.value = true
  await nextTick()
  // Keep the menu inside the window on the right edge.
  const width = menu.value?.offsetWidth ?? 0
  if (position.value.left + width > window.innerWidth - 8) {
    position.value = { ...position.value, left: Math.max(8, window.innerWidth - width - 8) }
  }
  menu.value?.querySelector('button')?.focus()
}

function choose(option) {
  open.value = false
  emit('select', option)
}

function onOutside(event) {
  if (!open.value) return
  if (trigger.value?.contains(event.target) || menu.value?.contains(event.target)) return
  open.value = false
}

function close() {
  open.value = false
}

onMounted(() => {
  document.addEventListener('mousedown', onOutside)
  window.addEventListener('scroll', close, true)
  window.addEventListener('resize', close)
})
onUnmounted(() => {
  document.removeEventListener('mousedown', onOutside)
  window.removeEventListener('scroll', close, true)
  window.removeEventListener('resize', close)
})
</script>

<template>
  <span class="inline-flex">
    <Badge v-if="!options.length" :variant="variant">{{ label }}</Badge>
    <button
      v-else
      ref="trigger"
      type="button"
      class="inline-flex items-center rounded-md focus:outline-none focus-visible:ring-2 focus-visible:ring-[hsl(var(--ring))]"
      aria-haspopup="menu"
      :aria-expanded="open"
      :aria-label="ariaLabel || label"
      :title="ariaLabel || label"
      @click.stop.prevent="toggle"
      @keydown.escape="close"
    >
      <Badge :variant="variant" class="cursor-pointer gap-1">
        {{ label }}
        <ChevronDown class="h-3 w-3 opacity-70" />
      </Badge>
    </button>
    <Teleport to="body">
      <div
        v-if="open"
        ref="menu"
        role="menu"
        class="fixed z-50 min-w-[12rem] rounded-lg border border-[hsl(var(--border))] bg-[hsl(var(--popover))] p-1 text-sm text-[hsl(var(--popover-foreground))] shadow-lg"
        :style="{ top: `${position.top}px`, left: `${position.left}px` }"
        @keydown.escape="close"
      >
        <button
          v-for="option in options"
          :key="option.key"
          type="button"
          role="menuitem"
          class="flex w-full items-center rounded-md px-3 py-2 text-left hover:bg-[hsl(var(--muted))] focus:bg-[hsl(var(--muted))] focus:outline-none"
          :class="option.destructive ? 'text-[hsl(var(--destructive))]' : ''"
          @click="choose(option)"
        >
          {{ option.label }}
        </button>
      </div>
    </Teleport>
  </span>
</template>
