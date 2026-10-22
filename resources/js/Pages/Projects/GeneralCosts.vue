<script setup>
import AppLayout from '@/Components/AppLayout.vue'
import Card from '@/Components/UI/Card.vue'
import CardHeader from '@/Components/UI/CardHeader.vue'
import CardTitle from '@/Components/UI/CardTitle.vue'
import CardContent from '@/Components/UI/CardContent.vue'
import Button from '@/Components/UI/Button.vue'
import Breadcrumb from '@/Components/UI/Breadcrumb.vue'
import FormSelect from '@/Components/UI/FormSelect.vue'
import FormInput from '@/Components/UI/FormInput.vue'
import Badge from '@/Components/UI/Badge.vue'
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue'
import BudgetGroupFormModal from '@/Components/Projects/BudgetGroupFormModal.vue'
import AllocationDialog from '@/Components/Projects/AllocationDialog.vue'
import { useTranslations } from '@/lib/useTranslations'
import { useFormatters } from '@/lib/useFormatters'
import { router, useForm } from '@inertiajs/vue3'
import { computed, reactive, ref, watch } from 'vue'
import { ChevronDown, ChevronRight, Copy, Download, FolderKanban, Pencil, Plus, Trash2 } from 'lucide-vue-next'

// The general costs on a page of their own: each cost block, plan against
// actual, who bears it and who is credited. Blocks are added and changed by
// managers (canManage); the keys stay with owners (canSteer).
const props = defineProps({
  year: Number,
  canSteer: { type: Boolean, default: false },
  canManage: { type: Boolean, default: false },
  hasPreviousYear: { type: Boolean, default: false },
  elapsed: { type: [String, Number], default: 0 },
  steering: Object,
  employees: Array,
  groups: { type: Array, default: () => [] },
  blockOptions: { type: Object, default: () => ({ accounts: [], kinds: [] }) },
  allocationOptions: { type: Object, default: null },
  jobOptions: { type: Array, default: () => [] },
})

const { t } = useTranslations()
const { formatCurrency, formatDate } = useFormatters()

const people = computed(() => props.steering.people)
const yearOptions = computed(() => {
  const now = new Date().getFullYear()
  return [now + 1, now, now - 1, now - 2].map(y => ({ value: String(y), label: String(y) }))
})
const num = v => Number(v ?? 0)
const open = reactive({})
const toggle = key => { open[key] = !open[key] }
const totalOf = id => props.steering.blocks.reduce((sum, b) => sum + num(b.people[id]?.effect), 0)
// What is booked so far, after the part moved to customer jobs; this is
// what the people bear.
const actualTotal = computed(() => props.steering.blocks.reduce((sum, b) => sum + num(b.actual), 0))

// An open block lists the bookings behind its actual, loaded on first open
// and again after every change of the page data (an allocation moves the
// numbers). From here a booking is moved to a job or a person.
const lines = ref({})
async function loadLines(id) {
  if (lines.value[id] === undefined) lines.value = { ...lines.value, [id]: 'loading' }
  try {
    const response = await fetch(`/projects/plan/${id}/lines`, { headers: { Accept: 'application/json' } })
    if (!response.ok) throw new Error(String(response.status))
    lines.value = { ...lines.value, [id]: (await response.json()).rows }
  } catch {
    lines.value = { ...lines.value, [id]: 'error' }
  }
}
function toggleBlock(block) {
  toggle('block-' + block.id)
  if (open['block-' + block.id] && !Array.isArray(lines.value[block.id])) loadLines(block.id)
}
watch(() => props.steering, () => {
  for (const block of props.steering.blocks) {
    if (open['block-' + block.id]) loadLines(block.id)
    else delete lines.value[block.id]
  }
})

const allocating = ref(null)
function allocate(line) {
  allocating.value = {
    id: line.entry_id,
    reference: line.reference,
    description: line.description,
    lines: [{
      id: line.line_id,
      account: { code: line.account, name: line.account_name },
      description: line.description,
      debit: line.debit,
      credit: line.credit,
      allocations: line.allocations,
    }],
  }
}

