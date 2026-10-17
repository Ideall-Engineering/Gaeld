<script setup>
import AppLayout from '@/Components/AppLayout.vue'
import Card from '@/Components/UI/Card.vue'
import CardHeader from '@/Components/UI/CardHeader.vue'
import CardTitle from '@/Components/UI/CardTitle.vue'
import CardContent from '@/Components/UI/CardContent.vue'
import Button from '@/Components/UI/Button.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'
import Breadcrumb from '@/Components/UI/Breadcrumb.vue'
import { useTranslations } from '@/lib/useTranslations'
import { useFormatters } from '@/lib/useFormatters'
import { Link, router } from '@inertiajs/vue3'
import { ListChecks, FolderKanban, X } from 'lucide-vue-next'

// Entries whose lines were rebuilt and whose old allocations had no clear
// successor line (spec 009). Opening the entry and saving its allocations
// resolves the review; dismissing it says the old allocations no longer apply.
defineProps({
  reviews: Array,
})

const { t } = useTranslations()
const { formatCurrency, formatDate } = useFormatters()

function dismiss(review) {
  router.post(`/projects/allocation-reviews/${review.id}/dismiss`, {}, { preserveScroll: true })
}

function describe(allocation) {
  const who = allocation.project ?? ''
  return Number(allocation.share) === 100 ? who : `${who} ${Number(allocation.share)} %`
}
</script>

<template>
  <AppLayout :title="t('allocation_reviews')">
    <Breadcrumb :items="[{ label: t('projects'), href: '/projects' }, { label: t('allocation_reviews') }]" class="mb-4" />
    <Card>
      <CardHeader><CardTitle>{{ t('allocation_reviews') }}</CardTitle></CardHeader>
      <CardContent>
        <p class="mb-4 text-sm text-[hsl(var(--muted-foreground))]">{{ t('allocation_reviews_desc') }}</p>
        <EmptyState
          v-if="reviews.length === 0"
          :icon="ListChecks"
          :title="t('allocation_reviews_empty_title')"
          :description="t('allocation_reviews_empty_desc')"
        />
        <div v-else class="space-y-3">
          <div v-for="review in reviews" :key="review.id" class="rounded-md border border-[hsl(var(--border))] p-3">
            <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
              <div class="text-sm">
                <span v-if="review.entry" class="font-medium">
                  {{ formatDate(review.entry.date) }} · {{ review.entry.reference || t('journal_entry') }} · {{ review.entry.description }}
                </span>
              </div>
              <div class="flex gap-2">
                <Link v-if="review.entry" :href="`/accounting/journal-entries/${review.entry.id}`">
                  <Button size="sm" variant="outline"><FolderKanban class="mr-1 h-4 w-4" /> {{ t('allocation_review_open') }}</Button>
                </Link>
                <Button size="sm" variant="ghost" @click="dismiss(review)"><X class="mr-1 h-4 w-4" /> {{ t('allocation_review_dismiss') }}</Button>
              </div>
            </div>
            <table class="w-full text-sm">
              <thead>
                <tr class="text-left text-[hsl(var(--muted-foreground))]">
                  <th class="pb-1 font-medium">{{ t('allocation_review_previous_line') }}</th>
                  <th class="pb-1 text-right font-medium">{{ t('amount') }}</th>
                  <th class="pb-1 pl-4 font-medium">{{ t('allocation_review_previous_allocation') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(line, index) in review.previous" :key="index">
                  <td>{{ line.account }}</td>
                  <td class="text-right tabular-nums">{{ formatCurrency(Number(line.debit) > 0 ? line.debit : line.credit) }}</td>
                  <td class="pl-4 font-mono text-xs">{{ line.allocations.map(describe).join(', ') }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </CardContent>
    </Card>
  </AppLayout>
</template>
