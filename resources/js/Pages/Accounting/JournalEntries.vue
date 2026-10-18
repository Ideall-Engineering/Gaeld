<script setup>
import { ref, computed, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/AppLayout.vue'
import Card from '@/Components/UI/Card.vue'
import CardHeader from '@/Components/UI/CardHeader.vue'
import CardTitle from '@/Components/UI/CardTitle.vue'
import CardContent from '@/Components/UI/CardContent.vue'
import Badge from '@/Components/UI/Badge.vue'
import DataTable from '@/Components/UI/DataTable.vue'
import ExportDropdown from '@/Components/UI/ExportDropdown.vue'
import Tooltip from '@/Components/UI/Tooltip.vue'
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue'
import Button from '@/Components/UI/Button.vue'
import HelpText from '@/Components/HelpText.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'
import JournalCorrectionDialog from '@/Components/Accounting/JournalCorrectionDialog.vue'
import JournalAccountCell from '@/Components/Accounting/JournalAccountCell.vue'
import JournalEntryFormModal from '@/Components/Accounting/JournalEntryFormModal.vue'
import { useFormatters } from '@/lib/useFormatters'
import { useTranslations } from '@/lib/useTranslations'
import AllocationDialog from '@/Components/Projects/AllocationDialog.vue'
import FormSelect from '@/Components/UI/FormSelect.vue'
import { BookText, Plus, Check, RotateCcw, Replace, Trash2, Pencil, HelpCircle, Lock, History, FolderKanban, FileText } from 'lucide-vue-next'

const props = defineProps({
  entries: Object,
  accounts: { type: Array, default: () => [] },
  vatRates: { type: Array, default: () => [] },
  vatTypes: { type: Array, default: () => [] },
  postedEntryEditMode: { type: String, default: 'strict' },
  allocationOptions: { type: Object, default: null },
  recentChangesCount: { type: Number, default: 0 },
  query: {
    type: Object,
    default: () => ({ sort: 'date', direction: 'desc', search: '', filter: {} }),
  },
  can: { type: Object, default: () => ({ create: false, edit: false, delete: false, amend: false }) },
})

const isFlexible = computed(() => props.postedEntryEditMode === 'flexible')

const { t } = useTranslations()
const { formatCurrency, formatDate } = useFormatters()

// Server-side query state -----------------------------------------------

function applyQuery(params) {
  router.get('/accounting/journal-entries', {
    ...props.query,
    ...params,
    page: 1,
  }, { preserveState: true, replace: true })
}

function handleSort({ sort, direction }) {
  applyQuery({ sort, direction })
}

function handleSearch(search) {
  applyQuery({ search })
}

function handleFilter({ key, value }) {
  applyQuery({ filter: { ...props.query.filter, [key]: value } })
}

const filters = computed(() => [
  {
    key: 'is_posted',
    label: t('status'),
    value: props.query.filter?.is_posted ?? '',
    options: [
      { value: '1', label: t('posted') },
      { value: '0', label: t('draft') },
    ],
  },
  {
    key: 'account_id',
    label: t('account'),
    value: props.query.filter?.account_id ?? '',
    options: props.accounts.map(a => ({ value: String(a.id), label: `${a.code} — ${a.name}` })),
  },
  {
    key: 'corrections',
    label: t('journal_filter_corrections'),
    value: props.query.filter?.corrections ?? '',
    options: [
      { value: 'hide', label: t('journal_filter_hide_corrections') },
    ],
  },
])

// Column presets ---------------------------------------------------------
// Named views in the spirit of Banana: each one is a fixed column set the
// bookkeeper switches between, with the table's own column menu left for
// ad-hoc tweaks on top of the chosen preset.

const VIEW_STORAGE_KEY = 'gaeld.journal-entries.view'

const VIEW_COLUMNS = {
  basic: ['date', 'reference', 'description', 'debit_account', 'credit_account', 'amount', 'is_posted', 'actions'],
  // The accounts view spends the width saved on reference, status and cost
  // centre to write both account names out in full instead of abbreviating
  // them to their number.
  accounts: ['date', 'description', 'debit_account', 'credit_account', 'amount', 'actions'],
  complete: ['date', 'reference', 'description', 'line_description', 'debit_account', 'credit_account', 'amount', 'vat_code', 'vat_amount', 'cost_center', 'is_posted', 'actions'],
  vat: ['date', 'reference', 'description', 'debit_account', 'credit_account', 'amount', 'vat_code', 'vat_amount', 'is_posted', 'actions'],
  cost_centers: ['date', 'reference', 'description', 'debit_account', 'credit_account', 'amount', 'cost_center', 'is_posted', 'actions'],
}

const views = computed(() => [
  { value: 'basic', label: t('journal_view_basic') },
  { value: 'accounts', label: t('journal_view_accounts') },
  { value: 'complete', label: t('journal_view_complete') },
  { value: 'vat', label: t('journal_view_vat') },
  { value: 'cost_centers', label: t('journal_view_cost_centers') },
])

function storedView() {
  try {
    const stored = localStorage.getItem(VIEW_STORAGE_KEY)
    if (stored && VIEW_COLUMNS[stored]) return stored
  } catch {
    // Private windows and blocked site data both throw; fall back to the default.
  }
  return 'basic'
}

const activeView = ref(storedView())

watch(activeView, view => {
  try {
    localStorage.setItem(VIEW_STORAGE_KEY, view)
  } catch {
    // Remembering the view is a convenience, never a requirement.
  }
})

// Spelled-out account names need room; the numbers alone do not.
const showsAccountNames = computed(() => activeView.value === 'accounts')
const accountColumnWidth = computed(() => (showsAccountNames.value ? 220 : 120))

// The reference cell carries the link into the entry. Where a view drops that
// column, the description has to take the link over, or the row cannot be
// opened at all.
const descriptionOpensEntry = computed(() => !VIEW_COLUMNS[activeView.value].includes('reference'))

const columnDefinitions = computed(() => ({
  date: { key: 'date', label: t('date'), format: v => formatDate(v), sortable: true },
  reference: { key: 'reference', label: t('reference'), sortable: true },
  description: { key: 'description', label: t('description'), sortable: true },
  line_description: { key: 'line_description', label: t('journal_line_description') },
  debit_account: { key: 'debit_account', label: t('debit'), minWidth: accountColumnWidth.value },
  credit_account: { key: 'credit_account', label: t('credit'), minWidth: accountColumnWidth.value },
  amount: { key: 'amount', label: t('amount'), class: 'text-right whitespace-nowrap' },
  vat_code: { key: 'vat_code', label: t('vat_code') },
  vat_amount: { key: 'vat_amount', label: t('vat_amount'), class: 'text-right whitespace-nowrap' },
  cost_center: { key: 'cost_center', label: t('cost_center') },
  is_posted: { key: 'is_posted', label: t('status'), sortable: true },
  actions: { key: 'actions', label: '', class: 'text-right' },
}))

const columns = computed(() =>
  VIEW_COLUMNS[activeView.value].map(key => columnDefinitions.value[key])
)

// Form modal state: create, edit a draft, or amend a posted entry in place
const showForm = ref(false)
const editingEntry = ref(null)

function openCreate() {
  editingEntry.value = null
  showForm.value = true
}

function openEdit(entry) {
  editingEntry.value = entry
  showForm.value = true
}

function closeForm() {
  showForm.value = false
}

// Post action
const postingEntry = ref(null)

function confirmPost(entry) {
  postingEntry.value = entry
}

function doPost() {
  router.post(`/accounting/journal-entries/${postingEntry.value.id}/post`, {}, {
    preserveScroll: true,
    onFinish: () => { postingEntry.value = null },
  })
}

// Reverse action
const reversingEntry = ref(null)

function confirmReverse(entry) {
  reversingEntry.value = entry
}

function doReverse() {
  router.post(`/accounting/journal-entries/${reversingEntry.value.id}/reverse`, {}, {
    preserveScroll: true,
    onFinish: () => { reversingEntry.value = null },
  })
}

// Correction action
const correctingEntry = ref(null)

function openCorrect(entry) {
  correctingEntry.value = entry
}

// Projects (spec 009): change the allocation of one entry, whatever its
// state, or allocate the selected entries to one project at once.
const allocatingEntry = ref(null)
const selectedEntryIds = ref([])
const bulkProjectId = ref('')
const bulkOverwrite = ref(false)
const bulkProcessing = ref(false)

const bulkProjectOptions = computed(() => [
  { value: '', label: t('select_placeholder') },
  ...(props.allocationOptions?.projects ?? [])
    .filter(p => p.is_active)
    .map(p => ({ value: String(p.id), label: `${'· '.repeat(p.depth)}${p.code} ${p.name}` })),
])

function allocationCodes(line) {
  return (line.allocations ?? [])
    .map(a => {
      const who = [a.project?.code, a.employee ? `${a.employee.first_name} ${a.employee.last_name}` : null].filter(Boolean).join(' / ')
      return Number(a.share) === 100 ? who : `${who} ${Number(a.share)} %`
    })
    .join(', ')
}

function allocateSelected() {
  if (!bulkProjectId.value || selectedEntryIds.value.length === 0) return
  bulkProcessing.value = true
  router.post('/accounting/journal-entry-allocations/bulk', {
    entry_ids: selectedEntryIds.value,
    project_id: Number(bulkProjectId.value),
    overwrite: bulkOverwrite.value,
  }, {
    preserveScroll: true,
    onFinish: () => { bulkProcessing.value = false },
  })
}

// Delete action
const deletingEntry = ref(null)

function confirmDelete(entry) {
  deletingEntry.value = entry
}

function doDelete() {
  // A posted entry is deleted against the revision it was shown with.
  const data = deletingEntry.value.is_posted ? { revision: deletingEntry.value.revision } : {}
  router.delete(`/accounting/journal-entries/${deletingEntry.value.id}`, {
    data,
    preserveScroll: true,
    onFinish: () => { deletingEntry.value = null },
  })
}
</script>

<template>
  <AppLayout :title="t('journal_entries')" help-page="accounting-basics">
    <HelpText :title="t('help_journal_title')" class="mb-6">
      <p>{{ isFlexible ? t('help_journal_text_flexible') : t('help_journal_text') }}</p>
    </HelpText>

    <div class="mb-4 flex flex-wrap items-center justify-end gap-2">
      <Link
        v-if="recentChangesCount > 0"
        href="/accounting/journal-entries/changes"
        class="mr-auto inline-flex items-center gap-1 text-sm text-[hsl(var(--primary))] hover:underline"
      >
        <History class="h-4 w-4" />
        {{ t('recent_changes_count', { count: recentChangesCount }) }}
      </Link>
      <Button v-if="can.create" size="sm" @click="openCreate">
        <Plus class="mr-1 h-4 w-4" />
        {{ t('new_journal_entry') }}
      </Button>
      <ExportDropdown base-url="/accounting/journal-entries/export" />
    </div>

    <Card>
      <CardHeader class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <CardTitle>{{ t('journal_entries') }}</CardTitle>
        <div class="flex flex-wrap gap-1 rounded-md border border-[hsl(var(--border))] p-1" role="tablist" :aria-label="t('journal_views')">
          <button
            v-for="view in views"
            :key="view.value"
            type="button"
            role="tab"
            :aria-selected="activeView === view.value"
            class="rounded px-3 py-1 text-sm transition-colors"
            :class="activeView === view.value
              ? 'bg-[hsl(var(--primary))] text-[hsl(var(--primary-foreground))]'
              : 'text-[hsl(var(--muted-foreground))] hover:bg-[hsl(var(--muted))]/50'"
            @click="activeView = view.value"
          >
            {{ view.label }}
          </button>
        </div>
      </CardHeader>
      <CardContent>
        <div
          v-if="allocationOptions && selectedEntryIds.length > 0"
          class="mb-3 flex flex-wrap items-end gap-3 rounded-md border border-[hsl(var(--border))] bg-[hsl(var(--muted)/0.4)] p-3"
        >
          <span class="text-sm font-medium">{{ t('allocation_selected_count', { count: selectedEntryIds.length }) }}</span>
          <FormSelect id="bulk_project" v-model="bulkProjectId" :label="t('project')" :options="bulkProjectOptions" class="min-w-[14rem]" />
          <label class="flex items-center gap-2 pb-2 text-sm">
            <input v-model="bulkOverwrite" type="checkbox" class="h-4 w-4 rounded border-[hsl(var(--input))]" />
            {{ t('allocation_bulk_overwrite') }}
          </label>
          <Button size="sm" :disabled="!bulkProjectId || bulkProcessing" :loading="bulkProcessing" @click="allocateSelected">
            <FolderKanban class="mr-1 h-4 w-4" /> {{ t('allocation_bulk_assign') }}
          </Button>
        </div>
        <DataTable
          :columns="columns"
          :rows="entries?.data ?? []"
          :pagination="entries"
          :sort="query.sort"
          :direction="query.direction"
          :search-value="query.search"
          :search-placeholder="t('journal_search_placeholder')"
          :filters="filters"
          searchable
          expandable
          :selectable="!!allocationOptions"
          @selection-change="ids => selectedEntryIds = ids"
          @sort="handleSort"
          @search="handleSearch"
          @filter="handleFilter"
        >
          <template #empty>
            <EmptyState
              :icon="BookText"
              :title="t('empty_journal_entries_title')"
              :description="t('empty_journal_entries_desc')"
              :action-label="can.create ? t('new_journal_entry') : null"
              @action="openCreate"
            />
          </template>
          <template #cell-is_posted="{ value }">
            <Badge :variant="value ? 'success' : 'warning'">{{ value ? t('posted') : t('draft') }}</Badge>
          </template>
          <template #cell-reference="{ value, row }">
            <span class="inline-flex items-center gap-2">
              <Link :href="`/accounting/journal-entries/${row.id}`" class="hover:underline">{{ value || t('journal_entry') }}</Link>
              <Badge v-if="row.is_split" variant="outline">{{ t('journal_split_positions', { count: row.line_count }) }}</Badge>
              <Badge v-if="row.revision > 1" variant="outline">{{ t('journal_entry_revision_badge', { revision: row.revision }) }}</Badge>
              <Badge v-if="row.type === 'historical_summary'" variant="secondary">{{ t('historical_summary_badge') }}</Badge>
              <Badge v-if="row.correction_role === 'original'" variant="warning">{{ t('journal_correction_corrected_badge') }}</Badge>
              <Badge v-else-if="row.correction_role === 'reversal'" variant="secondary">{{ t('journal_correction_reversal_badge') }}</Badge>
              <Badge v-else-if="row.correction_role === 'replacement'" variant="info">{{ t('journal_correction_replacement_badge') }}</Badge>
            </span>
          </template>
          <template #cell-description="{ value, row }">
            <Link v-if="descriptionOpensEntry" :href="`/accounting/journal-entries/${row.id}`" class="hover:underline">
              {{ value || t('journal_entry') }}
            </Link>
            <span v-else>{{ value }}</span>
            <a
              v-if="row.document_url"
              :href="row.document_url"
              target="_blank"
              rel="noopener noreferrer"
              class="ml-1 inline-flex align-middle text-[hsl(var(--muted-foreground))] hover:text-[hsl(var(--primary))]"
              :title="t('journal_entry_document_open')"
              :aria-label="t('journal_entry_document_open')"
              @click.stop
            ><FileText class="h-3.5 w-3.5" /></a>
          </template>
          <template #cell-debit_account="{ value, row }">
            <JournalAccountCell :account="value" :is-split="row.is_split" :show-name="showsAccountNames" />
          </template>
          <template #cell-credit_account="{ value, row }">
            <JournalAccountCell :account="value" :is-split="row.is_split" :show-name="showsAccountNames" />
          </template>
          <template #cell-amount="{ value }">
            {{ formatCurrency(value) }}
          </template>
          <template #cell-vat_amount="{ value }">
            <span v-if="Number(value) !== 0">{{ formatCurrency(value) }}</span>
            <span v-else class="text-[hsl(var(--muted-foreground))]">—</span>
          </template>
          <template #cell-vat_code="{ value }">
            <span v-if="value" class="font-mono">{{ value }}</span>
            <span v-else class="text-[hsl(var(--muted-foreground))]">—</span>
          </template>
          <template #cell-cost_center="{ value }">
            <span v-if="value" class="font-mono">{{ value }}</span>
            <span v-else class="text-[hsl(var(--muted-foreground))]">—</span>
          </template>
          <template #cell-line_description="{ value }">
            <span v-if="value">{{ value }}</span>
            <span v-else class="text-[hsl(var(--muted-foreground))]">—</span>
          </template>
          <template #cell-actions="{ row }">
            <div class="flex justify-end gap-1">
              <Tooltip v-if="allocationOptions" :content="t('allocation_dialog_title')" side="left">
                <Button variant="ghost" size="icon" :aria-label="t('allocation_dialog_title')" @click="allocatingEntry = row">
                  <FolderKanban class="h-4 w-4" />
                </Button>
              </Tooltip>
              <!-- Draft entry actions -->
              <template v-if="!row.is_posted">
                <Tooltip v-if="can.edit && row.correction_role !== 'reversal'" :content="t('edit')" side="left">
                  <Button variant="ghost" size="icon" @click="openEdit(row)">
                    <Pencil class="h-4 w-4" />
                  </Button>
                </Tooltip>
                <Tooltip v-if="can.edit && row.correction_role !== 'reversal'" :content="t('post')" side="left">
                  <Button variant="ghost" size="icon" @click="confirmPost(row)">
                    <Check class="h-4 w-4 text-[hsl(var(--success))]" />
                  </Button>
                </Tooltip>
                <Tooltip v-if="can.delete && !row.correction_role" :content="t('delete')" side="left">
                  <Button variant="ghost" size="icon" @click="confirmDelete(row)">
                    <Trash2 class="h-4 w-4 text-[hsl(var(--destructive))]" />
                  </Button>
                </Tooltip>
              </template>
              <!-- Posted entry in an open period: change it in place -->
              <template v-else-if="row.can_amend">
                <Tooltip :content="t('edit')" side="left">
                  <Button variant="ghost" size="icon" @click="openEdit(row)">
                    <Pencil class="h-4 w-4" />
                  </Button>
                </Tooltip>
                <Tooltip :content="t('delete')" side="left">
                  <Button variant="ghost" size="icon" @click="confirmDelete(row)">
                    <Trash2 class="h-4 w-4 text-[hsl(var(--destructive))]" />
                  </Button>
                </Tooltip>
              </template>
              <!-- Locked posted entry: say why, then offer the correction -->
              <template v-else>
                <Tooltip
                  v-if="isFlexible && row.edit_state && row.edit_state !== 'open'"
                  :content="t('journal_entry_locked_hint', { reason: row.edit_state_label })"
                  side="left"
                >
                  <span class="inline-flex h-9 w-9 items-center justify-center text-[hsl(var(--muted-foreground))]" :aria-label="t('journal_entry_locked_hint', { reason: row.edit_state_label })">
                    <Lock class="h-4 w-4" />
                  </span>
                </Tooltip>
                <!-- A posted replacement is itself correctable again -->
                <template v-if="!row.reversal_of_entry_id && (!row.correction_role || (row.correction_role === 'replacement' && row.correction_status === 'posted'))">
                  <Tooltip v-if="can.edit" :content="t('tooltip_correct_journal_entry')" side="left">
                    <Button variant="ghost" size="icon" @click="openCorrect(row)">
                      <Replace class="h-4 w-4" />
                    </Button>
                  </Tooltip>
                  <Tooltip v-if="can.edit" :content="t('tooltip_reverse_journal_entry')" side="left">
                    <Button variant="ghost" size="icon" @click="confirmReverse(row)">
                      <RotateCcw class="h-4 w-4" />
                    </Button>
                  </Tooltip>
                </template>
              </template>
            </div>
          </template>
          <template #expand-row="{ row }">
            <div v-if="row.lines?.length" class="overflow-x-auto">
              <table class="w-full text-sm">
                <thead>
                  <tr class="border-b text-left text-[hsl(var(--muted-foreground))]">
                    <th class="pb-1">{{ t('account') }}</th>
                    <th class="pb-1 text-right">
                      <span class="inline-flex items-center gap-1">
                        {{ t('debit') }}
                        <Tooltip :content="t('tooltip_journal_balance')" side="top">
                          <HelpCircle class="h-3 w-3 text-[hsl(var(--muted-foreground))]" />
                        </Tooltip>
                      </span>
                    </th>
                    <th class="pb-1 text-right">{{ t('credit') }}</th>
                    <th v-if="allocationOptions" class="pb-1 pl-4">{{ t('project') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="line in row.lines" :key="line.id">
                    <td>{{ line.account?.code }} — {{ line.account?.name }}</td>
                    <td class="text-right">{{ formatCurrency(line.debit) }}</td>
                    <td class="text-right">{{ formatCurrency(line.credit) }}</td>
                    <td v-if="allocationOptions" class="pl-4 font-mono text-xs">{{ allocationCodes(line) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <p v-else class="text-sm text-[hsl(var(--muted-foreground))]">{{ t('no_journal_lines') }}</p>
          </template>
        </DataTable>
      </CardContent>
    </Card>

    <AllocationDialog
      v-if="allocationOptions"
      :open="!!allocatingEntry"
      :entry="allocatingEntry"
      :options="allocationOptions"
      @close="allocatingEntry = null"
    />

    <!-- Create / edit / amend -->
    <JournalEntryFormModal
      :open="showForm"
      :entry="editingEntry"
      :accounts="accounts"
      :vat-rates="vatRates"
      :vat-types="vatTypes"
      :allocation-options="allocationOptions"
      @close="closeForm"
    />

    <!-- Post confirm -->
    <ConfirmDialog
      :open="!!postingEntry"
      :title="t('post')"
      :message="isFlexible ? t('confirm_post_journal_entry_flexible') : t('confirm_post_journal_entry')"
      @confirm="doPost"
      @cancel="postingEntry = null"
    />

    <!-- Reverse confirm -->
    <ConfirmDialog
      :open="!!reversingEntry"
      :title="t('reverse')"
      :message="t('confirm_reverse_journal_entry')"
      @confirm="doReverse"
      @cancel="reversingEntry = null"
    />

    <!-- Delete confirm -->
    <ConfirmDialog
      :open="!!deletingEntry"
      :title="t('delete_journal_entry')"
      :message="deletingEntry?.is_posted ? t('confirm_delete_posted_journal_entry') : t('confirm_delete_journal_entry')"
      variant="destructive"
      @confirm="doDelete"
      @cancel="deletingEntry = null"
    />

    <!-- Guided correction -->
    <JournalCorrectionDialog :entry="correctingEntry" @close="correctingEntry = null" />
  </AppLayout>
</template>