// Several bookings of a block to one job at once; what they carried so far
// is replaced, as they were picked for exactly that.
const selected = reactive({})
const selectedIn = id => Object.keys(selected[id] ?? {}).filter(k => selected[id][k])
function toggleLine(id, line) {
  selected[id] = { ...(selected[id] ?? {}), [line.line_id]: !selected[id]?.[line.line_id] }
}
function toggleAll(id) {
  const rows = Array.isArray(lines.value[id]) ? lines.value[id] : []
  const all = rows.length > 0 && selectedIn(id).length === rows.length
  selected[id] = Object.fromEntries(rows.map(r => [r.line_id, !all]))
}
const bulkProject = reactive({})
const bulkBusy = ref(false)
const projectOptions = computed(() => [
  { value: '', label: t('select_placeholder') },
  ...(props.allocationOptions?.projects ?? [])
    .filter(p => p.is_active)
    .map(p => ({ value: String(p.id), label: `${'· '.repeat(p.depth)}${p.code} ${p.name}` })),
])
function allocateSelected(id) {
  const ids = selectedIn(id).map(Number)
  const rows = (lines.value[id] ?? []).filter(r => ids.includes(r.line_id))
  if (!bulkProject[id] || rows.length === 0) return
  bulkBusy.value = true
  router.post('/accounting/journal-entry-allocations/bulk', {
    entry_ids: [...new Set(rows.map(r => r.entry_id))],
    line_ids: ids,
    project_id: Number(bulkProject[id]),
    overwrite: true,
  }, {
    preserveScroll: true,
    onSuccess: () => { selected[id] = {}; bulkProject[id] = '' },
    onFinish: () => { bulkBusy.value = false },
  })
}

// Plan against actual of a block: kind, rest, projection, progress.
const groupOf = id => props.groups.find(g => g.id === id) ?? {}
const kindLabel = kind => props.blockOptions.kinds.find(k => k.value === kind)?.label ?? kind
const elapsedPercent = computed(() => Math.round(Number(props.elapsed ?? 0) * 100))

const showBlockForm = ref(false)
const editingBlock = ref(null)
function editBlock(block = null) {
  editingBlock.value = block ? (props.groups.find(g => g.id === block.id) ?? null) : null
  if (!block || editingBlock.value) showBlockForm.value = true
}
const deletingBlock = ref(null)
function performDeleteBlock() {
  router.delete(`/projects/plan/${deletingBlock.value.id}`, { preserveScroll: true, onFinish: () => { deletingBlock.value = null } })
}
// Internal settlements: one person pays another for work done for everyone,
// optionally for a job. Owners add, change and remove them.
const settlements = computed(() => props.steering.settlements ?? [])
const nameOf = id => people.value.find(p => p.employee_id === id)?.name ?? props.employees.find(e => e.id === id)?.name ?? '?'
const settledOf = id => settlements.value.reduce((sum, s) => sum + (s.to_employee_id === id ? num(s.amount) : 0) - (s.from_employee_id === id ? num(s.amount) : 0), 0)
const personOptions = computed(() => props.employees.map(e => ({ value: e.id, label: e.name })))
const settlementJobOptions = computed(() => [
  { value: '', label: t('settlement_no_job') },
  ...props.jobOptions.filter(p => p.is_active).map(p => ({ value: String(p.id), label: `${'· '.repeat(p.depth)}${p.code} ${p.name}` })),
])
const blankSettlement = () => ({
  year: props.year,
  label: '',
  from_employee_id: props.employees[1]?.id ?? '',
  to_employee_id: props.employees[0]?.id ?? '',
  cost_center_id: '',
  amount: '',
})
const settlementForm = useForm(blankSettlement())
const editingSettlement = ref(null)
function editSettlement(row) {
  editingSettlement.value = row
  settlementForm.clearErrors()
  Object.assign(settlementForm, {
    year: props.year,
    label: row.label,
    from_employee_id: row.from_employee_id,
    to_employee_id: row.to_employee_id,
    cost_center_id: row.cost_center_id ? String(row.cost_center_id) : '',
    amount: row.amount,
  })
}
function resetSettlement() {
  editingSettlement.value = null
  settlementForm.clearErrors()
  Object.assign(settlementForm, blankSettlement())
}
function saveSettlement() {
  const options = { preserveScroll: true, onSuccess: resetSettlement }
  settlementForm.transform(data => ({ ...data, cost_center_id: data.cost_center_id ? Number(data.cost_center_id) : null }))
  if (editingSettlement.value) settlementForm.put(`/projects/settlements/${editingSettlement.value.id}`, options)
  else settlementForm.post('/projects/settlements', options)
}
const deletingSettlement = ref(null)
function performDeleteSettlement() {
  router.delete(`/projects/settlements/${deletingSettlement.value.id}`, { preserveScroll: true, onFinish: () => { deletingSettlement.value = null } })
}

