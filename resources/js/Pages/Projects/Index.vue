<script setup>
import AppLayout from '@/Components/AppLayout.vue'
import Card from '@/Components/UI/Card.vue'
import CardHeader from '@/Components/UI/CardHeader.vue'
import CardTitle from '@/Components/UI/CardTitle.vue'
import CardContent from '@/Components/UI/CardContent.vue'
import Badge from '@/Components/UI/Badge.vue'
import Button from '@/Components/UI/Button.vue'
import DataTable from '@/Components/UI/DataTable.vue'
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue'
import Modal from '@/Components/UI/Modal.vue'
import FormInput from '@/Components/UI/FormInput.vue'
import FormSelect from '@/Components/UI/FormSelect.vue'
import FormTextarea from '@/Components/UI/FormTextarea.vue'
import SearchableSelect from '@/Components/UI/SearchableSelect.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'
import { useTranslations } from '@/lib/useTranslations'
import { formatCurrency } from '@/lib/utils'
import { computed, ref, watch } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import { Plus, Pencil, Trash2, FolderKanban, BarChart3, ListChecks, ListTodo, Download } from 'lucide-vue-next'

const props = defineProps({
  projects: Array,
  contacts: Array,
  employees: Array,
  kinds: Array,
  statuses: Array,
  openReviews: Number,
  can: Object,
  overview: Object,
  year: Number,
})

const { t } = useTranslations()

const statusFilter = ref('')
const kindFilter = ref('')
const showInactive = ref(false)

const statusVariant = {
  pipeline: 'info',
  possible: 'secondary',
  in_progress: 'warning',
  done: 'success',
  lost: 'destructive',
}

const statusLabel = (value) => props.statuses.find(s => s.value === value)?.label ?? value
const kindLabel = (value) => props.kinds.find(k => k.value === value)?.label ?? value

// Two views of the same projects: the figures of a year, as the
// spreadsheet showed them, and the master data behind them.
const VIEW_KEY = 'gaeld.projects.view'
function storedView() {
  try {
    return localStorage.getItem(VIEW_KEY) === 'master' ? 'master' : 'overview'
  } catch {
    return 'overview'
  }
}
const activeView = ref(storedView())
watch(activeView, view => {
  try {
    localStorage.setItem(VIEW_KEY, view)
  } catch {
    // Remembering the view is a convenience only.
  }
})

const yearOptions = computed(() => {
  const now = new Date().getFullYear()
  return [now + 1, now, now - 1, now - 2, now - 3].map(y => ({ value: String(y), label: String(y) }))
})
function changeYear(value) {
  router.get('/projects', { year: value }, { preserveScroll: true, preserveState: true })
}

const figuresById = computed(() => Object.fromEntries((props.overview?.rows ?? []).map(r => [r.id, r])))

const deadlineVariant = { overdue: 'destructive', critical: 'warning', on_track: 'success', done: 'secondary', none: 'outline' }
const paymentVariant = { paid: 'success', partial: 'warning', open: 'destructive' }

const overviewColumns = computed(() => [
  { key: 'code', label: t('code') },
  { key: 'name', label: t('name') },
  { key: 'status', label: t('status') },
  { key: 'deadline', label: t('project_deadline') },
  { key: 'billing_month', label: t('project_billing_month') },
  { key: 'expected', label: t('project_expected_amount'), class: 'text-right whitespace-nowrap' },
  { key: 'revenue', label: t('revenue'), class: 'text-right whitespace-nowrap' },
  { key: 'expenses', label: t('expenses'), class: 'text-right whitespace-nowrap' },
  { key: 'net', label: t('project_net'), class: 'text-right whitespace-nowrap' },
  { key: 'received', label: t('project_received'), class: 'text-right whitespace-nowrap' },
  { key: 'open', label: t('project_open'), class: 'text-right whitespace-nowrap' },
  { key: 'payment', label: t('project_payment') },
  { key: 'actions', label: '', sortable: false },
])

const overviewRows = computed(() => rows.value.map(p => ({ ...p, ...(figuresById.value[p.id] ?? {}), depth: p.depth })))
const money = value => (value === null || value === undefined || Number(value) === 0 ? '' : formatCurrency(value))

