<script setup>
import AppLayout from '@/Components/AppLayout.vue'
import Card from '@/Components/UI/Card.vue'
import Badge from '@/Components/UI/Badge.vue'
import Button from '@/Components/UI/Button.vue'
import Breadcrumb from '@/Components/UI/Breadcrumb.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'
import FormSelect from '@/Components/UI/FormSelect.vue'
import SearchableSelect from '@/Components/UI/SearchableSelect.vue'
import ColumnMenu from '@/Components/Projects/ColumnMenu.vue'
import AllocationDialog from '@/Components/Projects/AllocationDialog.vue'
import { useTranslations } from '@/lib/useTranslations'
import { useFormatters } from '@/lib/useFormatters'
import { Link, router } from '@inertiajs/vue3'
import { computed, nextTick, ref, watch } from 'vue'
import { BookOpen, FileText, Pencil, Search, Split, X } from 'lucide-vue-next'

// The revenue and expense lines of a year as a journal, like Banana, with
// project, job and people on every line (spec 010). The job is changed in
// the line itself; a split opens the allocation dialog of the entry.
const props = defineProps({
  year: Number,
  rows: Array,
  projects: Array,
  employees: Array,
  generalCode: String,
  can: Object,
})

const { t } = useTranslations()
const { formatCurrency, formatDate } = useFormatters()

const yearOptions = computed(() => {
  const now = new Date().getFullYear()
  return [now + 1, now, now - 1, now - 2].map(y => ({ value: String(y), label: String(y) }))
})
function changeYear(year) {
  router.get('/projects/journal', { year }, { preserveScroll: true })
}

// Projects, jobs and who works on them
const byId = computed(() => Object.fromEntries(props.projects.map(p => [p.id, p])))
function groupOf(project) {
  let group = null
  let cursor = project?.parent_id ? byId.value[project.parent_id] : null
  for (let depth = 0; cursor && depth < 32; depth++) {
    if (cursor.kind === 'area') group = cursor
    cursor = cursor.parent_id ? byId.value[cursor.parent_id] : null
  }
  return project?.kind === 'area' ? project : group
}
function keyOf(project) {
  let cursor = project
  for (let depth = 0; cursor && depth < 32; depth++) {
    if (cursor.shares?.length) return cursor.shares
    cursor = cursor.parent_id ? byId.value[cursor.parent_id] : null
  }
  return []
}
const nameOf = id => props.employees.find(e => e.id === id)?.name ?? '?'
const initials = id => nameOf(id).split(' ').map(part => part[0]).join('').slice(0, 3).toUpperCase()

// What each row shows in the project, job and people columns.
const view = computed(() => props.rows.map((row) => {
  const jobs = row.allocations.filter(a => a.project_id).map(a => ({ ...a, project: byId.value[a.project_id] }))
  const first = jobs[0]?.project ?? null
  const isGeneral = row.category === 'general'
  const group = first && first.code !== props.generalCode ? groupOf(first) : null
  const people = new Map()
  for (const a of row.allocations) {
    if (a.employee_id) people.set(a.employee_id, Number(a.share))
    else if (a.project_id) keyOf(byId.value[a.project_id]).forEach(s => people.set(s.employee_id, Number(s.share)))
  }
  return {
    ...row,
    jobs,
    split: row.allocations.length > 1 || row.allocations.some(a => a.employee_id || Number(a.share) !== 100),
    projectKey: group ? String(group.id) : (isGeneral ? 'general' : (jobs.length ? 'none' : row.category)),
    projectLabel: group ? group.name : (isGeneral ? t('general_project_name') : (jobs.length ? t('project_no_area') : (row.category === 'payroll' ? t('journal_payroll') : ''))),
    projectHint: isGeneral && !jobs.length ? row.block : null,
    people: [...people.entries()].map(([employee_id, share]) => ({ employee_id, share })),
  }
}))

// Notion-like filters and sort, remembered per browser.
const FILTER_KEY = 'gaeld.projects.journal.filter'
function stored() {
  try {
    return JSON.parse(localStorage.getItem(FILTER_KEY) || '{}')
  } catch {
    return {}
  }
}
const saved = stored()
const list = value => (Array.isArray(value) ? value : [])
const search = ref('')
const accountFilter = ref(list(saved.accounts))
const projectFilter = ref(list(saved.projects))
const jobFilter = ref(list(saved.jobs))
const personFilter = ref(list(saved.people))
const defaultSort = { key: 'date', dir: 'asc' }
const sort = ref(saved.sort && typeof saved.sort === 'object' ? saved.sort : { ...defaultSort })
watch([accountFilter, projectFilter, jobFilter, personFilter, sort], () => {
  try {
    localStorage.setItem(FILTER_KEY, JSON.stringify({ accounts: accountFilter.value, projects: projectFilter.value, jobs: jobFilter.value, people: personFilter.value, sort: sort.value }))
  } catch {
    // Remembering the filter is a convenience only.
  }
}, { deep: true })