function copyPreviousBlocks() {
  router.post('/projects/plan/copy-previous', { year: props.year }, { preserveScroll: true })
}
</script>

<template>
  <AppLayout :title="t('general_costs')">
    <Breadcrumb :items="[{ label: t('jobs'), href: `/projects?year=${year}` }, { label: t('steering'), href: `/projects/steering?year=${year}` }, { label: t('general_costs') }]" class="mb-4" />
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
      <h1 class="text-xl font-semibold">{{ t('general_costs') }} {{ year }}</h1>
      <FormSelect id="year" :model-value="String(year)" :label="t('year')" :options="yearOptions" @update:model-value="v => router.get('/projects/general-costs', { year: v })" />
    </div>
      <Card>
        <CardHeader class="flex flex-row flex-wrap items-center justify-between gap-2">
          <CardTitle>{{ t('steering_blocks') }}</CardTitle>
          <div class="flex flex-wrap items-center gap-2">
            <a :href="`/projects/plan/export?year=${year}`"><Button size="sm" variant="ghost"><Download class="mr-1 h-4 w-4" /> CSV</Button></a>
            <Button v-if="canManage && hasPreviousYear && steering.blocks.length === 0" size="sm" variant="outline" @click="copyPreviousBlocks"><Copy class="mr-1 h-4 w-4" /> {{ t('budget_groups_copy_previous') }}</Button>
            <Button v-if="canManage" size="sm" variant="outline" @click="editBlock()"><Plus class="mr-1 h-3 w-3" /> {{ t('budget_group_new') }}</Button>
          </div>
        </CardHeader>
        <CardContent>
          <p class="mb-3 text-sm text-[hsl(var(--muted-foreground))]">{{ t('steering_blocks_desc') }} {{ t('general_costs_elapsed', { percent: elapsedPercent }) }}</p>
          <p v-if="steering.blocks.length === 0" class="py-4 text-sm text-[hsl(var(--muted-foreground))]">{{ t('budget_groups_empty_desc') }}</p>
          <div v-else class="overflow-x-auto">
            <table class="w-full text-sm [&_td]:pr-3 [&_th]:pr-3">
              <thead>
                <tr class="border-b text-left text-[hsl(var(--muted-foreground))]">
                  <th class="pb-2">{{ t('name') }}</th>
                  <th class="pb-2 text-right">{{ t('budget_group_plan') }}</th>
                  <th class="pb-2 text-right">{{ t('budget_group_actual') }}</th>
                  <th v-for="person in people" :key="person.employee_id" class="pb-2 text-right">{{ person.name }}</th>
                  <th class="w-20 pb-2"></th>
                </tr>
              </thead>
              <tbody class="divide-y divide-[hsl(var(--border))]">
                <template v-for="block in steering.blocks" :key="block.id">
                  <tr class="cursor-pointer hover:bg-[hsl(var(--muted))]/40" @click="toggleBlock(block)">
                    <td class="py-1">
                      <span class="flex items-center gap-1">
                        <component :is="open['block-' + block.id] ? ChevronDown : ChevronRight" class="h-4 w-4 shrink-0" />
                        {{ block.name }}
                      </span>
                      <span class="block pl-5 text-xs text-[hsl(var(--muted-foreground))]">
                        {{ kindLabel(groupOf(block.id).kind) }}<template v-if="num(groupOf(block.id).customer_projects) !== 0"> · {{ t('budget_group_customer_projects_short', { amount: formatCurrency(groupOf(block.id).customer_projects) }) }}</template>
                      </span>
                    </td>
                    <td class="py-1 text-right tabular-nums">{{ formatCurrency(block.plan) }}</td>
                    <td class="py-1 text-right tabular-nums">
                      <Badge v-if="groupOf(block.id).over_plan" variant="destructive">{{ formatCurrency(block.actual) }}</Badge>
                      <template v-else>{{ formatCurrency(block.actual) }}</template>
                      <div v-if="groupOf(block.id).progress != null" class="ml-auto mt-1 h-1.5 w-20 rounded bg-[hsl(var(--muted))]">
                        <div class="h-1.5 rounded" :class="groupOf(block.id).progress > 100 ? 'bg-[hsl(var(--destructive))]' : 'bg-[hsl(var(--primary))]'" :style="{ width: `${Math.min(groupOf(block.id).progress, 100)}%` }" />
                      </div>
                    </td>
                    <td v-for="person in people" :key="person.employee_id" class="py-1 text-right tabular-nums">
                      {{ formatCurrency(block.people[person.employee_id]?.effect ?? 0) }}
                    </td>
                    <td class="py-1 text-right" @click.stop>
                      <span v-if="canManage" class="flex justify-end">
                        <Button variant="ghost" size="icon" :aria-label="t('edit')" @click="editBlock(block)"><Pencil class="h-4 w-4" /></Button>
                        <Button variant="ghost" size="icon" :aria-label="t('delete')" @click="deletingBlock = block"><Trash2 class="h-4 w-4 text-[hsl(var(--destructive))]" /></Button>
                      </span>
                    </td>
                  </tr>
                  <template v-if="open['block-' + block.id]">
                    <tr class="text-xs text-[hsl(var(--muted-foreground))]">
                      <td class="py-1 pl-5" :colspan="people.length + 4">
                        <span :class="num(groupOf(block.id).rest) < 0 ? 'text-[hsl(var(--destructive))]' : ''">{{ t('budget_group_rest') }} {{ formatCurrency(groupOf(block.id).rest ?? 0) }}</span>
                        · {{ t('budget_group_projection') }} {{ formatCurrency(groupOf(block.id).projection ?? 0) }}
                      </td>
                    </tr>
                    <tr class="text-xs text-[hsl(var(--muted-foreground))]">
                      <td class="py-1 pl-5" colspan="3">
                        <template v-if="block.plan_fixed">{{ t('steering_block_plan_fixed') }}</template>
                        <template v-else-if="block.accounts?.length">
                          <template v-for="a in block.accounts" :key="a.code">
                            <div class="flex justify-between gap-3">
                              <span>{{ a.code }} {{ a.name }}: {{ t('steering_block_monthly', { amount: formatCurrency(a.monthly) }) }}</span>
                              <span class="tabular-nums">{{ formatCurrency(a.budget_yearly ?? a.yearly) }}</span>
                            </div>
                            <div v-for="r in a.recurring ?? []" :key="r.id" class="flex justify-between gap-3 pl-3">
                              <a :href="`/expenses/recurring/${r.uuid}/edit`" class="hover:underline" @click.stop>{{ t('steering_block_recurring', { label: r.label, count: r.count, amount: formatCurrency(r.amount) }) }}</a>
                              <span class="tabular-nums">{{ formatCurrency(r.yearly) }}</span>
                            </div>
                          </template>
                        </template>
                        <template v-else>{{ t('steering_block_plan_none') }}</template>
                      </td>
                      <td :colspan="people.length + 1"></td>
                    </tr>
                    <tr class="text-xs text-[hsl(var(--muted-foreground))]">
                      <td class="py-1 pl-5" colspan="3">{{ block.carry_equal ? t('steering_block_carry_equal') : t('steering_block_carry') }}</td>
                      <td v-for="person in people" :key="person.employee_id" class="py-1 text-right tabular-nums">
                        {{ num(block.people[person.employee_id]?.carry_share) }} % · {{ formatCurrency(block.people[person.employee_id]?.burden ?? 0) }}
                      </td>
                      <td></td>
                    </tr>
                    <tr>
                      <td class="pb-3 pl-5 pt-1" :colspan="people.length + 4">
                        <p class="mb-1 text-xs font-medium">{{ t('block_lines_title', { year }) }}</p>
                        <p v-if="lines[block.id] === 'loading'" class="text-xs text-[hsl(var(--muted-foreground))]">{{ t('loading') }}</p>
                        <p v-else-if="lines[block.id] === 'error'" class="text-xs text-[hsl(var(--destructive))]">{{ t('block_lines_error') }}</p>
                        <p v-else-if="!lines[block.id]?.length" class="text-xs text-[hsl(var(--muted-foreground))]">{{ t('block_lines_empty') }}</p>
                        <template v-else>
                          <div v-if="allocationOptions && selectedIn(block.id).length" class="mb-2 flex flex-wrap items-end gap-2 rounded-md border border-[hsl(var(--border))] bg-[hsl(var(--muted)/0.4)] p-2">
                            <span class="pb-2 text-xs font-medium">{{ t('allocation_selected_count', { count: selectedIn(block.id).length }) }}</span>
                            <FormSelect :id="'bulk_project_' + block.id" v-model="bulkProject[block.id]" :label="t('project')" :options="projectOptions" class="min-w-[14rem]" />
                            <Button size="sm" :disabled="!bulkProject[block.id] || bulkBusy" :loading="bulkBusy" @click="allocateSelected(block.id)">
                              <FolderKanban class="mr-1 h-4 w-4" /> {{ t('allocation_bulk_assign') }}
                            </Button>
                          </div>
                          <table class="w-full text-xs">
                            <thead>
                              <tr class="text-left text-[hsl(var(--muted-foreground))]">
                                <th v-if="allocationOptions" class="w-6 pb-1">
                                  <input type="checkbox" class="h-3.5 w-3.5 rounded border-[hsl(var(--input))]" :aria-label="t('select_all')" :checked="selectedIn(block.id).length === lines[block.id].length" @change="toggleAll(block.id)" />
                                </th>
                                <th class="pb-1">{{ t('date') }}</th>
                                <th class="pb-1">{{ t('reference') }}</th>
                                <th class="hidden pb-1 sm:table-cell">{{ t('description') }}</th>
                                <th class="pb-1 text-right">{{ t('amount') }}</th>
                                <th class="pb-1">{{ t('project') }}</th>
                                <th v-if="allocationOptions" class="w-8 pb-1"></th>
                              </tr>
                            </thead>
                            <tbody>
                              <tr v-for="line in lines[block.id]" :key="line.line_id" class="align-middle" :class="Number(line.in_block) === 0 && Number(line.amount) !== 0 ? 'text-[hsl(var(--muted-foreground))]' : ''">
                                <td v-if="allocationOptions" class="py-0.5">
                                  <input type="checkbox" class="h-3.5 w-3.5 rounded border-[hsl(var(--input))]" :aria-label="line.reference" :checked="!!selected[block.id]?.[line.line_id]" @change="toggleLine(block.id, line)" />
                                </td>
                                <td class="whitespace-nowrap py-0.5">{{ formatDate(line.date) }}</td>
                                <td class="py-0.5"><a :href="`/accounting/journal-entries/${line.entry_id}`" class="block max-w-[9rem] truncate hover:underline" :title="line.reference">{{ line.reference || '…' }}</a></td>
                                <td class="hidden py-0.5 sm:table-cell">{{ line.description }}</td>
                                <td class="whitespace-nowrap py-0.5 text-right tabular-nums">{{ formatCurrency(line.amount) }}</td>
                                <td class="py-0.5">
                                  <span v-if="line.assigned.length">{{ line.assigned.join(', ') }}</span>
                                  <span v-else-if="Number(line.at_acquisitions) === 0" class="italic text-[hsl(var(--muted-foreground))]">{{ t('block_line_unassigned') }}</span>
                                  <span v-if="Number(line.at_acquisitions) !== 0" class="block">
                                    <a :href="`/projects/acquisitions?year=${year}`" class="hover:underline" @click.stop>{{ t('block_line_acquisition', { amount: formatCurrency(line.at_acquisitions) }) }}</a>
                                  </span>
                                  <span v-if="Number(line.in_block) !== Number(line.amount) && Number(line.in_block) !== 0" class="block text-[hsl(var(--muted-foreground))]">{{ t('block_line_in_block', { amount: formatCurrency(line.in_block) }) }}</span>
                                </td>
                                <td v-if="allocationOptions" class="py-0.5 text-right">
                                  <Button variant="ghost" size="icon" class="h-6 w-6" :aria-label="t('block_line_assign')" :title="t('block_line_assign')" @click="allocate(line)"><FolderKanban class="h-3.5 w-3.5" /></Button>
                                </td>
                              </tr>
                            </tbody>
                          </table>
                        </template>
                      </td>
                    </tr>
                  </template>
                </template>
                <tr class="border-t font-semibold">
                  <td class="py-2">{{ t('total') }}</td>
                  <td class="py-2 text-right tabular-nums">{{ formatCurrency(steering.blocks.reduce((sum, b) => sum + num(b.plan), 0)) }}</td>
                  <td class="py-2 text-right tabular-nums">{{ formatCurrency(actualTotal) }}</td>
                  <td v-for="person in people" :key="person.employee_id" class="py-2 text-right tabular-nums">{{ formatCurrency(totalOf(person.employee_id)) }}</td>
                  <td></td>
                </tr>
              </tbody>
            </table>
          </div>
        </CardContent>
      </Card>

      <Card class="mt-4">
        <CardHeader><CardTitle>{{ t('settlements_title') }}</CardTitle></CardHeader>
        <CardContent>
          <p class="mb-3 text-sm text-[hsl(var(--muted-foreground))]">{{ t('settlements_desc') }}</p>
          <p v-if="!settlements.length" class="mb-3 text-sm text-[hsl(var(--muted-foreground))]">{{ t('settlements_empty') }}</p>
          <div v-else class="overflow-x-auto">
            <table class="w-full text-sm [&_td]:pr-3 [&_th]:pr-3">
              <thead>
                <tr class="border-b text-left text-[hsl(var(--muted-foreground))]">
                  <th class="pb-2">{{ t('description') }}</th>
                  <th class="pb-2">{{ t('settlement_from') }}</th>
                  <th class="pb-2">{{ t('settlement_to') }}</th>
                  <th class="hidden pb-2 sm:table-cell">{{ t('project') }}</th>
                  <th class="pb-2 text-right">{{ t('amount') }}</th>
                  <th v-if="canSteer" class="w-20 pb-2"></th>
                </tr>
              </thead>
              <tbody class="divide-y divide-[hsl(var(--border))]">
                <tr v-for="row in settlements" :key="row.id" :class="editingSettlement?.id === row.id ? 'bg-[hsl(var(--muted))]/40' : ''">
                  <td class="py-1">{{ row.label }}</td>
                  <td class="py-1">{{ nameOf(row.from_employee_id) }}</td>
                  <td class="py-1">{{ nameOf(row.to_employee_id) }}</td>
                  <td class="hidden py-1 sm:table-cell">
                    <a v-if="row.project" :href="`/projects/${row.project.id}/report?from=${year}-01-01&to=${year}-12-31`" class="hover:underline">{{ row.project.name }}</a>
                  </td>
                  <td class="py-1 text-right tabular-nums">{{ formatCurrency(row.amount) }}</td>
                  <td v-if="canSteer" class="py-1 text-right">
                    <span class="flex justify-end">
                      <Button variant="ghost" size="icon" :aria-label="t('edit')" @click="editSettlement(row)"><Pencil class="h-4 w-4" /></Button>
                      <Button variant="ghost" size="icon" :aria-label="t('delete')" @click="deletingSettlement = row"><Trash2 class="h-4 w-4 text-[hsl(var(--destructive))]" /></Button>
                    </span>
                  </td>
                </tr>
                <tr class="border-t font-semibold">
                  <td class="py-2" colspan="4">{{ t('settlements_balance') }}</td>
                  <td class="py-2 text-right" :colspan="canSteer ? 2 : 1">
                    <span v-for="person in people" :key="person.employee_id" class="block tabular-nums" :class="settledOf(person.employee_id) < 0 ? 'text-[hsl(var(--destructive))]' : ''">
                      {{ person.name }} {{ settledOf(person.employee_id) > 0 ? '+' : '' }}{{ formatCurrency(settledOf(person.employee_id)) }}
                    </span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <form v-if="canSteer" class="mt-4 flex flex-wrap items-end gap-2" @submit.prevent="saveSettlement">
            <FormInput id="settlement_label" v-model="settlementForm.label" :label="t('description')" :placeholder="t('settlement_placeholder')" :error="settlementForm.errors.label" class="min-w-[12rem] flex-1" />
            <FormSelect id="settlement_from" v-model="settlementForm.from_employee_id" :label="t('settlement_from')" :options="personOptions" :error="settlementForm.errors.from_employee_id" class="min-w-[9rem]" />
            <FormSelect id="settlement_to" v-model="settlementForm.to_employee_id" :label="t('settlement_to')" :options="personOptions" :error="settlementForm.errors.to_employee_id" class="min-w-[9rem]" />
            <FormSelect id="settlement_job" v-model="settlementForm.cost_center_id" :label="t('project')" :options="settlementJobOptions" :error="settlementForm.errors.cost_center_id" class="min-w-[10rem]" />
            <FormInput id="settlement_amount" v-model="settlementForm.amount" type="number" step="0.01" min="0" :label="t('amount')" :error="settlementForm.errors.amount" class="w-32" />
            <Button type="submit" size="sm" :disabled="settlementForm.processing">
              <Plus v-if="!editingSettlement" class="mr-1 h-4 w-4" /> {{ editingSettlement ? t('save') : t('add') }}
            </Button>
            <Button v-if="editingSettlement" type="button" size="sm" variant="outline" @click="resetSettlement">{{ t('cancel') }}</Button>
          </form>
        </CardContent>
      </Card>


    <AllocationDialog v-if="allocationOptions" :open="!!allocating" :entry="allocating" :options="allocationOptions" @close="allocating = null" />

    <BudgetGroupFormModal
      v-if="canManage"
      :open="showBlockForm"
      :group="editingBlock"
      :year="year"
      :accounts="blockOptions.accounts"
      :kinds="blockOptions.kinds"
      :employees="employees"
      :can-steer="canSteer"
      @close="showBlockForm = false"
    />
    <ConfirmDialog :open="!!deletingSettlement" :title="t('confirm_delete')" :message="deletingSettlement?.label ?? ''" @confirm="performDeleteSettlement" @cancel="deletingSettlement = null" />
    <ConfirmDialog :open="!!deletingBlock" :title="t('confirm_delete')" :message="deletingBlock?.name ?? ''" @confirm="performDeleteBlock" @cancel="deletingBlock = null" />
  </AppLayout>
</template>
