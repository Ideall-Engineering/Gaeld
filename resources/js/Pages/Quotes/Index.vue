<script setup>
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Components/AppLayout.vue'
import Button from '@/Components/UI/Button.vue'
import Badge from '@/Components/UI/Badge.vue'
import DataTable from '@/Components/UI/DataTable.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'
import { useFormatters } from '@/lib/useFormatters'
import { useTranslations } from '@/lib/useTranslations'
import { Eye, FileSignature, FileText, Pencil, Plus } from 'lucide-vue-next'
import { computed } from 'vue'

// Quotes (offers): listed like invoices, with their validity and answer.
const props = defineProps({
  quotes: Object,
  statuses: { type: Array, default: () => [] },
  query: { type: Object, default: () => ({ sort: 'issue_date', direction: 'desc', search: '', filter: {} }) },
  can: { type: Object, default: () => ({}) },
})

const { t } = useTranslations()
const { formatCurrency, formatDate } = useFormatters()

function applyQuery(params) {
  router.get('/quotes', { ...props.query, ...params, page: 1 }, { preserveState: true, replace: true })
}

const isExpired = row => row.status === 'sent' && row.valid_until && new Date(row.valid_until) < new Date(new Date().toDateString())

const columns = computed(() => [
  { key: 'number', label: t('number'), sortable: true },
  { key: 'customer', label: t('client'), format: v => v?.name ?? '—' },
  { key: 'title', label: t('quote_title') },
  { key: 'issue_date', label: t('date'), format: v => formatDate(v), sortable: true },
  { key: 'valid_until', label: t('quote_valid_until'), sortable: true },
  { key: 'total', label: t('total'), class: 'text-right', format: v => formatCurrency(v), sortable: true },
  { key: 'status', label: t('status'), sortable: true },
  { key: 'actions', label: '', class: 'text-right w-auto' },
])

const statusVariant = { draft: 'secondary', sent: 'default', accepted: 'success', declined: 'outline' }

const filters = computed(() => [{
  key: 'status',
  label: t('all_statuses'),
  value: props.query.filter?.status ?? '',
  options: props.statuses,
}])
</script>

<template>
  <AppLayout :title="t('quotes')">
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
      <p class="text-sm text-[hsl(var(--muted-foreground))]">{{ t('quotes_desc') }}</p>
      <Button v-if="can.create" as="a" href="/quotes/create">
        <Plus class="mr-2 h-4 w-4" />{{ t('quote_new') }}
      </Button>
    </div>

    <DataTable
      :columns="columns"
      :rows="quotes?.data ?? []"
      :pagination="quotes"
      :row-link="row => `/quotes/${row.id}`"
      :sort="query.sort"
      :direction="query.direction"
      searchable
      :search-value="query.search"
      :filters="filters"
      @sort="({ sort, direction }) => applyQuery({ sort, direction })"
      @search="search => applyQuery({ search })"
      @filter="({ key, value }) => applyQuery({ filter: { ...query.filter, [key]: value } })"
    >
      <template #cell-title="{ value }">
        <span class="block max-w-[16rem] truncate" :title="value">{{ value || '—' }}</span>
      </template>
      <template #cell-valid_until="{ value, row }">
        <span class="flex flex-wrap items-center gap-1.5">
          {{ value ? formatDate(value) : '—' }}
          <Badge v-if="isExpired(row)" variant="warning" class="text-xs">{{ t('quote_expired') }}</Badge>
        </span>
      </template>
      <template #cell-status="{ value, row }">
        <span class="flex flex-wrap items-center gap-1.5">
          <Badge :variant="statusVariant[value] ?? 'secondary'">{{ t(`quote_status_${value}`) }}</Badge>
          <span v-if="row.invoice" class="text-xs text-[hsl(var(--muted-foreground))]">→ {{ row.invoice.number }}</span>
        </span>
      </template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end gap-1">
          <Button as="a" :href="`/quotes/${row.id}`" variant="ghost" size="icon" :aria-label="t('view') + ' ' + row.number" :title="t('view')" @click.stop>
            <Eye class="h-4 w-4" />
          </Button>
          <Button as="a" :href="`/quotes/${row.id}/pdf`" target="_blank" variant="ghost" size="icon" :aria-label="t('quote_pdf') + ' ' + row.number" :title="t('quote_pdf')" @click.stop>
            <FileText class="h-4 w-4" />
          </Button>
          <Button v-if="row.status === 'draft'" as="a" :href="`/quotes/${row.id}/edit`" variant="ghost" size="icon" :aria-label="t('edit') + ' ' + row.number" :title="t('edit')" @click.stop>
            <Pencil class="h-4 w-4" />
          </Button>
        </div>
      </template>
      <template #empty>
        <EmptyState :icon="FileSignature" :title="t('quotes_empty')" :description="t('quotes_empty_desc')" :action-label="can.create ? t('quote_new') : null" action-href="/quotes/create" />
      </template>
    </DataTable>
  </AppLayout>
</template>
