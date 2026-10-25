<script setup>
import { Link } from '@inertiajs/vue3'
import { useTranslations } from '@/lib/useTranslations'

// Single and recurring invoices as two tabs over their lists.
defineProps({
  current: { type: String, required: true }, // 'invoices' | 'recurring'
})

const { t } = useTranslations()

const tabs = [
  { key: 'invoices', href: '/invoices', label: 'invoices' },
  { key: 'recurring', href: '/invoices/recurring', label: 'recurring_invoices' },
]
</script>

<template>
  <nav class="mb-6 flex gap-1 overflow-x-auto border-b border-[hsl(var(--border))]">
    <Link
      v-for="tab in tabs"
      :key="tab.key"
      :href="tab.href"
      :aria-current="current === tab.key ? 'page' : undefined"
      class="-mb-px whitespace-nowrap border-b-2 px-4 py-2 text-sm"
      :class="current === tab.key
        ? 'border-[hsl(var(--primary))] font-semibold'
        : 'border-transparent text-[hsl(var(--muted-foreground))] hover:text-[hsl(var(--foreground))]'"
    >
      {{ t(tab.label) }}
    </Link>
  </nav>
</template>
