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
import { useTranslations } from '@/lib/useTranslations'
import { useFormatters } from '@/lib/useFormatters'
import { Link, router, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { Download, Package, Pencil, Plus, Trash2, X } from 'lucide-vue-next'

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

function applyFilters(changes) {
  router.get('/projects/acquisitions', { year: props.year, ...props.filters, ...changes }, { preserveScroll: true, preserveState: true })
}

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
            <FormSelect id="year" :model-value="String(year)" :label="t('year')" :options="yearOptions" @update:model-value="v => applyFilters({ year: v })" />
            <FormSelect id="filter_person" :model-value="filters.employee_id" :label="t('acquisition_person')" :options="[{ value: '', label: t('all') }, ...employeeOptions.slice(1)]" @update:model-value="v => applyFilters({ employee_id: v })" />
            <FormSelect id="filter_status" :model-value="filters.status" :label="t('status')" :options="[{ value: '', label: t('all') }, ...statusOptions]" @update:model-value="v => applyFilters({ status: v })" />
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

        <EmptyState v-if="overview.rows.length === 0" :icon="Package" :title="t('acquisitions_empty_title')" :description="t('acquisitions_empty_desc')" :action-label="can.manage ? t('create_first') : undefined" @action="openCreate" />
        <div v-else class="overflow-x-auto">
          <table class="w-full text-sm [&_td]:pr-3 [&_th]:pr-3">
            <thead>
              <tr class="border-b text-left text-[hsl(var(--muted-foreground))]">
                <th class="pb-2">{{ t('date') }}</th>
                <th class="pb-2">{{ t('acquisition_item') }}</th>
                <th class="pb-2">{{ t('acquisition_people') }}</th>
                <th class="pb-2 text-right">{{ t('acquisition_gross') }}</th>
                <th class="pb-2 text-right">{{ t('acquisition_net') }}</th>
                <th class="pb-2">{{ t('status') }}</th>
                <th class="pb-2">{{ t('acquisition_return_until') }}</th>
                <th class="pb-2">{{ t('acquisition_warranty_until') }}</th>
                <th class="pb-2"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-[hsl(var(--border))]">
              <tr v-for="row in overview.rows" :key="row.id" :class="row.status === 'returned' ? 'text-[hsl(var(--muted-foreground))]' : ''">
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