const accountOptions = computed(() => {
  const seen = new Map()
  props.rows.forEach(r => seen.set(r.account.code, `${r.account.code} ${r.account.name}`))
  return [...seen.entries()].sort(([a], [b]) => a.localeCompare(b, undefined, { numeric: true })).map(([value, label]) => ({ value, label }))
})
const projectOptions = computed(() => [
  { value: 'open', label: t('journal_open') },
  ...props.projects.filter(p => p.kind === 'area' && !p.parent_id).map(p => ({ value: String(p.id), label: p.name })),
  { value: 'none', label: t('project_no_area') },
  { value: 'general', label: t('general_project_name') },
  { value: 'payroll', label: t('journal_payroll') },
])
const jobOptions = computed(() => props.projects
  .filter(p => p.kind !== 'area' && props.rows.some(r => r.allocations.some(a => a.project_id === p.id)))
  .map(p => ({ value: p.id, label: `${p.code} ${p.name}` })))
const personOptions = computed(() => props.employees.map(e => ({ value: e.id, label: e.name })))
const labelOf = (options, value) => options.find(o => o.value === value)?.label ?? value

const columnLabel = { date: 'date', reference: 'reference', description: 'description', amount: 'amount', project: 'project_area', job: 'project' }
const sortValue = {
  date: r => r.date,
  reference: r => r.reference ?? '',
  description: r => r.description ?? '',
  amount: r => Number(r.net),
  project: r => r.projectLabel || '~',
  job: r => r.jobs[0]?.project?.name ?? '~',
}
function compare(a, b) {
  const get = sortValue[sort.value.key] ?? sortValue.date
  const [x, y] = [get(a), get(b)]
  const order = typeof x === 'string' ? x.localeCompare(y, undefined, { numeric: true }) : x - y
  return (sort.value.dir === 'desc' ? -order : order) || a.line_id - b.line_id
}

const filtered = computed(() => {
  const q = search.value.trim().toLowerCase()
  return view.value
    .filter(r => !accountFilter.value.length || accountFilter.value.includes(r.account.code))
    .filter(r => !projectFilter.value.length || projectFilter.value.includes(r.projectKey))
    .filter(r => !jobFilter.value.length || r.jobs.some(j => jobFilter.value.includes(j.project_id)))
    .filter(r => !personFilter.value.length || r.people.some(p => personFilter.value.includes(p.employee_id)))
    .filter(r => !q || [r.reference, r.description, r.account.code, r.account.name, r.debit_account, r.credit_account, r.projectLabel, r.block, ...r.jobs.map(j => `${j.project?.code} ${j.project?.name}`)]
      .some(v => (v ?? '').toLowerCase().includes(q)))
    .sort(compare)
})
const totals = computed(() => filtered.value.reduce((s, r) => {
  const net = Number(r.net)
  return { revenue: s.revenue + (r.type === 'revenue' ? net : 0), expense: s.expense + (r.type === 'revenue' ? 0 : -net), net: s.net + net }
}, { revenue: 0, expense: 0, net: 0 }))

const PAGE = 300
const shown = ref(PAGE)
watch(filtered, () => { shown.value = PAGE })
const visible = computed(() => filtered.value.slice(0, shown.value))

const hasFilter = computed(() => [accountFilter, projectFilter, jobFilter, personFilter].some(f => f.value.length > 0) || search.value !== '')
const sorted = computed(() => sort.value.key !== defaultSort.key || sort.value.dir !== defaultSort.dir)
function clearFilters() {
  accountFilter.value = []
  projectFilter.value = []
  jobFilter.value = []
  personFilter.value = []
  search.value = ''
  sort.value = { ...defaultSort }
}

