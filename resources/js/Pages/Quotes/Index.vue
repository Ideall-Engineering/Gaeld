<script setup>
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/AppLayout.vue'
import Button from '@/Components/UI/Button.vue'
import Badge from '@/Components/UI/Badge.vue'
import DataTable from '@/Components/UI/DataTable.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'
import { useFormatters } from '@/lib/useFormatters'
import { useTranslations } from '@/lib/useTranslations'
import { Eye, FileSignature, FileText, Pencil, Plus } from 'lucide-vue-next'
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue'
import StatusMenu from '@/Components/Invoices/StatusMenu.vue'
import InlineDate from '@/Components/Invoices/InlineDate.vue'
import StatusColumn from '@/Components/UI/StatusColumn.vue'
import { usePermissions } from '@/lib/usePermissions'
import { computed, ref } from 'vue'

// Quotes (offers): listed like invoices, with their validity and answer.
const props = defineProps({
  quotes: Object,
  states: { type: Array, default: () => [] },
  query: { type: Object, default: () => ({ sort: 'issue_date', direction: 'desc', search: '', filter: {} }) },
  can: { type: Object, default: () => ({}) },
})

const { t } = useTranslations()
const { formatCurrency, formatDate } = useFormatters()
const { can: hasPermission } = usePermissions()

function applyQuery(params) {
  router.get('/quotes', { ...props.query, ...params, page: 1 }, { preserveState: true, replace: true })
}

const isExpired = row => row.status === 'sent' && row.valid_until && new Date(row.valid_until) < new Date(new Date().toDateString())

// Changes right in the list: dates of a draft, and the next status step.
const isDraftEditable = row => row.status === 'draft' && hasPermission('invoicing.edit')
const totalLink = row => (isDraftEditable(row) ? `/quotes/${row.id}/edit#lines` : `/quotes/${row.id}`)

function setDate(quote, field, value) {
  if (field === 'issue_date' && !value) return
  router.patch(`/quotes/${quote.id}/dates`, { [field]: value || null }, { preserveScroll: true, preserveState: true })
}

// The same steps as on the quote page (QuoteStatus::allowedTransitions).
function statusOptions(row) {
  if (row.invoice || !hasPermission('invoicing.edit')) return []
  const s = row.status
  const options = []
  if (s === 'draft') options.push({ key: 'sent', label: t('quote_mark_sent') })
  if (s === 'draft' || s === 'sent') {
    options.push({ key: 'accepted', label: t('quote_mark_accepted') })
    options.push({ key: 'declined', label: t('quote_mark_declined') })
  }
  if (s === 'sent' || s === 'declined') options.push({ key: 'draft', label: t('quote_back_to_draft') })
  if (s === 'accepted') options.push({ key: 'sent', label: t('quote_undo_decision') })
  if ((s === 'sent' || s === 'accepted') && hasPermission('invoicing.create')) options.push({ key: 'convert', label: t('quote_convert') })
  return options
}

const converting = ref(null)
const busy = ref(false)

function chooseStep(quote, option) {
  if (option.key === 'convert') {
    converting.value = quote
    return
  }
  router.post(`/quotes/${quote.id}/status`, { status: option.key }, { preserveScroll: true })
}

function convert() {
  busy.value = true
  router.post(`/quotes/${converting.value.id}/convert`, {}, {
    onFinish: () => {
      busy.value = false
      converting.value = null
    },
  })
}

const columns = computed(() => [
  { key: 'number', label: t('number'), class: 'whitespace-nowrap', sortable: true },
  { key: 'customer', label: t('client'), format: v => v?.name ?? '—' },
  { key: 'title', label: t('quote_title') },
  { key: 'issue_date', label: t('date'), sortable: true },
  { key: 'valid_until', label: t('quote_valid_until'), sortable: true },
  { key: 'total', label: t('total'), class: 'text-right', sortable: true },
  { key: 'status', label: t('status'), sortable: true },
  { key: 'actions', label: '', class: 'text-right w-auto' },
])

const statusVariant = { draft: 'secondary', sent: 'default', accepted: 'success', declined: 'outline' }

