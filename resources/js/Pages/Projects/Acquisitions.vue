<script setup>
import AppLayout from '@/Components/AppLayout.vue'
import Card from '@/Components/UI/Card.vue'
import CardHeader from '@/Components/UI/CardHeader.vue'
import CardTitle from '@/Components/UI/CardTitle.vue'
import CardContent from '@/Components/UI/CardContent.vue'
import Badge from '@/Components/UI/Badge.vue'
import Button from '@/Components/UI/Button.vue'
import Breadcrumb from '@/Components/UI/Breadcrumb.vue'
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'
import FormInput from '@/Components/UI/FormInput.vue'
import FormSelect from '@/Components/UI/FormSelect.vue'
import FormTextarea from '@/Components/UI/FormTextarea.vue'
import Modal from '@/Components/UI/Modal.vue'
import ColumnMenu from '@/Components/Projects/ColumnMenu.vue'
import { useTranslations } from '@/lib/useTranslations'
import { useFormatters } from '@/lib/useFormatters'
import { Link, router, useForm } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'
import { Download, Package, Pencil, Plus, Search, Trash2, X } from 'lucide-vue-next'

// Material and devices assigned to people (spec 009, stage 4). The net
// amount charges the people; a returned article charges nobody.
const props = defineProps({
  year: Number,
  filters: Object,
  overview: Object,
  employees: Array,
  defaultVatRate: String,
  deadlineDays: Number,
  can: Object,
})

const { t } = useTranslations()
const { formatCurrency, formatDate } = useFormatters()

const statuses = ['ordered', 'delivered', 'returned']
const statusVariant = { ordered: 'info', delivered: 'success', returned: 'secondary' }
const statusOptions = computed(() => statuses.map(s => ({ value: s, label: t('acquisition_status_' + s) })))
const yearOptions = computed(() => {
  const now = new Date().getFullYear()
  return [now + 1, now, now - 1, now - 2].map(y => ({ value: String(y), label: String(y) }))
})
const employeeOptions = computed(() => [{ value: '', label: t('select_placeholder') }, ...props.employees.map(e => ({ value: String(e.id), label: e.name }))])

function changeYear(year) {
  router.get('/projects/acquisitions', { year }, { preserveScroll: true, preserveState: true })
}

// Notion-like (spec 010): each column head sorts and filters the list in
// the browser; active filters show as chips. Remembered per browser only.
const FILTER_KEY = 'gaeld.acquisitions.filter'
function stored() {
  try {
    return JSON.parse(localStorage.getItem(FILTER_KEY) || '{}')
  } catch {
    return {}
  }
}
const saved = stored()
const search = ref('')
const statusFilter = ref(props.filters?.status ? [props.filters.status] : (Array.isArray(saved.statuses) ? saved.statuses : []))
const personFilter = ref(props.filters?.employee_id ? [props.filters.employee_id] : (Array.isArray(saved.people) ? saved.people : []))
const categoryFilter = ref(Array.isArray(saved.categories) ? saved.categories : [])
const defaultSort = { key: 'date', dir: 'asc' }
const sort = ref(saved.sort && typeof saved.sort === 'object' ? saved.sort : { ...defaultSort })
watch([statusFilter, personFilter, categoryFilter, sort], () => {
  try {
    localStorage.setItem(FILTER_KEY, JSON.stringify({ statuses: statusFilter.value, people: personFilter.value, categories: categoryFilter.value, sort: sort.value }))
  } catch {
    // Remembering the filter is a convenience only.
  }
}, { deep: true })