const rows = computed(() => props.projects.filter(p =>
  (showInactive.value || p.is_active)
  && (!statusFilter.value || p.status === statusFilter.value)
  && (!kindFilter.value || p.kind === kindFilter.value),
))

const columns = computed(() => [
  { key: 'code', label: t('code') },
  { key: 'name', label: t('name') },
  { key: 'kind', label: t('project_kind') },
  { key: 'status', label: t('status') },
  { key: 'contact_name', label: t('project_customer') },
  { key: 'responsible_name', label: t('project_responsible') },
  { key: 'target_date', label: t('project_target_date') },
  { key: 'expected_amount', label: t('project_expected_amount'), align: 'right' },
  { key: 'actions', label: '', sortable: false },
])

const filterStatusOptions = computed(() => [{ value: '', label: t('all') }, ...props.statuses])
const filterKindOptions = computed(() => [{ value: '', label: t('all') }, ...props.kinds])

const parentOptions = computed(() => [
  { value: '', label: t('none') },
  ...props.projects
    .filter(p => p.is_active && p.id !== editing.value?.id)
    .map(p => ({ value: p.id, label: `${'· '.repeat(p.depth)}${p.code} ${p.name}` })),
])
const contactOptions = computed(() => [{ value: '', label: t('none') }, ...props.contacts.map(c => ({ value: c.id, label: c.name }))])
const employeeOptions = computed(() => [{ value: '', label: t('none') }, ...props.employees.map(e => ({ value: e.id, label: e.name }))])

const showForm = ref(false)
const editing = ref(null)

const blankProject = () => ({
  code: '',
  name: '',
  parent_id: '',
  kind: 'project',
  status: 'in_progress',
  contact_id: '',
  responsible_employee_id: '',
  target_date: '',
  billing_month: '',
  expected_amount: '',
  notes: '',
  is_active: true,
})

const form = useForm(blankProject())

// Assigned field by field: after a save Inertia keeps the submitted values
// as the form's defaults, so reset() would bring back the last project.
function openCreate() {
  editing.value = null
  Object.assign(form, blankProject())
  form.clearErrors()
  showForm.value = true
}

function openEdit(project) {
  editing.value = project
  form.code = project.code
  form.name = project.name
  form.parent_id = project.parent_id ?? ''
  form.kind = project.kind
  form.status = project.status
  form.contact_id = project.contact_id ?? ''
  form.responsible_employee_id = project.responsible_employee_id ?? ''
  form.target_date = project.target_date ?? ''
  form.billing_month = project.billing_month ?? ''
  form.expected_amount = project.expected_amount ?? ''
  form.notes = project.notes ?? ''
  form.is_active = project.is_active
  form.clearErrors()
  showForm.value = true
}

function submitForm() {
  const options = { preserveScroll: true, onSuccess: () => { showForm.value = false } }
  if (editing.value) {
    form.put(`/projects/${editing.value.id}`, options)
  } else {
    form.post('/projects', options)
  }
}

const showDelete = ref(false)
const deleting = ref(null)
const deleteProcessing = ref(false)

function confirmDelete(project) {
  deleting.value = project
  showDelete.value = true
}

function performDelete() {
  if (!deleting.value) return
  deleteProcessing.value = true
  router.delete(`/projects/${deleting.value.id}`, {
    preserveScroll: true,
    onFinish: () => {
      deleteProcessing.value = false
      showDelete.value = false
      deleting.value = null
    },
  })
}
</script>