// Change the job right in the line: pick a job, "General" or nothing.
const GENERAL = '__general'
const NONE = '__none'
const OTHER = () => [
  { value: GENERAL, label: t('general_project_name'), group: t('journal_pick_other') },
  { value: NONE, label: t('journal_pick_none'), group: t('journal_pick_other') },
]
const activeJobs = computed(() => props.projects.filter(p => p.is_active && p.kind !== 'area' && p.code !== props.generalCode))
// Right after a project was chosen, the job picker offers only its jobs.
const jobArea = ref(null)
const pickerOptions = computed(() => [
  ...OTHER(),
  ...activeJobs.value
    .filter(p => jobArea.value === null || groupOf(p)?.id === jobArea.value)
    .map(p => ({ value: p.id, label: `${p.name} (${p.code})`, group: groupOf(p)?.name ?? t('project_no_area') })),
])
// The project of a line: any active project (area); the line goes to the
// project itself until a job is chosen.
const areaOptions = computed(() => [
  ...OTHER(),
  ...props.projects
    .filter(p => p.is_active && p.kind === 'area' && p.code !== props.generalCode)
    .map(p => ({ value: p.id, label: `${p.name} (${p.code})`, group: t('project_area') })),
])
const editingArea = ref(null)
const areaPicker = ref(null)
const mayChangeArea = row => props.can.allocate && !row.split && row.category !== 'payroll'
async function editArea(row) {
  if (!mayChangeArea(row)) return
  editing.value = null
  editingArea.value = row.line_id
  await nextTick()
  areaPicker.value?.openDropdown?.()
}
function saveArea(row, value) {
  editingArea.value = null
  if (value === null || value === undefined || value === '' || String(value) === row.projectKey) return
  if (value === GENERAL || value === NONE) {
    if (value === GENERAL && row.projectKey === 'general') return
    save(row, value)
    return
  }
  saving.value = row.line_id
  router.post('/projects/unallocated', { items: [{ line_id: row.line_id, project_id: Number(value) }] }, {
    preserveScroll: true,
    preserveState: true,
    onSuccess: async () => {
      if (!activeJobs.value.some(p => groupOf(p)?.id === Number(value))) return
      jobArea.value = Number(value)
      editing.value = row.line_id
      await nextTick()
      picker.value?.openDropdown?.()
    },
    onFinish: () => { saving.value = null },
  })
}
const editing = ref(null)
const picker = ref(null)
const saving = ref(null)
async function edit(row) {
  if (!props.can.allocate) return
  jobArea.value = null
  editingArea.value = null
  if (row.split) {
    openSplit(row)
    return
  }
  editing.value = row.line_id
  await nextTick()
  picker.value?.openDropdown?.()
}
function save(row, value) {
  editing.value = null
  jobArea.value = null
  if (value === null || value === undefined || value === '' || value === row.jobs[0]?.project_id) return
  const options = { preserveScroll: true, preserveState: true, onFinish: () => { saving.value = null } }
  saving.value = row.line_id
  if (value === GENERAL) {
    router.post('/projects/unallocated/general', { line_id: row.line_id, scope: 'line', year: props.year }, options)
  } else if (value === NONE) {
    if (!row.allocations.length) {
      saving.value = null
      return
    }
    router.put(`/accounting/journal-entries/${row.entry_id}/allocations`, { lines: [{ id: row.line_id, allocations: [] }] }, options)
  } else {
    router.post('/projects/unallocated', { items: [{ line_id: row.line_id, project_id: Number(value) }] }, options)
  }
}

// Split a line across jobs and people: the allocation dialog of its entry,
// with the result lines of that entry.
const splitEntry = ref(null)
const dialogOptions = computed(() => ({ projects: props.projects, employees: props.employees }))
function openSplit(row) {
  splitEntry.value = {
    id: row.entry_id,
    reference: row.reference,
    description: row.description,
    lines: props.rows.filter(r => r.entry_id === row.entry_id).map(r => ({
      id: r.line_id,
      account: r.account,
      description: r.description,
      debit: r.debit_account === r.account.code ? r.amount : '0',
      credit: r.debit_account === r.account.code ? '0' : r.amount,
      allocations: r.allocations,
    })),
  }
}

const categoryVariant = { open: 'warning', payroll: 'secondary' }
</script>