const NO_CATEGORY = '__none'
const personOptions = computed(() => props.employees.map(e => ({ value: e.id, label: e.name })))
const categoryOptions = computed(() => {
  const names = [...new Set(props.overview.rows.map(r => r.category).filter(Boolean))].sort((a, b) => a.localeCompare(b))
  const list = names.map(c => ({ value: c, label: c }))
  if (props.overview.rows.some(r => !r.category)) list.push({ value: NO_CATEGORY, label: t('acquisition_no_category') })
  return list
})
const columnLabel = { date: 'date', item: 'acquisition_item', gross: 'acquisition_gross', net: 'acquisition_net', status: 'status', return: 'acquisition_return_until', warranty: 'acquisition_warranty_until' }
const sortValue = {
  date: r => r.purchased_on ?? '',
  item: r => r.item ?? '',
  gross: r => Number(r.gross_amount || 0),
  net: r => Number(r.net_amount || 0),
  status: r => statuses.indexOf(r.status),
  // Empty dates go last either way.
  return: r => r.return_until ?? '9999',
  warranty: r => r.warranty_until ?? '9999',
}
function compare(a, b) {
  const get = sortValue[sort.value.key] ?? sortValue.date
  const [x, y] = [get(a), get(b)]
  const order = typeof x === 'string' ? x.localeCompare(y, undefined, { numeric: true }) : x - y
  return sort.value.dir === 'desc' ? -order : order
}
const rows = computed(() => {
  const q = search.value.trim().toLowerCase()
  return props.overview.rows
    .filter(r => !statusFilter.value.length || statusFilter.value.includes(r.status))
    .filter(r => !personFilter.value.length || r.people.some(p => personFilter.value.includes(p.employee_id)))
    .filter(r => !categoryFilter.value.length || categoryFilter.value.includes(r.category || NO_CATEGORY))
    .filter(r => !q || [r.item, r.category, r.supplier, r.journal_reference, ...r.people.map(p => p.name)].some(v => (v ?? '').toLowerCase().includes(q)))
    .sort(compare)
})
const visibleTotals = computed(() => rows.value.filter(r => r.status !== 'returned').reduce((s, r) => ({
  gross: s.gross + Number(r.gross_amount || 0),
  net: s.net + Number(r.net_amount || 0),
}), { gross: 0, net: 0 }))
const hasFilter = computed(() => statusFilter.value.length > 0 || personFilter.value.length > 0 || categoryFilter.value.length > 0 || search.value !== '')
const sorted = computed(() => sort.value.key !== defaultSort.key || sort.value.dir !== defaultSort.dir)
function clearFilters() {
  statusFilter.value = []
  personFilter.value = []
  categoryFilter.value = []
  search.value = ''
  sort.value = { ...defaultSort }
}
const labelOf = (options, value) => options.find(o => o.value === value)?.label ?? value

const showForm = ref(false)
const editing = ref(null)
const blank = () => ({
  item: '', category: '', supplier: '', purchased_on: new Date().toISOString().slice(0, 10),
  quantity: '1', unit_price: '', vat_rate: props.defaultVatRate, net_amount: '', status: 'delivered',
  return_until: '', warranty_until: '', journal_entry_id: '', notes: '',
  people: [{ employee_id: '', share: '100' }],
})
const form = useForm(blank())

const gross = computed(() => (parseFloat(form.quantity) || 0) * (parseFloat(form.unit_price) || 0))
const suggestedNet = computed(() => {
  const rate = parseFloat(form.vat_rate)
  return rate > 0 ? gross.value * 100 / (100 + rate) : gross.value
})
const shareTotal = computed(() => form.people.reduce((sum, p) => sum + (parseFloat(p.share) || 0), 0))

function openCreate() {
  editing.value = null
  Object.assign(form, blank())
  form.clearErrors()
  showForm.value = true
}

function openEdit(row) {
  editing.value = row
  Object.assign(form, {
    item: row.item, category: row.category ?? '', supplier: row.supplier ?? '', purchased_on: row.purchased_on,
    quantity: String(row.quantity), unit_price: String(row.unit_price), vat_rate: row.vat_rate ?? '', net_amount: String(row.net_amount),
    status: row.status, return_until: row.return_until ?? '', warranty_until: row.warranty_until ?? '',
    journal_entry_id: row.journal_entry_id ?? '', notes: row.notes ?? '',
    people: row.people.map(p => ({ employee_id: p.employee_id, share: String(Number(p.share)) })),
  })
  form.clearErrors()
  showForm.value = true
}

// "Both" in the spreadsheet: everyone listed gets an equal share.
function splitEqually() {
  const n = form.people.length
  if (n === 0) return
  const base = Math.floor((100 / n) * 100) / 100
  form.people.forEach((p, i) => { p.share = i === n - 1 ? String(+(100 - base * (n - 1)).toFixed(2)) : String(base) })
}

function addPerson() {
  form.people.push({ employee_id: '', share: '' })
  splitEqually()
}

function removePerson(index) {
  form.people.splice(index, 1)
  splitEqually()
}

function submit() {
  const options = { preserveScroll: true, onSuccess: () => { showForm.value = false } }
  if (editing.value) form.put(`/projects/acquisitions/${editing.value.id}`, options)
  else form.post('/projects/acquisitions', options)
}

const deleting = ref(null)
function performDelete() {
  router.delete(`/projects/acquisitions/${deleting.value.id}`, { preserveScroll: true, onFinish: () => { deleting.value = null } })
}

const peopleError = computed(() => form.errors.people || Object.entries(form.errors).find(([k]) => k.startsWith('people.'))?.[1])
</script>

