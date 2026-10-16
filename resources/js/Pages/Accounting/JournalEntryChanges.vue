<script setup>
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/AppLayout.vue'
import Card from '@/Components/UI/Card.vue'
import CardHeader from '@/Components/UI/CardHeader.vue'
import CardTitle from '@/Components/UI/CardTitle.vue'
import CardDescription from '@/Components/UI/CardDescription.vue'
import CardContent from '@/Components/UI/CardContent.vue'
import Badge from '@/Components/UI/Badge.vue'
import Breadcrumb from '@/Components/UI/Breadcrumb.vue'
import { useFormatters } from '@/lib/useFormatters'
import { useTranslations } from '@/lib/useTranslations'

// Posted entries changed or deleted in the last 30 days, so a change to
// figures that may already have been passed on does not go unnoticed.
defineProps({
  revisions: { type: Object, required: true },
})

const { t } = useTranslations()
const { formatCurrency, formatDate } = useFormatters()

function total(lines) {
  return lines.reduce((sum, line) => sum + Number(line.debit || 0), 0)
}
</script>

<template>
  <AppLayout :title="t('recent_changes_title')" help-page="accounting-basics">
    <Breadcrumb
      :items="[{ label: t('journal_entries'), href: '/accounting/journal-entries' }, { label: t('recent_changes_title') }]"
      class="mb-4"
    />

    <Card>
      <CardHeader>
        <CardTitle>{{ t('recent_changes_title') }}</CardTitle>
        <CardDescription>{{ t('recent_changes_description') }}</CardDescription>
      </CardHeader>
      <CardContent>
        <p v-if="!revisions.data.length" class="text-sm text-[hsl(var(--muted-foreground))]">
          {{ t('recent_changes_empty') }}
        </p>

        <div v-else class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="border-b border-[hsl(var(--border))] text-left text-[hsl(var(--muted-foreground))]">
                <th class="pb-2 pr-4 font-medium">{{ t('recent_changes_changed_at') }}</th>
                <th class="pb-2 pr-4 font-medium">{{ t('reference') }}</th>
                <th class="pb-2 pr-4 font-medium">{{ t('description') }}</th>
                <th class="pb-2 pr-4 font-medium">{{ t('status') }}</th>
                <th class="pb-2 pr-4 text-right font-medium">{{ t('amount') }}</th>
                <th class="pb-2 pr-4 font-medium">{{ t('recent_changes_changed_by') }}</th>
                <th class="pb-2 pr-4 font-medium">{{ t('journal_history_reason') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-[hsl(var(--border))]">
              <tr v-for="revision in revisions.data" :key="revision.id">
                <td class="py-2 pr-4 whitespace-nowrap">{{ formatDate(revision.recorded_at) }}</td>
                <td class="py-2 pr-4">
                  <Link
                    v-if="revision.entry_exists"
                    :href="`/accounting/journal-entries/${revision.journal_entry_id}`"
                    class="hover:underline"
                  >
                    {{ revision.reference || t('journal_entry') }}
                  </Link>
                  <span v-else>{{ revision.reference || t('journal_entry') }}</span>
                  <span class="ml-1 text-xs text-[hsl(var(--muted-foreground))]">
                    {{ t('journal_history_version', { revision: revision.revision }) }}
                  </span>
                </td>
                <td class="py-2 pr-4">
                  {{ revision.entry.description || '—' }}
                  <span class="block text-xs text-[hsl(var(--muted-foreground))]">{{ formatDate(revision.entry.date) }}</span>
                </td>
                <td class="py-2 pr-4">
                  <Badge :variant="revision.kind === 'deleted' ? 'destructive' : 'warning'">
                    {{ t('recent_changes_kind_' + revision.kind) }}
                  </Badge>
                </td>
                <td class="py-2 pr-4 text-right font-mono">{{ formatCurrency(total(revision.lines)) }}</td>
                <td class="py-2 pr-4">{{ revision.user || t('journal_history_unknown_user') }}</td>
                <td class="py-2 pr-4">{{ revision.reason || '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="revisions.last_page > 1" class="mt-4 flex justify-between text-sm">
          <Link v-if="revisions.prev_page_url" :href="revisions.prev_page_url" class="text-[hsl(var(--primary))] hover:underline">‹</Link>
          <span>{{ revisions.current_page }} / {{ revisions.last_page }}</span>
          <Link v-if="revisions.next_page_url" :href="revisions.next_page_url" class="text-[hsl(var(--primary))] hover:underline">›</Link>
        </div>
      </CardContent>
    </Card>
  </AppLayout>
</template>
