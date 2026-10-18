<script setup>
import AppLayout from '@/Components/AppLayout.vue'
import Card from '@/Components/UI/Card.vue'
import CardContent from '@/Components/UI/CardContent.vue'
import Badge from '@/Components/UI/Badge.vue'
import Button from '@/Components/UI/Button.vue'
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue'
import FormSelect from '@/Components/UI/FormSelect.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'
import ProjectFormModal from '@/Components/Projects/ProjectFormModal.vue'
import ColumnMenu from '@/Components/Projects/ColumnMenu.vue'
import { useTranslations } from '@/lib/useTranslations'
import { formatCurrency } from '@/lib/utils'
import { computed, ref, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { AlertTriangle, CheckCircle2, Download, FolderKanban, FolderPlus, ListChecks, Pencil, Plus, Search, Trash2, X } from 'lucide-vue-next'

// Jobs grouped by project, with what each brought in net (spec 010, US1
// and US3). On top: whether every receipt of the year has its place.
const props = defineProps({
  projects: Array,
  contacts: Array,
  employees: Array,
  kinds: Array,
  statuses: Array,
  openReviews: Number,
  can: Object,
  overview: Object,
  coverage: Object,
  year: Number,
})

const { t } = useTranslations()

const statusVariant = { pipeline: 'info', possible: 'secondary', in_progress: 'warning', done: 'success', lost: 'destructive' }
const statusLabel = value => props.statuses.find(s => s.value === value)?.label ?? value

const yearOptions = computed(() => {
  const now = new Date().getFullYear()
  return [now + 1, now, now - 1, now - 2, now - 3].map(y => ({ value: String(y), label: String(y) }))
})
function changeYear(value) {
  router.get('/projects', { year: value }, { preserveScroll: true, preserveState: true })
}

// Filters stay in the browser between visits; a convenience only.
const FILTER_KEY = 'gaeld.jobs.filter'
function stored() {
  try {
    return JSON.parse(localStorage.getItem(FILTER_KEY) || '{}')
  } catch {
    return {}
  }
}
const search = ref('')
const saved = stored()
const showInactive = ref(!!saved.inactive)
const groupFilter = ref(typeof saved.group === 'string' ? saved.group : '')
// Notion-like: each column head filters and sorts (ColumnMenu).
const statusFilter = ref(Array.isArray(saved.statuses) ? saved.statuses : [])
const personFilter = ref(Array.isArray(saved.people) ? saved.people : [])
const sort = ref(saved.sort && typeof saved.sort === 'object' ? saved.sort : { key: 'code', dir: 'asc' })
watch([showInactive, groupFilter, statusFilter, personFilter, sort], () => {
  try {
    localStorage.setItem(FILTER_KEY, JSON.stringify({
      inactive: showInactive.value,
      group: groupFilter.value,
      statuses: statusFilter.value,
      people: personFilter.value,
      sort: sort.value,
    }))
  } catch {
    // Remembering the filter is a convenience only.
  }
}, { deep: true })

const figures = computed(() => Object.fromEntries((props.overview?.rows ?? []).map(r => [r.id, r])))
const byId = computed(() => Object.fromEntries(props.projects.map(p => [p.id, p])))

// The top-most project above a job, or null.
function groupOf(project) {
  let group = null
  let cursor = project.parent_id ? byId.value[project.parent_id] : null
  for (let depth = 0; cursor && depth < 32; depth++) {
    if (cursor.kind === 'area') group = cursor
    cursor = cursor.parent_id ? byId.value[cursor.parent_id] : null
  }
  return group
}

// Who works on a job: its own key, else the nearest one above it.
function keyOf(project) {
  let cursor = project
  for (let depth = 0; cursor && depth < 32; depth++) {
    if (cursor.shares?.length) return { shares: cursor.shares, inherited: cursor !== project }
    cursor = cursor.parent_id ? byId.value[cursor.parent_id] : null
  }
  return { shares: [], inherited: false }
}
const nameOf = id => props.employees.find(e => e.id === id)?.name ?? '?'
const initials = id => nameOf(id).split(' ').map(part => part[0]).join('').slice(0, 3).toUpperCase()

const groupOptions = computed(() => [
  { value: '', label: t('job_filter_all_projects') },
  ...props.projects.filter(p => p.kind === 'area' && !p.parent_id).map(p => ({ value: String(p.id), label: p.name })),
  { value: 'none', label: t('project_no_area') },
])
const statusOptions = computed(() => props.statuses.map(s => ({ value: s.value, label: s.label })))
const personOptions = computed(() => props.employees.map(e => ({ value: e.id, label: e.name })))
const columnLabel = { code: 'project', status: 'status', revenue: 'revenue', expenses: 'expenses', net: 'project_net', margin: 'job_margin' }

function setSort(key, dir) {
  sort.value = { key, dir }
}
const statusOrder = computed(() => Object.fromEntries(props.statuses.map((s, i) => [s.value, i])))
const sortValue = {
  code: j => j.code ?? '',
  status: j => statusOrder.value[j.status] ?? 99,
  revenue: j => Number(j.revenue || 0),
  expenses: j => Number(j.expenses || 0),
  net: j => Number(j.net || 0),
  margin: j => margin(j) ?? -Infinity,
}
function compare(a, b) {
  const get = sortValue[sort.value.key] ?? sortValue.code
  const [x, y] = [get(a), get(b)]
  const order = typeof x === 'string' ? x.localeCompare(y, undefined, { numeric: true }) : x - y
  return sort.value.dir === 'desc' ? -order : order
}

const matches = (job) => {
  if (!showInactive.value && !job.is_active) return false
  if (groupFilter.value && String(groupOf(job)?.id ?? 'none') !== groupFilter.value) return false
  // Without a status filter lost jobs stay out, as they count for nobody.
  if (statusFilter.value.length ? !statusFilter.value.includes(job.status) : job.status === 'lost') return false
  if (personFilter.value.length && !keyOf(job).shares.some(s => personFilter.value.includes(s.employee_id))) return false
  const q = search.value.trim().toLowerCase()
  if (!q) return true
  const f = figures.value[job.id] ?? {}
  return [job.code, job.name, f.customer, groupOf(job)?.name].some(v => (v ?? '').toLowerCase().includes(q))
}

const hasFilter = computed(() => statusFilter.value.length > 0 || personFilter.value.length > 0 || groupFilter.value !== '' || search.value !== '')
function clearFilters() {
  statusFilter.value = []
  personFilter.value = []
  groupFilter.value = ''
  search.value = ''
  sort.value = { key: 'code', dir: 'asc' }
}

const margin = (row) => Number(row.revenue) > 0 ? Math.round((Number(row.net) / Number(row.revenue)) * 100) : null

const groups = computed(() => {
  const list = []
  const index = {}
  const add = (group) => {
    const key = group ? group.id : 0
    if (!index[key]) {
      index[key] = { key, group, jobs: [] }
      list.push(index[key])
    }
    return index[key]
  }
  props.projects.filter(p => p.kind === 'area' && !groupOf(p)).forEach(add)
  props.projects
    .filter(p => p.kind !== 'area' && matches(p))
    .forEach(job => add(groupOf(job)).jobs.push({ ...job, ...(figures.value[job.id] ?? {}), key: keyOf(job) }))

  return list
    .filter(g => g.jobs.length > 0 || (!hasFilter.value && g.group && (showInactive.value || g.group.is_active)))
    .map(g => {
      g.jobs.sort(compare)
      const sum = g.jobs.reduce((s, j) => ({
        revenue: s.revenue + Number(j.revenue || 0),
        expenses: s.expenses + Number(j.expenses || 0),
        net: s.net + Number(j.net || 0),
        // Still to come: what open jobs without any revenue yet expect.
        expected: s.expected + (!Number(j.revenue) && !['done', 'lost'].includes(j.status) ? Number(j.expected || 0) : 0),
      }), { revenue: 0, expenses: 0, net: 0, expected: 0 })
      return { ...g, sum }
    })
})

const totals = computed(() => props.overview?.totals ?? { revenue: 0, expenses: 0, net: 0 })
const totalMargin = computed(() => margin(totals.value))
const money = value => (value === null || value === undefined || Number(value) === 0 ? '' : formatCurrency(value))

const coverageShare = computed(() => {
  const c = props.coverage
  return c && c.total > 0 ? Math.round((c.done / c.total) * 100) : 100
})

// Create and edit
const showForm = ref(false)
const editing = ref(null)
const preset = ref({})
const formOptions = computed(() => ({ projects: props.projects, contacts: props.contacts, employees: props.employees, kinds: props.kinds, statuses: props.statuses }))
function openCreate(values = {}) {
  editing.value = null
  preset.value = values
  showForm.value = true
}
function openEdit(project) {
  editing.value = project
  showForm.value = true
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

const jobHref = job => `/projects/${job.id}/report?from=${props.year}-01-01&to=${props.year}-12-31`
</script>

<template>
  <AppLayout :title="t('jobs')">
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
      <div class="flex items-end gap-3">
        <h1 class="text-2xl font-semibold">{{ t('jobs') }}</h1>
        <FormSelect id="filter_year" :model-value="String(year)" :options="yearOptions" :aria-label="t('year')" class="w-28" @update:model-value="changeYear" />
      </div>
      <div v-if="can.manage" class="flex flex-wrap gap-2">
        <Button variant="outline" @click="openCreate({ kind: 'area' })"><FolderPlus class="mr-1 h-4 w-4" /> {{ t('group_new') }}</Button>
        <Button @click="openCreate()"><Plus class="mr-1 h-4 w-4" /> {{ t('job_new') }}</Button>
      </div>
    </div>

    <!-- US1: has every receipt its place? -->
    <Card v-if="coverage" class="mb-4">
      <CardContent class="pt-6">
        <div v-if="coverage.open === 0" class="flex items-start gap-3">
          <CheckCircle2 class="mt-0.5 h-6 w-6 shrink-0 text-green-600" />
          <div>
            <div class="font-medium">{{ t('coverage_all_done', { year }) }}</div>
            <div class="text-sm text-[hsl(var(--muted-foreground))]">
              {{ t('coverage_revenue', { assigned: coverage.revenue.assigned.count, general: coverage.revenue.general.count, open: 0 }) }} ·
              {{ t('coverage_expense', { assigned: coverage.expense.assigned.count, general: coverage.expense.general.count, open: 0 }) }}
            </div>
          </div>
        </div>
        <div v-else class="flex flex-wrap items-center justify-between gap-4">
          <div class="flex min-w-[16rem] flex-1 items-start gap-3">
            <AlertTriangle class="mt-0.5 h-6 w-6 shrink-0 text-amber-500" />
            <div class="flex-1">
              <div class="font-medium">{{ t('coverage_open', { count: coverage.open }) }}</div>
              <div class="my-2 h-2 w-full overflow-hidden rounded-full bg-[hsl(var(--muted))]" role="progressbar" :aria-valuenow="coverageShare" aria-valuemin="0" aria-valuemax="100">
                <div class="h-full rounded-full bg-green-600" :style="{ width: `${coverageShare}%` }" />
              </div>
              <div class="text-sm text-[hsl(var(--muted-foreground))]">
                {{ t('coverage_progress', { done: coverage.done, total: coverage.total }) }} ·
                {{ t('coverage_revenue', { assigned: coverage.revenue.assigned.count, general: coverage.revenue.general.count, open: coverage.revenue.open.count }) }} ·
                {{ t('coverage_expense', { assigned: coverage.expense.assigned.count, general: coverage.expense.general.count, open: coverage.expense.open.count }) }}
              </div>
            </div>
          </div>
          <Link :href="`/projects/unallocated?year=${year}`">
            <Button><ListChecks class="mr-1 h-4 w-4" /> {{ t('coverage_action') }}</Button>
          </Link>
        </div>
        <p class="mt-2 text-xs text-[hsl(var(--muted-foreground))]">
          {{ t('coverage_hidden', { payroll: coverage.revenue.payroll.count + coverage.expense.payroll.count, corrections: coverage.corrections }) }}
        </p>
      </CardContent>
    </Card>

    <div v-if="openReviews > 0" class="mb-4 flex flex-wrap items-center justify-between gap-2 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200">
      <span>{{ t('allocation_reviews_desc') }}</span>
      <Link href="/projects/allocation-reviews"><Button size="sm" variant="outline">{{ t('allocation_reviews') }} ({{ openReviews }})</Button></Link>
    </div>

    <!-- Year at a glance -->
    <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
      <div class="rounded-lg border border-[hsl(var(--border))] p-4">
        <div class="text-xs text-[hsl(var(--muted-foreground))]">{{ t('revenue') }}</div>
        <div class="text-xl font-semibold tabular-nums">{{ formatCurrency(totals.revenue) }}</div>
      </div>
      <div class="rounded-lg border border-[hsl(var(--border))] p-4">
        <div class="text-xs text-[hsl(var(--muted-foreground))]">{{ t('expenses') }}</div>
        <div class="text-xl font-semibold tabular-nums">{{ formatCurrency(totals.expenses) }}</div>
      </div>
      <div class="rounded-lg border border-[hsl(var(--border))] p-4">
        <div class="text-xs text-[hsl(var(--muted-foreground))]">{{ t('project_net') }}</div>
        <div class="text-xl font-semibold tabular-nums" :class="Number(totals.net) < 0 ? 'text-[hsl(var(--destructive))]' : ''">{{ formatCurrency(totals.net) }}</div>
      </div>
      <div class="rounded-lg border border-[hsl(var(--border))] p-4">
        <div class="text-xs text-[hsl(var(--muted-foreground))]">{{ t('job_margin') }}</div>
        <div class="text-xl font-semibold tabular-nums">{{ totalMargin === null ? '·' : `${totalMargin} %` }}</div>
      </div>
    </div>

    <div class="mb-4 flex flex-wrap items-end gap-3">
      <label class="relative min-w-[14rem] flex-1">
        <Search class="pointer-events-none absolute left-2.5 top-2.5 h-4 w-4 text-[hsl(var(--muted-foreground))]" />
        <input v-model="search" type="search" :placeholder="t('job_search')" :aria-label="t('job_search')" class="h-9 w-full rounded-md border border-[hsl(var(--input))] bg-transparent pl-8 pr-3 text-sm" />
      </label>
      <FormSelect id="filter_group" v-model="groupFilter" :options="groupOptions" :aria-label="t('project_area')" class="w-44" />
      <label class="flex items-center gap-2 pb-2 text-sm">
        <input v-model="showInactive" type="checkbox" class="h-4 w-4 rounded border-[hsl(var(--input))]" />
        {{ t('project_show_inactive') }}
      </label>
      <a :href="`/projects/export?year=${year}`">
        <Button size="sm" variant="ghost"><Download class="mr-1 h-4 w-4" /> CSV</Button>
      </a>
    </div>

    <!-- Active filters and sort, as chips that can be removed (Notion-like). -->
    <div v-if="hasFilter || sort.key !== 'code' || sort.dir !== 'asc'" class="-mt-2 mb-4 flex flex-wrap items-center gap-2 text-sm">
      <span v-if="statusFilter.length" class="inline-flex items-center gap-1 rounded-full bg-[hsl(var(--muted))] px-3 py-1">
        {{ t('status') }}: {{ statusFilter.map(v => statusOptions.find(o => o.value === v)?.label ?? v).join(', ') }}
        <button type="button" :aria-label="t('column_filter_clear')" @click="statusFilter = []"><X class="h-3 w-3" /></button>
      </span>
      <span v-if="personFilter.length" class="inline-flex items-center gap-1 rounded-full bg-[hsl(var(--muted))] px-3 py-1">
        {{ t('project_shares') }}: {{ personFilter.map(nameOf).join(', ') }}
        <button type="button" :aria-label="t('column_filter_clear')" @click="personFilter = []"><X class="h-3 w-3" /></button>
      </span>
      <span v-if="sort.key !== 'code' || sort.dir !== 'asc'" class="inline-flex items-center gap-1 rounded-full bg-[hsl(var(--muted))] px-3 py-1">
        {{ t('job_sort') }}: {{ t(columnLabel[sort.key] ?? 'project') }} {{ sort.dir === 'desc' ? '↓' : '↑' }}
        <button type="button" :aria-label="t('column_filter_clear')" @click="sort = { key: 'code', dir: 'asc' }"><X class="h-3 w-3" /></button>
      </span>
      <button type="button" class="text-[hsl(var(--muted-foreground))] hover:underline" @click="clearFilters">{{ t('column_filter_reset') }}</button>
    </div>

    <EmptyState
      v-if="groups.length === 0"
      :icon="FolderKanban"
      :title="t('empty_projects_title')"
      :description="t('empty_projects_desc')"
      :action-label="can.manage ? t('job_new') : undefined"
      @action="openCreate()"
    />

    <!-- One head for all projects, like a Notion table: each column sorts
         and filters the whole list, and stays in reach while scrolling. -->
    <div class="sticky top-0 z-20 mb-2 rounded-lg border border-[hsl(var(--border))] bg-[hsl(var(--card))] shadow-sm">
      <table class="w-full table-fixed text-sm">
        <colgroup>
          <col>
          <col class="hidden w-32 sm:table-column">
          <col v-if="can.steer" class="hidden w-44 md:table-column">
          <col class="w-32 sm:w-36">
          <col class="hidden w-32 sm:table-column">
          <col class="w-28 sm:w-32">
          <col class="hidden w-24 md:table-column">
          <col class="hidden w-12 sm:table-column">
        </colgroup>
        <thead>
          <tr class="text-left text-xs text-[hsl(var(--muted-foreground))]">
            <th class="px-3 py-2 font-medium"><ColumnMenu :label="t('project')" column="code" :sort="sort" @sort="dir => setSort('code', dir)" /></th>
            <th class="hidden w-32 px-1 py-2 font-medium sm:table-cell">
              <ColumnMenu v-model:selected="statusFilter" :label="t('status')" column="status" :sort="sort" :options="statusOptions" @sort="dir => setSort('status', dir)" />
            </th>
            <th v-if="can.steer" class="hidden w-44 px-1 py-2 font-medium md:table-cell">
              <ColumnMenu v-model:selected="personFilter" :label="t('project_shares')" column="people" :sortable="false" :options="personOptions" />
            </th>
            <th class="w-32 px-1 py-2 text-right font-medium sm:w-36"><ColumnMenu :label="t('revenue')" column="revenue" align="right" :sort="sort" @sort="dir => setSort('revenue', dir)" /></th>
            <th class="hidden w-32 px-1 py-2 text-right font-medium sm:table-cell"><ColumnMenu :label="t('expenses')" column="expenses" align="right" :sort="sort" @sort="dir => setSort('expenses', dir)" /></th>
            <th class="w-28 px-1 py-2 text-right font-medium sm:w-32"><ColumnMenu :label="t('project_net')" column="net" align="right" :sort="sort" @sort="dir => setSort('net', dir)" /></th>
            <th class="hidden w-24 px-1 py-2 text-right font-medium md:table-cell"><ColumnMenu :label="t('job_margin')" column="margin" align="right" :sort="sort" @sort="dir => setSort('margin', dir)" /></th>
            <th class="hidden w-12 px-2 py-2 sm:table-cell"></th>
          </tr>
        </thead>
      </table>
    </div>

    <div class="space-y-4">
      <Card v-for="group in groups" :key="group.key">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-[hsl(var(--border))] px-4 py-3">
          <div class="flex items-center gap-2">
            <Link v-if="group.group" :href="jobHref(group.group)" class="font-semibold hover:underline" :class="group.group.is_active ? '' : 'line-through'">{{ group.group.name }}</Link>
            <span v-else class="font-semibold text-[hsl(var(--muted-foreground))]">{{ t('project_no_area') }}</span>
            <span class="text-xs text-[hsl(var(--muted-foreground))]">{{ t('job_count', { count: group.jobs.length }) }}</span>
            <template v-if="group.group && can.manage">
              <Button variant="ghost" size="icon" :aria-label="t('group_edit')" @click="openEdit(group.group)"><Pencil class="h-4 w-4" /></Button>
              <Button v-if="group.jobs.length === 0" variant="ghost" size="icon" :aria-label="t('delete')" @click="confirmDelete(group.group)"><Trash2 class="h-4 w-4 text-[hsl(var(--destructive))]" /></Button>
            </template>
          </div>
          <div class="flex items-center gap-4 text-sm tabular-nums">
            <span class="text-[hsl(var(--muted-foreground))]">{{ t('project_net') }}</span>
            <span class="font-semibold" :class="group.sum.net < 0 ? 'text-[hsl(var(--destructive))]' : ''">{{ formatCurrency(group.sum.net) }}</span>
            <span v-if="group.sum.expected" class="text-xs text-[hsl(var(--muted-foreground))]">{{ t('job_expected_hint', { amount: formatCurrency(group.sum.expected) }) }}</span>
            <Button v-if="can.manage" size="sm" variant="outline" @click="openCreate({ parent_id: group.group?.id ?? '' })"><Plus class="mr-1 h-3 w-3" /> {{ t('project') }}</Button>
          </div>
        </div>
        <div v-if="group.jobs.length">
          <!-- Same fixed widths as the shared head, so every column lines up. -->
          <table class="w-full table-fixed text-sm">
            <colgroup>
              <col>
              <col class="hidden w-32 sm:table-column">
              <col v-if="can.steer" class="hidden w-44 md:table-column">
              <col class="w-32 sm:w-36">
              <col class="hidden w-32 sm:table-column">
              <col class="w-28 sm:w-32">
              <col class="hidden w-24 md:table-column">
              <col class="hidden w-12 sm:table-column">
            </colgroup>
            <tbody class="divide-y divide-[hsl(var(--border))]">
              <tr
                v-for="job in group.jobs"
                :key="job.id"
                class="cursor-pointer hover:bg-[hsl(var(--muted))]/40"
                @click="router.visit(jobHref(job))"
              >
                <td class="px-4 py-2">
                  <Link :href="jobHref(job)" class="font-medium hover:underline" :class="job.is_active ? '' : 'text-[hsl(var(--muted-foreground))] line-through'" @click.stop>{{ job.name }}</Link>
                  <div class="text-xs text-[hsl(var(--muted-foreground))]">
                    {{ job.code }}<template v-if="job.customer"> · {{ job.customer }}</template><template v-if="job.kind === 'internal'"> · {{ t('project_kind_internal') }}</template>
                  </div>
                </td>
                <td class="hidden px-2 py-2 sm:table-cell"><Badge :variant="statusVariant[job.status] ?? 'default'">{{ statusLabel(job.status) }}</Badge></td>
                <td v-if="can.steer" class="hidden px-2 py-2 md:table-cell">
                  <div class="flex flex-wrap gap-1" :class="job.key.inherited ? 'opacity-60' : ''" :title="job.key.inherited ? t('job_people_inherited') : ''">
                    <span v-for="share in job.key.shares" :key="share.employee_id" class="rounded bg-[hsl(var(--muted))] px-1.5 py-0.5 text-xs whitespace-nowrap">
                      {{ initials(share.employee_id) }}<template v-if="Number(share.share) !== 100"> {{ Number(share.share) }}%</template>
                    </span>
                  </div>
                </td>
                <td class="px-2 py-2 text-right tabular-nums whitespace-nowrap">
                  {{ money(job.revenue) }}
                  <div v-if="!Number(job.revenue) && Number(job.expected)" class="text-xs text-[hsl(var(--muted-foreground))]">{{ t('job_expected_hint', { amount: formatCurrency(job.expected) }) }}</div>
                </td>
                <td class="hidden px-2 py-2 text-right tabular-nums whitespace-nowrap sm:table-cell">{{ money(job.expenses) }}</td>
                <td class="px-2 py-2 text-right font-medium tabular-nums whitespace-nowrap" :class="Number(job.net) < 0 ? 'text-[hsl(var(--destructive))]' : ''">{{ money(job.net) }}</td>
                <td class="hidden px-2 py-2 text-right tabular-nums md:table-cell">{{ margin(job) === null ? '' : `${margin(job)} %` }}</td>
                <td class="hidden px-2 py-2 text-right sm:table-cell" @click.stop>
                  <Button v-if="can.manage" variant="ghost" size="icon" :aria-label="t('edit')" @click="openEdit(job)"><Pencil class="h-4 w-4" /></Button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </Card>
    </div>

    <ProjectFormModal :open="showForm" :project="editing" :preset="preset" :options="formOptions" :can-steer="can.steer" @close="showForm = false" />

    <ConfirmDialog
      :open="showDelete"
      :title="t('confirm_delete')"
      :message="deleting ? deleting.name : ''"
      :processing="deleteProcessing"
      @confirm="performDelete"
      @cancel="showDelete = false"
    />
  </AppLayout>
</template>