<template>
  <AppLayout :title="t('acquisitions')">
    <Breadcrumb :items="[{ label: t('projects'), href: '/projects' }, { label: t('acquisitions') }]" class="mb-4" />
    <Card>
      <CardHeader>
        <div class="flex flex-wrap items-end justify-between gap-3">
          <CardTitle>{{ t('acquisitions') }}</CardTitle>
          <div class="flex flex-wrap items-end gap-2">
            <FormSelect id="year" :model-value="String(year)" :label="t('year')" :options="yearOptions" @update:model-value="changeYear" />
            <a :href="`/projects/acquisitions/export?year=${year}`"><Button size="sm" variant="outline"><Download class="mr-1 h-4 w-4" /> CSV</Button></a>
            <Button v-if="can.manage" size="sm" @click="openCreate"><Plus class="mr-1 h-4 w-4" /> {{ t('add') }}</Button>
          </div>
        </div>
      </CardHeader>
      <CardContent>
        <p class="mb-4 text-sm text-[hsl(var(--muted-foreground))]">{{ t('acquisitions_desc', { days: deadlineDays }) }}</p>

        <div v-if="overview.people.length" class="mb-6 flex flex-wrap gap-3">
          <div v-for="person in overview.people" :key="person.employee_id" class="rounded-md border border-[hsl(var(--border))] px-4 py-2">
            <div class="text-xs text-[hsl(var(--muted-foreground))]">{{ person.name }}</div>
            <div class="text-lg font-semibold tabular-nums">{{ formatCurrency(person.charge) }}</div>
          </div>
          <div class="rounded-md border border-[hsl(var(--border))] bg-[hsl(var(--muted)/0.4)] px-4 py-2">
            <div class="text-xs text-[hsl(var(--muted-foreground))]">{{ t('total') }} {{ t('acquisition_net') }}</div>
            <div class="text-lg font-semibold tabular-nums">{{ formatCurrency(overview.totals.net) }}</div>
          </div>
        </div>

        <div v-if="overview.rows.length" class="mb-3 flex flex-wrap items-center gap-2 text-sm">
          <label class="relative min-w-[14rem] flex-1 sm:max-w-sm">
            <Search class="pointer-events-none absolute left-2.5 top-2.5 h-4 w-4 text-[hsl(var(--muted-foreground))]" />
            <input v-model="search" type="search" :placeholder="t('search')" :aria-label="t('search')" class="h-9 w-full rounded-md border border-[hsl(var(--input))] bg-transparent pl-8 pr-3 text-sm" />
          </label>
          <span v-if="categoryFilter.length" class="inline-flex items-center gap-1 rounded-full bg-[hsl(var(--muted))] px-3 py-1">
            {{ t('acquisition_category') }}: {{ categoryFilter.map(v => labelOf(categoryOptions, v)).join(', ') }}
            <button type="button" :aria-label="t('column_filter_clear')" @click="categoryFilter = []"><X class="h-3 w-3" /></button>
          </span>
          <span v-if="personFilter.length" class="inline-flex items-center gap-1 rounded-full bg-[hsl(var(--muted))] px-3 py-1">
            {{ t('acquisition_people') }}: {{ personFilter.map(v => labelOf(personOptions, v)).join(', ') }}
            <button type="button" :aria-label="t('column_filter_clear')" @click="personFilter = []"><X class="h-3 w-3" /></button>
          </span>
          <span v-if="statusFilter.length" class="inline-flex items-center gap-1 rounded-full bg-[hsl(var(--muted))] px-3 py-1">
            {{ t('status') }}: {{ statusFilter.map(v => labelOf(statusOptions, v)).join(', ') }}
            <button type="button" :aria-label="t('column_filter_clear')" @click="statusFilter = []"><X class="h-3 w-3" /></button>
          </span>
          <span v-if="sorted" class="inline-flex items-center gap-1 rounded-full bg-[hsl(var(--muted))] px-3 py-1">
            {{ t('job_sort') }}: {{ t(columnLabel[sort.key] ?? 'date') }} {{ sort.dir === 'desc' ? '↓' : '↑' }}
            <button type="button" :aria-label="t('column_filter_clear')" @click="sort = { ...defaultSort }"><X class="h-3 w-3" /></button>
          </span>
          <button v-if="hasFilter || sorted" type="button" class="text-[hsl(var(--muted-foreground))] hover:underline" @click="clearFilters">{{ t('column_filter_reset') }}</button>
        </div>

        <EmptyState v-if="overview.rows.length === 0" :icon="Package" :title="t('acquisitions_empty_title')" :description="t('acquisitions_empty_desc')" :action-label="can.manage ? t('create_first') : undefined" @action="openCreate" />
        <div v-else class="overflow-x-auto">
          <table class="w-full text-sm [&_td]:pr-3 [&_th]:pr-3">
            <thead>
              <tr class="border-b text-left text-xs text-[hsl(var(--muted-foreground))]">
                <th class="pb-2 font-medium"><ColumnMenu :label="t('date')" column="date" :sort="sort" @sort="dir => sort = { key: 'date', dir }" /></th>
                <th class="pb-2 font-medium"><ColumnMenu v-model:selected="categoryFilter" :label="t('acquisition_item')" column="item" :sort="sort" :options="categoryOptions" @sort="dir => sort = { key: 'item', dir }" /></th>
                <th class="pb-2 font-medium"><ColumnMenu v-model:selected="personFilter" :label="t('acquisition_people')" column="people" :sortable="false" :options="personOptions" /></th>
                <th class="pb-2 text-right font-medium"><ColumnMenu :label="t('acquisition_gross')" column="gross" align="right" :sort="sort" @sort="dir => sort = { key: 'gross', dir }" /></th>
                <th class="pb-2 text-right font-medium"><ColumnMenu :label="t('acquisition_net')" column="net" align="right" :sort="sort" @sort="dir => sort = { key: 'net', dir }" /></th>
                <th class="pb-2 font-medium"><ColumnMenu v-model:selected="statusFilter" :label="t('status')" column="status" :sort="sort" :options="statusOptions" @sort="dir => sort = { key: 'status', dir }" /></th>
                <th class="pb-2 font-medium"><ColumnMenu :label="t('acquisition_return_until')" column="return" :sort="sort" @sort="dir => sort = { key: 'return', dir }" /></th>
                <th class="pb-2 font-medium"><ColumnMenu :label="t('acquisition_warranty_until')" column="warranty" :sort="sort" @sort="dir => sort = { key: 'warranty', dir }" /></th>
                <th class="pb-2"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-[hsl(var(--border))]">
              <tr v-for="row in rows" :key="row.id" :class="row.status === 'returned' ? 'text-[hsl(var(--muted-foreground))]' : ''">
                <td class="py-2 whitespace-nowrap">{{ formatDate(row.purchased_on) }}</td>
                <td class="py-2">
                  <div class="font-medium">{{ row.item }}</div>
                  <div class="text-xs text-[hsl(var(--muted-foreground))]">
                    {{ [row.category, row.supplier].filter(Boolean).join(' · ') }}
                    <Link v-if="row.journal_entry_id" :href="`/accounting/journal-entries/${row.journal_entry_id}`" class="ml-1 hover:underline">{{ row.journal_reference || t('journal_entry') }}</Link>
                  </div>
                </td>
                <td class="py-2 text-xs">
                  <div v-for="p in row.people" :key="p.employee_id" class="whitespace-nowrap">{{ p.name }}<span v-if="Number(p.share) !== 100"> {{ Number(p.share) }} %</span></div>
                </td>
                <td class="py-2 text-right tabular-nums whitespace-nowrap">{{ formatCurrency(row.gross_amount) }}</td>
                <td class="py-2 text-right tabular-nums whitespace-nowrap" :class="row.status === 'returned' ? 'line-through' : ''">{{ formatCurrency(row.net_amount) }}</td>
                <td class="py-2"><Badge :variant="statusVariant[row.status]">{{ t('acquisition_status_' + row.status) }}</Badge></td>
                <td class="py-2 whitespace-nowrap">
                  <Badge v-if="row.return_due" variant="warning">{{ formatDate(row.return_until) }}</Badge>
                  <span v-else-if="row.return_until">{{ formatDate(row.return_until) }}</span>
                </td>
                <td class="py-2 whitespace-nowrap">{{ row.warranty_until ? formatDate(row.warranty_until) : '' }}</td>
                <td class="py-2">
                  <div v-if="can.manage" class="flex justify-end gap-1">
                    <Button variant="ghost" size="icon" :aria-label="t('edit')" @click="openEdit(row)"><Pencil class="h-4 w-4" /></Button>
                    <Button variant="ghost" size="icon" :aria-label="t('delete')" @click="deleting = row"><Trash2 class="h-4 w-4 text-[hsl(var(--destructive))]" /></Button>
                  </div>
                </td>
              </tr>
              <tr v-if="rows.length === 0">
                <td colspan="9" class="py-6 text-center text-[hsl(var(--muted-foreground))]">{{ t('acquisitions_none_match') }}</td>
              </tr>
              <tr v-else class="font-semibold">
                <td class="py-2" colspan="3">{{ hasFilter ? t('acquisitions_total_shown', { count: rows.length }) : t('total') }}</td>
                <td class="py-2 text-right tabular-nums whitespace-nowrap">{{ formatCurrency(visibleTotals.gross) }}</td>
                <td class="py-2 text-right tabular-nums whitespace-nowrap">{{ formatCurrency(visibleTotals.net) }}</td>
                <td colspan="4"></td>
              </tr>
            </tbody>
          </table>
        </div>
      </CardContent>
    </Card>

    <Modal :open="showForm" :title="editing ? t('edit') : t('add')" size="xl" @close="showForm = false">
      <form class="space-y-4" @submit.prevent="submit">
        <FormInput id="item" v-model="form.item" :label="t('acquisition_item')" :error="form.errors.item" required />
        <div class="grid gap-4 sm:grid-cols-3">
          <FormInput id="category" v-model="form.category" :label="t('acquisition_category')" :error="form.errors.category" />
          <FormInput id="supplier" v-model="form.supplier" :label="t('acquisition_supplier')" :error="form.errors.supplier" />
          <FormInput id="purchased_on" v-model="form.purchased_on" type="date" :label="t('date')" :error="form.errors.purchased_on" required />
        </div>
        <div class="grid gap-4 sm:grid-cols-4">
          <FormInput id="quantity" v-model="form.quantity" type="number" step="0.01" min="0" :label="t('acquisition_quantity')" :error="form.errors.quantity" required />
          <FormInput id="unit_price" v-model="form.unit_price" type="number" step="0.01" min="0" :label="t('acquisition_unit_price')" :error="form.errors.unit_price" required />
          <FormInput id="vat_rate" v-model="form.vat_rate" type="number" step="0.01" min="0" :label="t('acquisition_vat_rate')" :placeholder="t('acquisition_no_vat')" :error="form.errors.vat_rate" />
          <FormInput id="net_amount" v-model="form.net_amount" type="number" step="0.01" min="0" :label="t('acquisition_net')" :placeholder="suggestedNet.toFixed(2)" :error="form.errors.net_amount" />
        </div>
        <p class="text-xs text-[hsl(var(--muted-foreground))]">{{ t('acquisition_gross') }}: {{ formatCurrency(gross) }} · {{ t('acquisition_net_hint') }}</p>
        <div>
          <div class="mb-1 flex items-center justify-between">
            <label class="text-sm font-medium">{{ t('acquisition_people') }}</label>
            <span class="text-xs" :class="Math.abs(shareTotal - 100) > 0.001 ? 'text-[hsl(var(--destructive))]' : 'text-[hsl(var(--muted-foreground))]'">{{ shareTotal.toFixed(2) }} %</span>
          </div>
          <div v-for="(person, index) in form.people" :key="index" class="mb-2 flex items-end gap-2">
            <FormSelect :id="`person_${index}`" v-model="person.employee_id" :options="employeeOptions" class="flex-1" />
            <FormInput :id="`share_${index}`" v-model="person.share" type="number" step="0.01" min="0" max="100" class="w-28" />
            <Button v-if="form.people.length > 1" type="button" variant="ghost" size="icon" :aria-label="t('delete')" @click="removePerson(index)"><X class="h-4 w-4" /></Button>
          </div>
          <Button type="button" size="sm" variant="outline" @click="addPerson"><Plus class="mr-1 h-3 w-3" /> {{ t('acquisition_add_person') }}</Button>
          <p v-if="peopleError" class="mt-1 text-xs text-[hsl(var(--destructive))]">{{ peopleError }}</p>
        </div>
        <div class="grid gap-4 sm:grid-cols-3">
          <FormSelect id="status" v-model="form.status" :label="t('status')" :options="statusOptions" />
          <FormInput id="return_until" v-model="form.return_until" type="date" :label="t('acquisition_return_until')" :error="form.errors.return_until" />
          <FormInput id="warranty_until" v-model="form.warranty_until" type="date" :label="t('acquisition_warranty_until')" :error="form.errors.warranty_until" />
        </div>
        <FormTextarea id="notes" v-model="form.notes" :label="t('notes')" :error="form.errors.notes" />
        <div class="flex justify-end gap-2">
          <Button variant="outline" type="button" @click="showForm = false">{{ t('cancel') }}</Button>
          <Button type="submit" :disabled="form.processing" :loading="form.processing">{{ t('save') }}</Button>
        </div>
      </form>
    </Modal>

    <ConfirmDialog :open="!!deleting" :title="t('confirm_delete')" :message="deleting?.item ?? ''" @confirm="performDelete" @cancel="deleting = null" />
  </AppLayout>
</template>