<template>
  <AppLayout :title="t('projects')">
    <Card>
      <CardHeader>
        <div class="flex flex-wrap items-center justify-between gap-2">
          <CardTitle>{{ t('projects') }}</CardTitle>
          <div class="flex flex-wrap items-center gap-2">
            <Link v-if="openReviews > 0" href="/projects/allocation-reviews">
              <Button size="sm" variant="outline">
                <ListChecks class="mr-1 h-4 w-4" /> {{ t('allocation_reviews') }}
                <Badge variant="warning" class="ml-2">{{ openReviews }}</Badge>
              </Button>
            </Link>
            <Link v-if="can.allocate" :href="`/projects/unallocated?year=${year}`">
              <Button size="sm" variant="outline"><ListTodo class="mr-1 h-4 w-4" /> {{ t('unallocated_lines') }}</Button>
            </Link>
            <Button v-if="can.manage" size="sm" @click="openCreate">
              <Plus class="mr-1 h-4 w-4" /> {{ t('add') }}
            </Button>
          </div>
        </div>
      </CardHeader>
      <CardContent>
        <p class="mb-4 text-sm text-[hsl(var(--muted-foreground))]">{{ t('projects_desc') }}</p>
        <div class="mb-4 flex flex-wrap items-end gap-3">
          <div class="flex gap-1 rounded-md border border-[hsl(var(--border))] p-1" role="tablist">
            <button
              v-for="view in [{ value: 'overview', label: t('project_view_overview') }, { value: 'master', label: t('project_view_master') }]"
              :key="view.value"
              type="button"
              role="tab"
              :aria-selected="activeView === view.value"
              class="rounded px-3 py-1 text-sm transition-colors"
              :class="activeView === view.value ? 'bg-[hsl(var(--primary))] text-[hsl(var(--primary-foreground))]' : 'text-[hsl(var(--muted-foreground))] hover:bg-[hsl(var(--muted))]/50'"
              @click="activeView = view.value"
            >
              {{ view.label }}
            </button>
          </div>
          <FormSelect v-if="activeView === 'overview'" id="filter_year" :model-value="String(year)" :label="t('year')" :options="yearOptions" @update:model-value="changeYear" />
          <FormSelect id="filter_status" v-model="statusFilter" :label="t('status')" :options="filterStatusOptions" />
          <FormSelect id="filter_kind" v-model="kindFilter" :label="t('project_kind')" :options="filterKindOptions" />
          <label class="flex items-center gap-2 pb-2 text-sm">
            <input v-model="showInactive" type="checkbox" class="h-4 w-4 rounded border-[hsl(var(--input))]" />
            {{ t('project_show_inactive') }}
          </label>
          <a v-if="activeView === 'overview'" :href="`/projects/export?year=${year}`" class="ml-auto">
            <Button size="sm" variant="outline"><Download class="mr-1 h-4 w-4" /> CSV</Button>
          </a>
        </div>

        <template v-if="activeView === 'overview'">
          <DataTable :columns="overviewColumns" :rows="overviewRows">
            <template #empty>
              <EmptyState :icon="FolderKanban" :title="t('empty_projects_title')" :description="t('empty_projects_desc')" />
            </template>
            <template #cell-name="{ row }">
              <span :style="{ paddingLeft: `${row.depth * 1.25}rem` }" :class="[row.kind === 'area' ? 'font-semibold' : '', row.is_active ? '' : 'text-[hsl(var(--muted-foreground))] line-through']">
                {{ row.name }}
              </span>
              <div v-if="row.customer && row.kind !== 'area'" class="text-xs text-[hsl(var(--muted-foreground))]" :style="{ paddingLeft: `${row.depth * 1.25}rem` }">{{ row.customer }}</div>
            </template>
            <template #cell-status="{ row }">
              <Badge v-if="row.kind !== 'area'" :variant="statusVariant[row.status] ?? 'default'">{{ statusLabel(row.status) }}</Badge>
            </template>
            <template #cell-deadline="{ row }">
              <span v-if="row.kind !== 'area'" class="inline-flex items-center gap-2 whitespace-nowrap">
                <Badge :variant="deadlineVariant[row.deadline] ?? 'outline'">{{ t('project_deadline_' + row.deadline) }}</Badge>
                <span class="text-xs text-[hsl(var(--muted-foreground))]">{{ row.target_date }}</span>
              </span>
            </template>
            <template #cell-expected="{ value }"><span class="tabular-nums">{{ money(value) }}</span></template>
            <template #cell-revenue="{ value }"><span class="tabular-nums">{{ money(value) }}</span></template>
            <template #cell-expenses="{ value }"><span class="tabular-nums">{{ money(value) }}</span></template>
            <template #cell-net="{ value }">
              <span class="tabular-nums font-medium" :class="Number(value) < 0 ? 'text-[hsl(var(--destructive))]' : ''">{{ money(value) }}</span>
            </template>
            <template #cell-received="{ value }"><span class="tabular-nums">{{ money(value) }}</span></template>
            <template #cell-open="{ value }"><span class="tabular-nums">{{ money(value) }}</span></template>
            <template #cell-payment="{ value }">
              <Badge v-if="value" :variant="paymentVariant[value]">{{ t('project_payment_' + value) }}</Badge>
            </template>
            <template #cell-actions="{ row }">
              <div class="flex items-center justify-end gap-1">
                <Link :href="`/projects/${row.id}/report?from=${year}-01-01&to=${year}-12-31`">
                  <Button variant="ghost" size="icon" :aria-label="t('project_report')"><BarChart3 class="h-4 w-4" /></Button>
                </Link>
                <Button v-if="can.manage" variant="ghost" size="icon" :aria-label="t('edit')" @click="openEdit(row)"><Pencil class="h-4 w-4" /></Button>
              </div>
            </template>
          </DataTable>

          <div v-if="overview" class="mt-6 grid gap-4 lg:grid-cols-2">
            <div>
              <h3 class="mb-2 text-sm font-semibold">{{ t('project_sums_by_status') }}</h3>
              <table class="w-full text-sm">
                <thead>
                  <tr class="border-b text-left text-[hsl(var(--muted-foreground))]">
                    <th class="pb-1">{{ t('status') }}</th><th class="pb-1 text-right">{{ t('project_expected_amount') }}</th><th class="pb-1 text-right">{{ t('project_net') }}</th><th class="pb-1 text-right">{{ t('project_open') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="group in overview.by_status" :key="group.key">
                    <td class="py-1">{{ statusLabel(group.key) }} <span class="text-xs text-[hsl(var(--muted-foreground))]">({{ group.count }})</span></td>
                    <td class="py-1 text-right tabular-nums">{{ formatCurrency(group.expected) }}</td>
                    <td class="py-1 text-right tabular-nums">{{ formatCurrency(group.net) }}</td>
                    <td class="py-1 text-right tabular-nums">{{ formatCurrency(group.open) }}</td>
                  </tr>
                  <tr class="border-t font-semibold">
                    <td class="py-1">{{ t('total') }}</td>
                    <td class="py-1 text-right tabular-nums">{{ formatCurrency(overview.totals.expected) }}</td>
                    <td class="py-1 text-right tabular-nums">{{ formatCurrency(overview.totals.net) }}</td>
                    <td class="py-1 text-right tabular-nums">{{ formatCurrency(overview.totals.open) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div>
              <h3 class="mb-2 text-sm font-semibold">{{ t('project_sums_by_area') }}</h3>
              <table class="w-full text-sm">
                <thead>
                  <tr class="border-b text-left text-[hsl(var(--muted-foreground))]">
                    <th class="pb-1">{{ t('project_area') }}</th><th class="pb-1 text-right">{{ t('revenue') }}</th><th class="pb-1 text-right">{{ t('expenses') }}</th><th class="pb-1 text-right">{{ t('project_net') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="group in overview.by_area" :key="group.key ?? 'none'">
                    <td class="py-1">{{ group.key ?? t('project_no_area') }} <span class="text-xs text-[hsl(var(--muted-foreground))]">({{ group.count }})</span></td>
                    <td class="py-1 text-right tabular-nums">{{ formatCurrency(group.revenue) }}</td>
                    <td class="py-1 text-right tabular-nums">{{ formatCurrency(group.expenses) }}</td>
                    <td class="py-1 text-right tabular-nums">{{ formatCurrency(group.net) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </template>

        <DataTable v-else :columns="columns" :rows="rows">
          <template #empty>
            <EmptyState
              :icon="FolderKanban"
              :title="t('empty_projects_title')"
              :description="t('empty_projects_desc')"
              :action-label="can.manage ? t('create_first') : undefined"
              @action="openCreate"
            />
          </template>
          <template #cell-name="{ row }">
            <span :style="{ paddingLeft: `${row.depth * 1.25}rem` }" :class="[row.kind === 'area' ? 'font-semibold' : '', row.is_active ? '' : 'text-[hsl(var(--muted-foreground))] line-through']">
              {{ row.name }}
            </span>
          </template>
          <template #cell-kind="{ value }">{{ kindLabel(value) }}</template>
          <template #cell-status="{ value }">
            <Badge :variant="statusVariant[value] ?? 'default'">{{ statusLabel(value) }}</Badge>
          </template>
          <template #cell-expected_amount="{ value }">
            <span class="tabular-nums">{{ value !== null && value !== '' ? formatCurrency(value) : '' }}</span>
          </template>
          <template #cell-actions="{ row }">
            <div class="flex items-center justify-end gap-1">
              <Link :href="`/projects/${row.id}/report`">
                <Button variant="ghost" size="icon" :aria-label="t('project_report')"><BarChart3 class="h-4 w-4" /></Button>
              </Link>
              <template v-if="can.manage">
                <Button variant="ghost" size="icon" :aria-label="t('edit')" @click="openEdit(row)"><Pencil class="h-4 w-4" /></Button>
                <Button variant="ghost" size="icon" :aria-label="t('delete')" @click="confirmDelete(row)">
                  <Trash2 class="h-4 w-4 text-[hsl(var(--destructive))]" />
                </Button>
              </template>
            </div>
          </template>
        </DataTable>
      </CardContent>
    </Card>

    <Modal :open="showForm" :title="editing ? t('edit') : t('add')" @close="showForm = false">
      <form class="space-y-4" @submit.prevent="submitForm">
        <div class="grid gap-4 sm:grid-cols-3">
          <FormInput id="code" v-model="form.code" :label="t('code')" :error="form.errors.code" required />
          <FormInput id="name" v-model="form.name" :label="t('name')" :error="form.errors.name" required class="sm:col-span-2" />
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
          <FormSelect id="kind" v-model="form.kind" :label="t('project_kind')" :options="kinds" :error="form.errors.kind" required />
          <FormSelect id="status" v-model="form.status" :label="t('status')" :options="statuses" :error="form.errors.status" required />
        </div>
        <SearchableSelect id="parent_id" v-model="form.parent_id" :label="t('project_parent')" :options="parentOptions" :error="form.errors.parent_id" />
        <div class="grid gap-4 sm:grid-cols-2">
          <SearchableSelect id="contact_id" v-model="form.contact_id" :label="t('project_customer')" :options="contactOptions" :error="form.errors.contact_id" />
          <SearchableSelect id="responsible_employee_id" v-model="form.responsible_employee_id" :label="t('project_responsible')" :options="employeeOptions" :error="form.errors.responsible_employee_id" />
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
          <FormInput id="target_date" v-model="form.target_date" type="date" :label="t('project_target_date')" :error="form.errors.target_date" />
          <FormInput id="expected_amount" v-model="form.expected_amount" type="number" step="0.01" min="0" :label="t('project_expected_amount')" :error="form.errors.expected_amount" />
        </div>
        <FormInput id="billing_month" v-model="form.billing_month" type="month" :label="t('project_billing_month')" :error="form.errors.billing_month" />
        <FormTextarea id="notes" v-model="form.notes" :label="t('notes')" :error="form.errors.notes" />
        <label v-if="editing" class="flex items-center gap-2 text-sm">
          <input v-model="form.is_active" type="checkbox" class="h-4 w-4 rounded border-[hsl(var(--input))]" />
          {{ t('active') }}
        </label>
        <div class="flex justify-end gap-2">
          <Button variant="outline" type="button" @click="showForm = false">{{ t('cancel') }}</Button>
          <Button type="submit" :disabled="form.processing" :loading="form.processing">{{ t('save') }}</Button>
        </div>
      </form>
    </Modal>

    <ConfirmDialog
      :open="showDelete"
      :title="t('confirm_delete')"
      :message="deleting ? `${deleting.code} ${deleting.name}` : ''"
      :processing="deleteProcessing"
      @confirm="performDelete"
      @cancel="showDelete = false"
    />
  </AppLayout>
</template>