// Documents exist, only the chosen statuses or the search hide them.
const hiddenByStatus = computed(() => props.states.some(s => s.count > 0))
</script>

<template>
  <AppLayout :title="t('quotes')">
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
      <p class="text-sm text-[hsl(var(--muted-foreground))]">{{ t('quotes_desc') }}</p>
      <Button v-if="can.create" as="a" href="/quotes/create">
        <Plus class="mr-2 h-4 w-4" />{{ t('quote_new') }}
      </Button>
    </div>

    <div class="flex flex-col gap-4 2xl:flex-row">
      <StatusColumn
        :states="states"
        :selected="query.filter?.state ?? ''"
        @change="state => applyQuery({ filter: { ...query.filter, state } })"
      />
      <DataTable
        class="min-w-0 flex-1"
        :columns="columns"
        :rows="quotes?.data ?? []"
        :pagination="quotes"
        :row-link="row => `/quotes/${row.id}`"
        :sort="query.sort"
        :direction="query.direction"
        searchable
        :search-value="query.search"
        @sort="({ sort, direction }) => applyQuery({ sort, direction })"
        @search="search => applyQuery({ search })"
      >
        <template #cell-number="{ value, row, mobile }">
          <span v-if="mobile">{{ value }}</span>
          <Link v-else :href="`/quotes/${row.id}`" class="font-medium hover:underline">{{ value }}</Link>
        </template>
        <template #cell-customer="{ value, mobile }">
          <Link v-if="value?.uuid && !mobile" :href="`/contacts/${value.uuid}`" class="hover:underline" :title="t('open_contact')">{{ value.name }}</Link>
          <span v-else>{{ value?.name ?? '—' }}</span>
        </template>
        <template #cell-title="{ value }">
          <span class="block max-w-[16rem] truncate" :title="value">{{ value || '—' }}</span>
        </template>
        <template #cell-issue_date="{ value, row, mobile }">
          <InlineDate :model-value="value" :editable="!mobile && isDraftEditable(row)" :label="t('date')" @change="date => setDate(row, 'issue_date', date)" />
        </template>
        <template #cell-valid_until="{ value, row, mobile }">
          <span class="flex flex-wrap items-center gap-1.5">
            <InlineDate :model-value="value" :editable="!mobile && isDraftEditable(row)" :label="t('quote_valid_until')" @change="date => setDate(row, 'valid_until', date)" />
            <Badge v-if="isExpired(row)" variant="warning" class="text-xs">{{ t('quote_expired') }}</Badge>
          </span>
        </template>
        <template #cell-total="{ value, row, mobile }">
          <span v-if="mobile">{{ formatCurrency(value) }}</span>
          <Link v-else :href="totalLink(row)" class="tabular-nums hover:underline" :title="isDraftEditable(row) ? t('edit_lines') : t('view')">{{ formatCurrency(value) }}</Link>
        </template>
        <template #cell-status="{ value, row, mobile }">
          <span class="flex flex-wrap items-center gap-1.5">
            <Badge v-if="mobile" :variant="statusVariant[value] ?? 'secondary'">{{ t(`quote_status_${value}`) }}</Badge>
            <StatusMenu
              v-else
              :label="t(`quote_status_${value}`)"
              :variant="statusVariant[value] ?? 'secondary'"
              :options="statusOptions(row)"
              :aria-label="t('change_status')"
              @select="option => chooseStep(row, option)"
            />
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
          <EmptyState v-if="hiddenByStatus" :icon="FileText" :title="t('list_state_empty')" :description="t('list_state_empty_desc')" />
          <EmptyState v-else :icon="FileSignature" :title="t('quotes_empty')" :description="t('quotes_empty_desc')" :action-label="can.create ? t('quote_new') : null" action-href="/quotes/create" />
        </template>
      </DataTable>
    </div>

    <ConfirmDialog
      :open="!!converting"
      :title="t('quote_convert')"
      :message="t('quote_convert_confirm', { number: converting?.number })"
      :confirm-label="t('quote_convert')"
      confirm-variant="default"
      :processing="busy"
      @confirm="convert"
      @cancel="converting = null"
    />
  </AppLayout>
</template>