<template>
  <AppLayout :title="t('project_journal')">
    <Breadcrumb :items="[{ label: t('jobs'), href: `/projects?year=${year}` }, { label: t('project_journal') }]" class="mb-4" />

    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
      <div class="flex items-end gap-3">
        <h1 class="text-2xl font-semibold">{{ t('project_journal') }}</h1>
        <FormSelect id="year" :model-value="String(year)" :options="yearOptions" :aria-label="t('year')" class="w-28" @update:model-value="changeYear" />
      </div>
      <p class="max-w-2xl text-sm text-[hsl(var(--muted-foreground))]">{{ t('project_journal_desc') }}</p>
    </div>

    <EmptyState v-if="rows.length === 0" :icon="BookOpen" :title="t('project_journal_empty')" />

    <template v-else>
      <div class="mb-3 flex flex-wrap items-center gap-2 text-sm">
        <label class="relative min-w-[14rem] flex-1 sm:max-w-sm">
          <Search class="pointer-events-none absolute left-2.5 top-2.5 h-4 w-4 text-[hsl(var(--muted-foreground))]" />
          <input v-model="search" type="search" :placeholder="t('search')" :aria-label="t('search')" class="h-9 w-full rounded-md border border-[hsl(var(--input))] bg-transparent pl-8 pr-3 text-sm" />
        </label>
        <span v-if="accountFilter.length" class="inline-flex items-center gap-1 rounded-full bg-[hsl(var(--muted))] px-3 py-1">
          {{ t('account') }}: {{ accountFilter.join(', ') }}
          <button type="button" :aria-label="t('column_filter_clear')" @click="accountFilter = []"><X class="h-3 w-3" /></button>
        </span>
        <span v-if="projectFilter.length" class="inline-flex items-center gap-1 rounded-full bg-[hsl(var(--muted))] px-3 py-1">
          {{ t('project_area') }}: {{ projectFilter.map(v => labelOf(projectOptions, v)).join(', ') }}
          <button type="button" :aria-label="t('column_filter_clear')" @click="projectFilter = []"><X class="h-3 w-3" /></button>
        </span>
        <span v-if="jobFilter.length" class="inline-flex items-center gap-1 rounded-full bg-[hsl(var(--muted))] px-3 py-1">
          {{ t('project') }}: {{ jobFilter.map(v => labelOf(jobOptions, v)).join(', ') }}
          <button type="button" :aria-label="t('column_filter_clear')" @click="jobFilter = []"><X class="h-3 w-3" /></button>
        </span>
        <span v-if="personFilter.length" class="inline-flex items-center gap-1 rounded-full bg-[hsl(var(--muted))] px-3 py-1">
          {{ t('project_shares') }}: {{ personFilter.map(v => labelOf(personOptions, v)).join(', ') }}
          <button type="button" :aria-label="t('column_filter_clear')" @click="personFilter = []"><X class="h-3 w-3" /></button>
        </span>
        <span v-if="sorted" class="inline-flex items-center gap-1 rounded-full bg-[hsl(var(--muted))] px-3 py-1">
          {{ t('job_sort') }}: {{ t(columnLabel[sort.key] ?? 'date') }} {{ sort.dir === 'desc' ? '↓' : '↑' }}
          <button type="button" :aria-label="t('column_filter_clear')" @click="sort = { ...defaultSort }"><X class="h-3 w-3" /></button>
        </span>
        <button v-if="hasFilter || sorted" type="button" class="text-[hsl(var(--muted-foreground))] hover:underline" @click="clearFilters">{{ t('column_filter_reset') }}</button>
      </div>

      <Card>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="sticky top-0 z-10 bg-[hsl(var(--card))]">
              <tr class="border-b border-[hsl(var(--border))] text-left text-xs text-[hsl(var(--muted-foreground))]">
                <th class="px-3 py-2 font-medium"><ColumnMenu :label="t('date')" column="date" :sort="sort" @sort="dir => sort = { key: 'date', dir }" /></th>
                <th class="hidden px-2 py-2 font-medium 2xl:table-cell"><ColumnMenu :label="t('reference')" column="reference" :sort="sort" @sort="dir => sort = { key: 'reference', dir }" /></th>
                <th class="px-2 py-2 font-medium"><ColumnMenu :label="t('description')" column="description" :sort="sort" @sort="dir => sort = { key: 'description', dir }" /></th>
                <th class="hidden px-2 py-2 font-medium lg:table-cell"><ColumnMenu v-model:selected="accountFilter" :label="t('debit')" column="debit" :sortable="false" :options="accountOptions" /></th>
                <th class="hidden px-2 py-2 font-medium lg:table-cell"><ColumnMenu v-model:selected="accountFilter" :label="t('credit')" column="credit" :sortable="false" :options="accountOptions" /></th>
                <th class="px-2 py-2 text-right font-medium"><ColumnMenu :label="t('amount')" column="amount" align="right" :sort="sort" @sort="dir => sort = { key: 'amount', dir }" /></th>
                <th class="hidden px-2 py-2 font-medium sm:table-cell"><ColumnMenu v-model:selected="projectFilter" :label="t('project_area')" column="project" :sort="sort" :options="projectOptions" @sort="dir => sort = { key: 'project', dir }" /></th>
                <th class="px-2 py-2 font-medium"><ColumnMenu v-model:selected="jobFilter" :label="t('project')" column="job" :sort="sort" :options="jobOptions" @sort="dir => sort = { key: 'job', dir }" /></th>
                <th v-if="can.steer" class="hidden whitespace-nowrap px-2 py-2 text-right font-medium md:table-cell"><ColumnMenu v-model:selected="personFilter" :label="t('project_shares')" column="people" :sortable="false" :options="personOptions" align="right" /></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-[hsl(var(--border))]">
              <tr v-for="row in visible" :key="row.line_id" :class="saving === row.line_id ? 'opacity-50' : ''">
                <td class="px-3 py-1.5 whitespace-nowrap tabular-nums">{{ formatDate(row.date) }}</td>
                <td class="hidden px-2 py-1.5 2xl:table-cell">
                  <Link :href="`/accounting/journal-entries/${row.entry_id}`" class="block max-w-[9rem] truncate font-mono text-xs hover:underline" :title="row.reference">{{ row.reference || t('journal_entry') }}</Link>
                </td>
                <td class="max-w-[15rem] px-2 py-1.5">
                  <div class="truncate" :title="row.description">{{ row.description }}<a v-if="row.document_url" :href="row.document_url" target="_blank" rel="noopener noreferrer" class="ml-1 inline-flex align-middle text-[hsl(var(--muted-foreground))] hover:text-[hsl(var(--primary))]" :title="t('journal_entry_document_open')" :aria-label="t('journal_entry_document_open')"><FileText class="h-3.5 w-3.5" /></a></div>
                  <div class="truncate text-xs text-[hsl(var(--muted-foreground))] 2xl:hidden">
                    <Link :href="`/accounting/journal-entries/${row.entry_id}`" class="font-mono hover:underline">{{ row.reference || t('journal_entry') }}</Link><span class="lg:hidden"> · {{ row.debit_account }} / {{ row.credit_account }}</span>
                  </div>
                </td>
                <td class="hidden px-2 py-1.5 font-mono text-xs lg:table-cell" :title="row.debit_account === row.account.code ? row.account.name : ''">{{ row.debit_account }}</td>
                <td class="hidden px-2 py-1.5 font-mono text-xs lg:table-cell" :title="row.credit_account === row.account.code ? row.account.name : ''">{{ row.credit_account }}</td>
                <td class="px-2 py-1.5 text-right tabular-nums whitespace-nowrap" :class="Number(row.net) > 0 ? 'text-green-700 dark:text-green-400' : ''">{{ formatCurrency(row.net) }}</td>
                <td class="hidden max-w-[10rem] px-2 py-1 sm:table-cell">
                  <SearchableSelect
                    v-if="editingArea === row.line_id"
                    :ref="el => { areaPicker = el }"
                    :model-value="/^\d+$/.test(row.projectKey) ? Number(row.projectKey) : null"
                    :options="areaOptions"
                    group-key="group"
                    :placeholder="t('journal_pick_project')"
                    force-searchable
                    @update:model-value="v => saveArea(row, v)"
                  />
                  <button
                    v-else
                    type="button"
                    class="group flex w-full min-w-0 items-center gap-1 rounded px-1 py-0.5 text-left"
                    :class="mayChangeArea(row) ? 'hover:bg-[hsl(var(--muted))]' : 'cursor-default'"
                    :disabled="!mayChangeArea(row)"
                    :title="mayChangeArea(row) ? t('journal_pick_project') : ''"
                    @click="editArea(row)"
                  >
                    <span class="min-w-0 flex-1">
                      <Badge v-if="row.category === 'open'" :variant="categoryVariant.open">{{ t('journal_open') }}</Badge>
                      <template v-else>
                        <span class="block truncate" :class="row.category === 'payroll' ? 'text-[hsl(var(--muted-foreground))]' : ''" :title="row.projectLabel">{{ row.projectLabel }}</span>
                        <span v-if="row.projectHint" :title="row.projectHint" class="block truncate text-xs text-[hsl(var(--muted-foreground))]">{{ row.projectHint }}</span>
                      </template>
                    </span>
                    <Pencil v-if="mayChangeArea(row)" class="h-3 w-3 shrink-0 opacity-0 group-hover:opacity-60" />
                  </button>
                </td>
                <td class="w-48 max-w-[12rem] px-2 py-1">
                  <SearchableSelect
                    v-if="editing === row.line_id"
                    :ref="el => { picker = el }"
                    :model-value="row.jobs[0]?.project_id ?? null"
                    :options="pickerOptions"
                    group-key="group"
                    :placeholder="t('unallocated_choose')"
                    force-searchable
                    @update:model-value="v => save(row, v)"
                  />
                  <div v-else class="group flex items-center gap-1">
                    <button
                      type="button"
                      class="flex min-w-0 flex-1 items-center gap-1 rounded px-1 py-0.5 text-left"
                      :class="can.allocate ? 'hover:bg-[hsl(var(--muted))]' : 'cursor-default'"
                      :disabled="!can.allocate"
                      @click="edit(row)"
                    >
                      <span v-if="row.split" class="truncate">
                        <Split class="mr-1 inline h-3 w-3" />{{ row.jobs.map(j => `${j.project?.code ?? '?'} ${Number(j.share)}%`).join(', ') || row.allocations.map(a => `${initials(a.employee_id)} ${Number(a.share)}%`).join(', ') }}
                      </span>
                      <span v-else-if="row.jobs.length && row.jobs[0].project?.kind !== 'area'" class="truncate" :title="row.jobs[0].project?.code">{{ row.jobs[0].project?.name ?? '?' }}</span>
                      <span v-else-if="can.allocate && row.category !== 'payroll'" class="truncate text-[hsl(var(--muted-foreground))]">{{ t('unallocated_choose') }}</span>
                      <span v-else class="text-[hsl(var(--muted-foreground))]">·</span>
                      <Pencil v-if="can.allocate" class="ml-auto h-3 w-3 shrink-0 opacity-0 group-hover:opacity-60" />
                    </button>
                    <Button v-if="can.allocate && !row.split" variant="ghost" size="icon" class="h-7 w-7 shrink-0 opacity-40 group-hover:opacity-100 focus:opacity-100" :aria-label="t('journal_split')" :title="t('journal_split')" @click="openSplit(row)"><Split class="h-3.5 w-3.5" /></Button>
                  </div>
                </td>
                <td v-if="can.steer" class="hidden px-2 py-1.5 md:table-cell">
                  <div class="flex justify-end gap-1 whitespace-nowrap">
                    <span v-for="p in row.people" :key="p.employee_id" class="rounded bg-[hsl(var(--muted))] px-1.5 py-0.5 text-xs whitespace-nowrap" :title="nameOf(p.employee_id)">
                      {{ initials(p.employee_id) }}<template v-if="p.share !== 100"> {{ p.share }}%</template>
                    </span>
                  </div>
                </td>
              </tr>
              <tr v-if="filtered.length === 0">
                <td colspan="9" class="py-6 text-center text-[hsl(var(--muted-foreground))]">{{ t('journal_none_match') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="filtered.length > shown" class="border-t border-[hsl(var(--border))] p-3 text-center">
          <Button variant="outline" size="sm" @click="shown += PAGE">{{ t('journal_show_more', { count: filtered.length - shown }) }}</Button>
        </div>
        <div class="flex flex-wrap justify-end gap-x-6 gap-y-1 border-t border-[hsl(var(--border))] px-4 py-3 text-sm tabular-nums">
          <span class="text-[hsl(var(--muted-foreground))]">{{ t('journal_lines_count', { count: filtered.length }) }}</span>
          <span>{{ t('revenue') }} <strong>{{ formatCurrency(totals.revenue) }}</strong></span>
          <span>{{ t('expenses') }} <strong>{{ formatCurrency(totals.expense) }}</strong></span>
          <span>{{ t('project_net') }} <strong :class="totals.net < 0 ? 'text-[hsl(var(--destructive))]' : ''">{{ formatCurrency(totals.net) }}</strong></span>
        </div>
      </Card>
    </template>

    <AllocationDialog :open="!!splitEntry" :entry="splitEntry" :options="dialogOptions" @close="splitEntry = null" />
  </AppLayout>
</template>
