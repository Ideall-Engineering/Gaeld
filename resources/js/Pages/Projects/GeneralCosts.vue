<script setup>
import AppLayout from '@/Components/AppLayout.vue'
import Card from '@/Components/UI/Card.vue'
import CardHeader from '@/Components/UI/CardHeader.vue'
import CardTitle from '@/Components/UI/CardTitle.vue'
import CardContent from '@/Components/UI/CardContent.vue'
import Button from '@/Components/UI/Button.vue'
import Breadcrumb from '@/Components/UI/Breadcrumb.vue'
import FormSelect from '@/Components/UI/FormSelect.vue'
import BudgetGroupFormModal from '@/Components/Projects/BudgetGroupFormModal.vue'
import AllocationDialog from '@/Components/Projects/AllocationDialog.vue'
import { useTranslations } from '@/lib/useTranslations'
import { useFormatters } from '@/lib/useFormatters'
import { router } from '@inertiajs/vue3'
import { computed, reactive, ref, watch } from 'vue'
import { ChevronDown, ChevronRight, FolderKanban, Pencil } from 'lucide-vue-next'

// The general costs of the steering on a page of their own: each cost
// block, who bears it and who is credited. Only owners (canSteer) edit.
const props = defineProps({
  year: Number,
  canSteer: { type: Boolean, default: false },
  steering: Object,
  employees: Array,
  groups: { type: Array, default: () => [] },
  blockOptions: { type: Object, default: () => ({ accounts: [], kinds: [] }) },
  allocationOptions: { type: Object, default: null },
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

const showBlockForm = ref(false)
const editingBlock = ref(null)
function editBlock(block) {
  editingBlock.value = props.groups.find(g => g.id === block.id) ?? null
  if (editingBlock.value) showBlockForm.value = true
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
        <CardHeader><CardTitle>{{ t('steering_blocks') }}</CardTitle></CardHeader>
        <CardContent>
          <p class="mb-3 text-sm text-[hsl(var(--muted-foreground))]">{{ t('steering_blocks_desc') }}</p>
          <div class="overflow-x-auto">
            <table class="w-full text-sm [&_td]:pr-3 [&_th]:pr-3">
              <thead>
                <tr class="border-b text-left text-[hsl(var(--muted-foreground))]">
                  <th class="pb-2">{{ t('name') }}</th>
                  <th class="pb-2 text-right">{{ t('budget_group_plan') }}</th>
                  <th class="pb-2 text-right">{{ t('budget_group_actual') }}</th>
                  <th v-for="person in people" :key="person.employee_id" class="pb-2 text-right">{{ person.name }}</th>
                  <th class="w-12 pb-2"></th>
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
                    </td>
                    <td class="py-1 text-right tabular-nums">{{ formatCurrency(block.plan) }}</td>
                    <td class="py-1 text-right tabular-nums">{{ formatCurrency(block.actual) }}</td>
                    <td v-for="person in people" :key="person.employee_id" class="py-1 text-right tabular-nums">
                      {{ formatCurrency(block.people[person.employee_id]?.effect ?? 0) }}
                    </td>
                    <td class="py-1 text-right" @click.stop>
                      <Button v-if="canSteer" variant="ghost" size="icon" :aria-label="t('edit')" @click="editBlock(block)"><Pencil class="h-4 w-4" /></Button>
                    </td>
                  </tr>
                  <template v-if="open['block-' + block.id]">
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
                    <tr class="text-xs text-[hsl(var(--muted-foreground))]">
                      <td class="py-1 pl-5" colspan="3">{{ Object.values(block.people).some(p => num(p.credit_share) > 0) ? t('steering_block_credit') : t('steering_block_credit_none') }}</td>
                      <td v-for="person in people" :key="person.employee_id" class="py-1 text-right tabular-nums">
                        {{ num(block.people[person.employee_id]?.credit_share) }} % · {{ formatCurrency(block.people[person.employee_id]?.credit ?? 0) }}
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
                              <tr v-for="line in lines[block.id]" :key="line.line_id" class="align-middle" :class="line.jobs.length && Number(line.at_jobs) === Number(line.amount) ? 'text-[hsl(var(--muted-foreground))]' : ''">
                                <td v-if="allocationOptions" class="py-0.5">
                                  <input type="checkbox" class="h-3.5 w-3.5 rounded border-[hsl(var(--input))]" :aria-label="line.reference" :checked="!!selected[block.id]?.[line.line_id]" @change="toggleLine(block.id, line)" />
                                </td>
                                <td class="whitespace-nowrap py-0.5">{{ formatDate(line.date) }}</td>
                                <td class="py-0.5"><a :href="`/accounting/journal-entries/${line.entry_id}`" class="block max-w-[9rem] truncate hover:underline" :title="line.reference">{{ line.reference || '…' }}</a></td>
                                <td class="hidden py-0.5 sm:table-cell">{{ line.description }}</td>
                                <td class="whitespace-nowrap py-0.5 text-right tabular-nums">{{ formatCurrency(line.amount) }}</td>
                                <td class="py-0.5">
                                  <span v-if="line.assigned.length">{{ line.assigned.join(', ') }}</span>
                                  <span v-else class="italic text-[hsl(var(--muted-foreground))]">{{ t('block_line_unassigned') }}</span>
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


    <AllocationDialog v-if="allocationOptions" :open="!!allocating" :entry="allocating" :options="allocationOptions" @close="allocating = null" />

    <BudgetGroupFormModal
      v-if="canSteer"
      :open="showBlockForm"
      :group="editingBlock"
      :year="year"
      :accounts="blockOptions.accounts"
      :kinds="blockOptions.kinds"
      :employees="employees"
      can-steer
      @close="showBlockForm = false"
    />
  </AppLayout>
</template>
