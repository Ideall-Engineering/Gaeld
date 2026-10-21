<script setup>
import AppLayout from '@/Components/AppLayout.vue'
import Card from '@/Components/UI/Card.vue'
import CardHeader from '@/Components/UI/CardHeader.vue'
import CardTitle from '@/Components/UI/CardTitle.vue'
import CardContent from '@/Components/UI/CardContent.vue'
import Button from '@/Components/UI/Button.vue'
import Breadcrumb from '@/Components/UI/Breadcrumb.vue'
import ExportDropdown from '@/Components/UI/ExportDropdown.vue'
import FormInput from '@/Components/UI/FormInput.vue'
import FormSelect from '@/Components/UI/FormSelect.vue'
import BudgetGroupFormModal from '@/Components/Projects/BudgetGroupFormModal.vue'
import { useTranslations } from '@/lib/useTranslations'
import { useFormatters } from '@/lib/useFormatters'
import { Link, router, useForm } from '@inertiajs/vue3'
import { computed, reactive, ref } from 'vue'
import { AlertTriangle, Check, ChevronDown, ChevronRight, Pencil, Plus, Trash2 } from 'lucide-vue-next'
import { Bar, Line } from 'vue-chartjs'
import { BarElement, CategoryScale, Chart as ChartJS, Legend, LinearScale, LineElement, PointElement, Tooltip } from 'chart.js'
import { useTheme } from '@/lib/useTheme'
import { intlLocale } from '@/lib/utils'

ChartJS.register(CategoryScale, LinearScale, BarElement, LineElement, PointElement, Tooltip, Legend)

// What each person earned and drew, and the room left for performance pay
// (spec 009, stage 5; spec 010, US5). Seen from accountants up; only owners
// (canSteer) change blocks and plan items (spec 012).
const props = defineProps({
  year: Number,
  canSteer: { type: Boolean, default: false },
  steering: Object,
  employees: Array,
  groups: { type: Array, default: () => [] },
  blockOptions: { type: Object, default: () => ({ accounts: [], kinds: [] }) },
})

const { t } = useTranslations()
const { formatCurrency, formatDate, intlMonthName, locale } = useFormatters()
const { isDark } = useTheme()

const people = computed(() => props.steering.people)
const yearOptions = computed(() => {
  const now = new Date().getFullYear()
  return [now + 1, now, now - 1, now - 2].map(y => ({ value: String(y), label: String(y) }))
})

// Each line of a person can be opened: jobs, general costs, drawings.
const open = reactive({})
const toggle = key => { open[key] = !open[key] }
const itemsOf = id => props.steering.items.filter(i => i.employee_id === id)
const blocksOf = id => props.steering.blocks
  .map(b => ({ ...b, effect: b.people[id]?.effect ?? '0.00' }))
  .filter(b => Number(b.effect) !== 0)

// Spec 010: a cost block is edited where its effect is seen.
const showBlockForm = ref(false)
const editingBlock = ref(null)
function editBlock(block) {
  editingBlock.value = props.groups.find(g => g.id === block.id) ?? null
  if (editingBlock.value) showBlockForm.value = true
}
const num = v => Number(v ?? 0)
// Shown as an outflow, without a "-0.00" for nothing.
const out = v => (num(v) === 0 ? 0 : -num(v))

// The open payments of the year and, in a second tab, of the next one.
const openYear = ref(props.year)
const openPayments = computed(() => (openYear.value === props.year ? props.steering.open_payments : props.steering.open_payments_next_year) ?? [])
const openTotal = computed(() => openPayments.value.reduce((sum, p) => sum + num(p.expected), 0))
// Invoices sent and not paid yet: they come in on their due day.
const openInvoices = computed(() => props.steering.open_invoices ?? [])
const openInvoicesTotal = computed(() => openInvoices.value.reduce((sum, i) => sum + num(i.gross), 0))
const today = new Date().toISOString().slice(0, 10)

// The day a payment is expected, changed right in the list.
function setPaymentDate(payment, value) {
  router.put(`/projects/${payment.id}/payment-date`, { payment_expected_on: value || null }, { preserveScroll: true })
}

// Spec 012: the expenses still expected, in the same two tabs, grouped by
// month with the undated ones last. VAT is a payment, not a cost: listed,
// but kept out of the net total.
const expectedYear = ref(props.year)
const expected = computed(() => (expectedYear.value === props.year ? props.steering.expected_expenses : props.steering.expected_expenses_next_year)
  ?? { rows: [], by_source: {}, total: '0.00', cash_total: '0.00', hourly: [] })
const monthLabel = key => `${intlMonthName(Number(key.slice(5, 7)) - 1)} ${key.slice(0, 4)}`
const expectedMonths = computed(() => {
  const months = []
  for (const row of expected.value.rows) {
    const key = row.date ? row.date.slice(0, 7) : 'undated'
    let month = months[months.length - 1]
    if (!month || month.key !== key) {
      month = { key, label: key === 'undated' ? t('steering_expected_undated') : monthLabel(key), rows: [], total: 0 }
      months.push(month)
    }
    month.rows.push(row)
    if (row.in_result) month.total += num(row.amount)
  }
  return months
})
function expectedLabel(row) {
  if (row.source === 'social') return t('steering_expected_social_label')
  if (row.source === 'block') return t('steering_expected_block_label', { name: row.label })
  if (row.source === 'vat') return t('steering_expected_vat_' + row.label, { quarter: row.quarter ?? '' })
  return row.label
}
const expectedSources = ['salary', 'social', 'recurring', 'expense', 'acquisition', 'planned', 'block']

// Single expected expenses: entered, marked done or removed right here.
const plannedForm = useForm({ date: '', label: '', amount: '', vat_amount: '', account_code: '' })
const accountOptions = computed(() => [{ value: '', label: '' }, ...(props.blockOptions.accounts ?? []).map(a => ({ value: a.code, label: `${a.code} ${a.name}` }))])
function addPlanned() {
  plannedForm.post('/projects/steering/planned-expenses', { preserveScroll: true, onSuccess: () => plannedForm.reset() })
}
function donePlanned(row) {
  router.put(`/projects/steering/planned-expenses/${row.id}`, { done: true }, { preserveScroll: true })
}
function removePlanned(row) {
  router.delete(`/projects/steering/planned-expenses/${row.id}`, { preserveScroll: true })
}

// What is still expected all in all, per year, and month by month with the
// bank balance (running year only).
const outlookMonths = computed(() => props.steering.outlook?.months ?? [])
const outlook = computed(() => [props.year, props.year + 1].map(y => {
  const income = ((y === props.year ? props.steering.open_payments : props.steering.open_payments_next_year) ?? []).reduce((sum, p) => sum + num(p.expected), 0)
  const expenses = num((y === props.year ? props.steering.expected_expenses : props.steering.expected_expenses_next_year)?.total)
  const december = outlookMonths.value.find(m => m.key === `${y}-12`)
  return { year: y, income, expenses, result: income - expenses, bank: december ? num(december.bank) : null }
}))
const seriesColors = computed(() => isDark.value ? ['#3987e5', '#d95926'] : ['#2a78d6', '#eb6834'])
const compact = value => 'CHF ' + new Intl.NumberFormat(intlLocale(locale.value), { notation: 'compact', maximumFractionDigits: 1 }).format(Math.abs(value) < 0.5 ? 0 : value)
const axisOptions = computed(() => {
  const tick = isDark.value ? '#c3c2b7' : '#52514e'
  const grid = isDark.value ? 'rgba(195, 194, 183, 0.15)' : 'rgba(82, 81, 78, 0.12)'
  return {
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    plugins: {
      legend: { position: 'bottom', labels: { color: tick, boxWidth: 12 } },
      tooltip: { callbacks: { label: item => ` ${item.dataset.label}: ${formatCurrency(item.raw)}` } },
    },
    scales: {
      x: { ticks: { color: tick }, grid: { display: false } },
      y: { ticks: { color: tick, callback: compact }, grid: { color: grid } },
    },
  }
})
const flowChart = computed(() => ({
  labels: outlookMonths.value.map(m => monthLabel(m.key)),
  datasets: [
    { label: t('steering_outlook_income'), data: outlookMonths.value.map(m => num(m.income)), backgroundColor: seriesColors.value[0], borderRadius: 4, borderSkipped: 'bottom', maxBarThickness: 18 },
    { label: t('steering_outlook_expenses'), data: outlookMonths.value.map(m => num(m.expenses)), backgroundColor: seriesColors.value[1], borderRadius: 4, borderSkipped: 'bottom', maxBarThickness: 18 },
  ],
}))
const bankChart = computed(() => ({
  labels: outlookMonths.value.map(m => monthLabel(m.key)),
  datasets: [{ label: t('steering_outlook_bank'), data: outlookMonths.value.map(m => num(m.bank)), borderColor: seriesColors.value[0], backgroundColor: seriesColors.value[0], borderWidth: 2, pointRadius: 4, tension: 0.2 }],
}))
const bankOptions = computed(() => ({ ...axisOptions.value, plugins: { ...axisOptions.value.plugins, legend: { display: false } } }))

// Drawn, one foldable row per person: plan items and acquisitions.
const drawnRows = computed(() => people.value
  .map(p => ({ ...p, items: itemsOf(p.employee_id) }))
  .filter(p => p.items.length || num(p.acquisitions) !== 0 || num(p.still_due) !== 0))

const itemForm = useForm({ year: props.year, employee_id: props.employees[0]?.id ?? '', label: '', amount: '' })
function addItem() {
  itemForm.post('/projects/steering/items', {
    preserveScroll: true,
    onSuccess: () => { itemForm.label = ''; itemForm.amount = '' },
  })
}
function removeItem(item) {
  router.delete(`/projects/steering/items/${item.id}`, { preserveScroll: true })
}
</script>

<template>
  <AppLayout :title="t('steering')">
    <Breadcrumb :items="[{ label: t('jobs'), href: `/projects?year=${year}` }, { label: t('steering') }]" class="mb-4" />
    <div class="space-y-6">
      <Card>
        <CardHeader>
          <div class="flex flex-wrap items-end justify-between gap-3">
            <CardTitle>{{ t('steering') }} {{ year }}</CardTitle>
            <div class="flex items-end gap-2">
              <FormSelect id="year" :model-value="String(year)" :label="t('year')" :options="yearOptions" @update:model-value="v => router.get('/projects/steering', { year: v })" />
              <ExportDropdown base-url="/projects/steering/export" :params="{ year }" />
            </div>
          </div>
        </CardHeader>
        <CardContent>
          <p class="mb-4 text-sm text-[hsl(var(--muted-foreground))]">{{ t('steering_desc') }}</p>
          <p v-if="Number(steering.unshared) !== 0" class="mb-4 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200">
            {{ t('steering_unshared', { amount: formatCurrency(steering.unshared) }) }}
          </p>
          <div v-if="steering.done_without_revenue?.length" class="mb-4 rounded-md border border-[hsl(var(--destructive))]/40 bg-[hsl(var(--destructive))]/10 p-3 text-sm" role="alert">
            <div class="flex items-center gap-2 font-medium text-[hsl(var(--destructive))]">
              <AlertTriangle class="h-4 w-4 shrink-0" />
              {{ t('steering_done_without_revenue', { count: steering.done_without_revenue.length }) }}
            </div>
            <p class="mt-1 text-[hsl(var(--muted-foreground))]">{{ t('steering_done_without_revenue_hint') }}</p>
            <ul class="mt-2 space-y-0.5">
              <li v-for="job in steering.done_without_revenue" :key="job.id" class="flex flex-wrap justify-between gap-x-3">
                <Link :href="`/projects/${job.id}/report`" class="hover:underline">{{ job.code }} {{ job.name }}<span v-if="job.group" class="text-[hsl(var(--muted-foreground))]"> · {{ job.group }}</span></Link>
                <span class="tabular-nums text-[hsl(var(--muted-foreground))]">{{ job.expected !== null ? t('job_expected_hint', { amount: formatCurrency(job.expected) }) : '' }}</span>
              </li>
            </ul>
          </div>
          <div class="grid gap-4 md:grid-cols-2">
            <div v-for="person in people" :key="person.employee_id" class="rounded-lg border border-[hsl(var(--border))] p-4">
              <div class="mb-3 text-lg font-semibold">{{ person.name }}</div>
              <table class="w-full text-sm">
                <tbody>
                  <tr>
                    <td class="py-1">
                      <button type="button" class="flex items-center gap-1 hover:underline" @click="toggle(person.employee_id)">
                        <component :is="open[person.employee_id] ? ChevronDown : ChevronRight" class="h-4 w-4" />
                        {{ t('steering_contribution') }}
                      </button>
                    </td>
                    <td class="py-1 text-right tabular-nums">{{ formatCurrency(person.contribution) }}</td>
                  </tr>
                  <tr v-if="open[person.employee_id]">
                    <td colspan="2" class="pb-2 pl-5">
                      <p v-if="!person.jobs?.length" class="text-xs text-[hsl(var(--muted-foreground))]">{{ t('person_jobs_empty') }}</p>
                      <table v-else class="w-full text-xs">
                        <tbody class="divide-y divide-[hsl(var(--border))]">
                          <tr v-for="job in person.jobs" :key="job.id">
                            <td class="py-1">
                              <Link :href="`/projects/${job.id}/report?from=${year}-01-01&to=${year}-12-31`" class="hover:underline">{{ job.name }}</Link>
                              <span class="text-[hsl(var(--muted-foreground))]"><template v-if="job.group"> · {{ job.group }}</template> · {{ job.general ? t('steering_general_job') : t('project_status_' + job.status) }}<template v-if="!job.booked"> · {{ t('person_expected') }}</template><template v-else-if="Number(job.open_part) !== 0"> · {{ t('steering_invoice_open', { amount: formatCurrency(job.open_part) }) }}</template></span>
                            </td>
                            <td class="py-1 text-right tabular-nums text-[hsl(var(--muted-foreground))]">{{ Number(job.share) }} %</td>
                            <td class="py-1 text-right tabular-nums">{{ formatCurrency(job.part) }}</td>
                          </tr>
                        </tbody>
                      </table>
                    </td>
                  </tr>
                  <tr>
                    <td class="py-1">
                      <button type="button" class="flex items-center gap-1 hover:underline" @click="toggle('effect-' + person.employee_id)">
                        <component :is="open['effect-' + person.employee_id] ? ChevronDown : ChevronRight" class="h-4 w-4" />
                        {{ t('steering_effect') }}
                      </button>
                    </td>
                    <td class="py-1 text-right tabular-nums">{{ formatCurrency(person.effect) }}</td>
                  </tr>
                  <tr v-if="open['effect-' + person.employee_id]">
                    <td colspan="2" class="pb-2 pl-5">
                      <table class="w-full text-xs">
                        <tbody class="divide-y divide-[hsl(var(--border))]">
                          <tr v-for="block in blocksOf(person.employee_id)" :key="block.id">
                            <td class="py-1"><button type="button" class="text-left hover:underline" @click="editBlock(block)">{{ block.name }}</button></td>
                            <td class="py-1 text-right tabular-nums">{{ formatCurrency(block.effect) }}</td>
                          </tr>
                        </tbody>
                      </table>
                    </td>
                  </tr>
                  <tr class="border-t font-semibold">
                    <td class="py-2">{{ t('steering_value') }}</td>
                    <td class="py-2 text-right tabular-nums">{{ formatCurrency(person.value) }}</td>
                  </tr>
                  <tr>
                    <td class="py-1">
                      <button type="button" class="flex items-center gap-1 hover:underline" @click="toggle('drawn-' + person.employee_id)">
                        <component :is="open['drawn-' + person.employee_id] ? ChevronDown : ChevronRight" class="h-4 w-4" />
                        {{ t('steering_drawn') }}
                      </button>
                    </td>
                    <td class="py-1 text-right tabular-nums">{{ formatCurrency(-person.drawn) }}</td>
                  </tr>
                  <tr v-if="open['drawn-' + person.employee_id]">
                    <td colspan="2" class="pb-2 pl-5">
                      <table class="w-full text-xs">
                        <tbody class="divide-y divide-[hsl(var(--border))]">
                          <tr v-for="item in itemsOf(person.employee_id)" :key="item.id">
                            <td class="py-1">{{ item.label }}</td>
                            <td class="py-1 text-right tabular-nums">{{ formatCurrency(-item.amount) }}</td>
                          </tr>
                          <tr v-if="Number(person.acquisitions) !== 0">
                            <td class="py-1"><Link :href="`/projects/acquisitions?year=${year}`" class="hover:underline">{{ t('acquisitions') }}</Link></td>
                            <td class="py-1 text-right tabular-nums">{{ formatCurrency(-person.acquisitions) }}</td>
                          </tr>
                          <tr v-if="Number(person.still_due) !== 0">
                            <td class="py-1">{{ t('steering_still_due') }}</td>
                            <td class="py-1 text-right tabular-nums">{{ formatCurrency(-person.still_due) }}</td>
                          </tr>
                          <tr v-if="!itemsOf(person.employee_id).length && Number(person.acquisitions) === 0 && Number(person.still_due) === 0">
                            <td colspan="2" class="py-1 text-[hsl(var(--muted-foreground))]">{{ t('steering_drawn_empty') }}</td>
                          </tr>
                        </tbody>
                      </table>
                    </td>
                  </tr>
                  <tr class="border-t text-base font-semibold">
                    <td class="py-2">
                      <button type="button" class="flex items-center gap-1 hover:underline" @click="toggle('play-' + person.employee_id)">
                        <component :is="open['play-' + person.employee_id] ? ChevronDown : ChevronRight" class="h-4 w-4" />
                        {{ t('steering_play') }}
                      </button>
                    </td>
                    <td class="py-2 text-right tabular-nums" :class="Number(person.play) < 0 ? 'text-[hsl(var(--destructive))]' : 'text-green-700 dark:text-green-400'">{{ formatCurrency(person.play) }}</td>
                  </tr>
                  <tr v-if="open['play-' + person.employee_id]">
                    <td colspan="2" class="pb-3 pl-5">
                      <table class="w-full text-xs">
                        <tbody>
                          <tr>
                            <td class="py-1">{{ t('steering_play_received') }}</td>
                            <td class="py-1 text-right tabular-nums">{{ formatCurrency(person.contribution_received) }}</td>
                          </tr>
                          <tr>
                            <td class="py-1">{{ t('steering_play_expected', { year }) }}</td>
                            <td class="py-1 text-right tabular-nums">{{ formatCurrency(person.contribution_expected) }}</td>
                          </tr>
                          <tr v-for="job in person.jobs.filter(j => !j.booked || Number(j.open_part) !== 0)" :key="'exp-' + job.id" class="text-[hsl(var(--muted-foreground))]">
                            <td class="py-0.5 pl-4">
                              <Link :href="`/projects/${job.id}/report?from=${year}-01-01&to=${year}-12-31`" class="hover:underline">{{ job.name }}</Link>
                              <span> · {{ job.booked ? t('steering_invoice_open_short') : t('project_status_' + job.status) }}<template v-if="!job.booked && job.billing"> · {{ job.billing }}</template></span>
                            </td>
                            <td class="py-0.5 text-right tabular-nums">{{ formatCurrency(job.booked ? job.open_part : job.part) }}</td>
                          </tr>
                          <tr>
                            <td class="py-1">{{ t('steering_effect') }}</td>
                            <td class="py-1 text-right tabular-nums">{{ formatCurrency(person.effect) }}</td>
                          </tr>
                          <tr>
                            <td class="py-1">{{ t('steering_drawn') }}</td>
                            <td class="py-1 text-right tabular-nums">{{ formatCurrency(-person.drawn) }}</td>
                          </tr>
                          <tr class="border-t font-semibold">
                            <td class="py-1">= {{ t('steering_play') }}</td>
                            <td class="py-1 text-right tabular-nums">{{ formatCurrency(person.play) }}</td>
                          </tr>
                          <tr v-if="Number(person.play) !== 0 && Number(person.contribution_expected) !== 0" class="text-[hsl(var(--muted-foreground))]">
                            <td class="pt-1" colspan="2">{{ t('steering_play_if_nothing_comes', { amount: formatCurrency(Number(person.play) - Number(person.contribution_expected)) }) }}</td>
                          </tr>
                        </tbody>
                      </table>
                    </td>
                  </tr>
                  <template v-for="aside in [{ key: 'prospects', jobs: person.prospect_jobs }, { key: 'later', jobs: person.later_jobs }]" :key="aside.key">
                    <tr v-if="aside.jobs?.length" class="text-xs text-[hsl(var(--muted-foreground))]">
                      <td class="py-1">
                        <button type="button" class="flex items-center gap-1 hover:underline" @click="toggle(aside.key + '-' + person.employee_id)">
                          <component :is="open[aside.key + '-' + person.employee_id] ? ChevronDown : ChevronRight" class="h-4 w-4" />
                          {{ t('steering_' + aside.key) }}
                        </button>
                      </td>
                      <td class="py-1 text-right tabular-nums">{{ formatCurrency(person[aside.key]) }}</td>
                    </tr>
                    <tr v-if="aside.jobs?.length && open[aside.key + '-' + person.employee_id]">
                      <td colspan="2" class="pb-2 pl-5">
                        <table class="w-full text-xs text-[hsl(var(--muted-foreground))]">
                          <tbody class="divide-y divide-[hsl(var(--border))]">
                            <tr v-for="job in aside.jobs" :key="job.id">
                              <td class="py-1">
                                <Link :href="`/projects/${job.id}/report?from=${year}-01-01&to=${year}-12-31`" class="hover:underline">{{ job.name }}</Link>
                                <span> · {{ t('project_status_' + job.status) }}<template v-if="aside.key === 'later' && job.billing"> · {{ job.billing }}</template></span>
                              </td>
                              <td class="py-1 text-right tabular-nums">{{ Number(job.share) }} %</td>
                              <td class="py-1 text-right tabular-nums">{{ formatCurrency(job.part) }}</td>
                            </tr>
                          </tbody>
                        </table>
                      </td>
                    </tr>
                  </template>
                  <tr class="text-xs text-[hsl(var(--muted-foreground))]">
                    <td class="py-1 pl-5">{{ t('steering_booked_salary') }}</td>
                    <td class="py-1 text-right tabular-nums">{{ formatCurrency(person.booked_salary) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <div class="flex flex-wrap items-center gap-4">
            <CardTitle>{{ t('steering_open_payments') }}</CardTitle>
            <div class="inline-flex rounded-md border border-[hsl(var(--border))] p-0.5" role="tablist">
              <button
                v-for="y in [year, year + 1]"
                :key="y"
                type="button"
                role="tab"
                :aria-selected="openYear === y"
                class="rounded px-3 py-1 text-sm tabular-nums"
                :class="openYear === y ? 'bg-[hsl(var(--muted))] font-semibold' : 'text-[hsl(var(--muted-foreground))]'"
                @click="openYear = y"
              >
                {{ y }}
                <span class="ml-1 text-xs text-[hsl(var(--muted-foreground))]">{{ (y === year ? steering.open_payments : steering.open_payments_next_year)?.length ?? 0 }}</span>
              </button>
            </div>
          </div>
        </CardHeader>
        <CardContent>
          <p class="mb-3 text-sm text-[hsl(var(--muted-foreground))]">{{ openYear === year ? t('steering_open_payments_desc') : t('steering_open_payments_next_desc') }}</p>
          <p v-if="!openPayments.length" class="text-sm text-[hsl(var(--muted-foreground))]">{{ t('steering_open_payments_none') }}</p>
          <div v-else class="overflow-x-auto">
            <table class="w-full text-sm [&_td]:pr-3 [&_th]:pr-3">
              <thead>
                <tr class="border-b text-left text-[hsl(var(--muted-foreground))]">
                  <th class="pb-2">{{ t('project_kind_project') }}</th>
                  <th class="pb-2">{{ t('status') }}</th>
                  <th class="pb-2">{{ t('project_payment_expected_on') }}</th>
                  <th class="pb-2 text-right">{{ t('steering_open_expected') }}</th>
                  <th class="pb-2 text-right">{{ t('steering_open_costs') }}</th>
                  <th class="pb-2">{{ t('steering_open_people') }}</th>
                  <th class="pb-2">{{ t('invoices') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-[hsl(var(--border))]">
                <tr v-for="p in openPayments" :key="p.id">
                  <td class="py-1">
                    <Link :href="`/projects/${p.id}/report?from=${openYear}-01-01&to=${openYear}-12-31`" class="hover:underline">{{ p.name }}</Link>
                    <div class="text-xs text-[hsl(var(--muted-foreground))]">{{ p.code }}<template v-if="p.group"> · {{ p.group }}</template></div>
                  </td>
                  <td class="py-1">{{ t('project_status_' + p.status) }}</td>
                  <td class="py-1">
                    <input
                      type="date"
                      :value="p.payment_expected_on ?? ''"
                      :aria-label="t('project_payment_expected_on')"
                      class="h-8 rounded-md border border-[hsl(var(--input))] bg-transparent px-2 text-sm tabular-nums"
                      :class="p.sort && p.sort < today ? 'border-[hsl(var(--destructive))] text-[hsl(var(--destructive))]' : ''"
                      @change="setPaymentDate(p, $event.target.value)"
                    />
                    <div v-if="!p.payment_expected_on" class="text-xs text-[hsl(var(--muted-foreground))]">
                      {{ p.billing_month ? t('steering_open_from_billing', { month: p.billing_month }) : (p.billing ? t('steering_open_from_target', { month: p.billing }) : t('steering_open_undated')) }}
                    </div>
                  </td>
                  <td class="py-1 text-right tabular-nums">{{ formatCurrency(p.expected) }}</td>
                  <td class="py-1 text-right tabular-nums">{{ Number(p.costs) ? formatCurrency(-p.costs) : '' }}</td>
                  <td class="py-1 text-xs">
                    <div v-for="person in p.people" :key="person.name">{{ person.name }} {{ formatCurrency(person.part) }}</div>
                    <span v-if="!p.people.length" class="text-amber-700 dark:text-amber-300">{{ t('steering_open_nobody') }}</span>
                  </td>
                  <td class="py-1 text-xs">
                    <div v-for="inv in p.invoices" :key="inv.id"><Link :href="`/invoices/${inv.id}`" class="hover:underline">{{ inv.number ?? t('draft') }}</Link> · {{ t('invoice_status_' + inv.status) }}</div>
                    <span v-if="!p.invoices.length" class="text-[hsl(var(--muted-foreground))]">{{ t('steering_open_no_invoice') }}</span>
                  </td>
                </tr>
                <tr class="font-semibold">
                  <td class="py-2" colspan="3">{{ t('total') }}</td>
                  <td class="py-2 text-right tabular-nums">{{ formatCurrency(openTotal) }}</td>
                  <td colspan="3"></td>
                </tr>
              </tbody>
            </table>
          </div>

          <template v-if="openYear === year">
            <h3 class="mb-1 mt-6 text-sm font-semibold">{{ t('steering_open_invoices') }}</h3>
            <p class="mb-3 text-sm text-[hsl(var(--muted-foreground))]">{{ t('steering_open_invoices_desc') }}</p>
            <p v-if="!openInvoices.length" class="text-sm text-[hsl(var(--muted-foreground))]">{{ t('steering_open_invoices_none') }}</p>
            <div v-else class="overflow-x-auto">
              <table class="w-full text-sm [&_td]:pr-3 [&_th]:pr-3">
                <thead>
                  <tr class="border-b text-left text-[hsl(var(--muted-foreground))]">
                    <th class="pb-2">{{ t('invoices') }}</th>
                    <th class="pb-2">{{ t('project_kind_project') }}</th>
                    <th class="pb-2">{{ t('due_date') }}</th>
                    <th class="pb-2 text-right">{{ t('steering_open_invoices_gross') }}</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-[hsl(var(--border))]">
                  <tr v-for="inv in openInvoices" :key="inv.id">
                    <td class="py-1">
                      <Link :href="`/invoices/${inv.id}`" class="hover:underline">{{ inv.number }}</Link>
                      <div class="text-xs text-[hsl(var(--muted-foreground))]">{{ inv.customer }}</div>
                    </td>
                    <td class="py-1 text-xs">{{ inv.job ?? '' }}</td>
                    <td class="py-1 tabular-nums" :class="inv.due_date && inv.due_date < today ? 'text-[hsl(var(--destructive))]' : ''">{{ inv.due_date ? formatDate(inv.due_date) : '' }}</td>
                    <td class="py-1 text-right tabular-nums">{{ formatCurrency(inv.gross) }}</td>
                  </tr>
                  <tr class="font-semibold">
                    <td class="py-2" colspan="3">{{ t('total') }}</td>
                    <td class="py-2 text-right tabular-nums">{{ formatCurrency(openInvoicesTotal) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </template>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <div class="flex flex-wrap items-center gap-4">
            <CardTitle>{{ t('steering_expected_expenses') }}</CardTitle>
            <div class="inline-flex rounded-md border border-[hsl(var(--border))] p-0.5" role="tablist">
              <button
                v-for="y in [year, year + 1]"
                :key="y"
                type="button"
                role="tab"
                :aria-selected="expectedYear === y"
                class="rounded px-3 py-1 text-sm tabular-nums"
                :class="expectedYear === y ? 'bg-[hsl(var(--muted))] font-semibold' : 'text-[hsl(var(--muted-foreground))]'"
                @click="expectedYear = y"
              >
                {{ y }}
                <span class="ml-1 text-xs text-[hsl(var(--muted-foreground))]">{{ (y === year ? steering.expected_expenses : steering.expected_expenses_next_year)?.rows?.length ?? 0 }}</span>
              </button>
            </div>
          </div>
        </CardHeader>
        <CardContent>
          <p class="mb-3 text-sm text-[hsl(var(--muted-foreground))]">{{ expectedYear === year ? t('steering_expected_expenses_desc') : t('steering_expected_expenses_next_desc') }}</p>
          <p v-if="expected.hourly?.length" class="mb-3 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200">
            {{ t('steering_expected_hourly', { names: expected.hourly.join(', ') }) }}
          </p>
          <p v-if="!expected.rows.length" class="text-sm text-[hsl(var(--muted-foreground))]">{{ t('steering_expected_none') }}</p>
          <div v-else class="overflow-x-auto">
            <table class="w-full text-sm [&_td]:pr-3 [&_th]:pr-3">
              <thead>
                <tr class="border-b text-left text-[hsl(var(--muted-foreground))]">
                  <th class="pb-2">{{ t('date') }}</th>
                  <th class="pb-2">{{ t('description') }}</th>
                  <th class="pb-2">{{ t('source') }}</th>
                  <th class="pb-2">{{ t('account') }}</th>
                  <th class="pb-2 text-right">{{ t('amount') }}</th>
                  <th class="w-20 pb-2"></th>
                </tr>
              </thead>
              <tbody>
                <template v-for="month in expectedMonths" :key="month.key">
                  <tr class="border-b border-[hsl(var(--border))] bg-[hsl(var(--muted))]/40 font-medium">
                    <td class="py-1" colspan="4">{{ month.label }}</td>
                    <td class="py-1 text-right tabular-nums">{{ formatCurrency(month.total) }}</td>
                    <td></td>
                  </tr>
                  <tr v-for="(row, i) in month.rows" :key="month.key + '-' + i" class="border-b border-[hsl(var(--border))]">
                    <td class="py-1 tabular-nums" :class="row.overdue ? 'text-[hsl(var(--destructive))]' : ''">
                      {{ row.date ? formatDate(row.date) : '' }}
                      <span v-if="row.overdue" class="ml-1 text-xs">{{ t('steering_expected_overdue') }}</span>
                    </td>
                    <td class="py-1">
                      <Link v-if="row.link" :href="row.link" class="hover:underline">{{ expectedLabel(row) }}</Link>
                      <template v-else>{{ expectedLabel(row) }}</template>
                    </td>
                    <td class="py-1 text-[hsl(var(--muted-foreground))]">{{ t('steering_expected_source_' + row.source) }}</td>
                    <td class="py-1 tabular-nums text-[hsl(var(--muted-foreground))]">{{ row.account ?? '' }}</td>
                    <td class="py-1 text-right tabular-nums" :class="row.in_result ? '' : 'text-[hsl(var(--muted-foreground))]'">
                      {{ formatCurrency(row.amount) }}
                      <div v-if="!row.in_result" class="text-xs">{{ t('steering_expected_not_in_result') }}</div>
                    </td>
                    <td class="py-1 text-right">
                      <template v-if="row.source === 'planned'">
                        <Button variant="ghost" size="icon" :aria-label="t('steering_planned_done')" :title="t('steering_planned_done')" @click="donePlanned(row)"><Check class="h-4 w-4" /></Button>
                        <Button variant="ghost" size="icon" :aria-label="t('delete')" @click="removePlanned(row)"><Trash2 class="h-4 w-4 text-[hsl(var(--destructive))]" /></Button>
                      </template>
                    </td>
                  </tr>
                </template>
                <template v-for="source in expectedSources" :key="'sum-' + source">
                  <tr v-if="num(expected.by_source[source]) !== 0" class="text-[hsl(var(--muted-foreground))]">
                    <td class="pt-2" colspan="4">{{ t('steering_expected_source_' + source) }}</td>
                    <td class="pt-2 text-right tabular-nums">{{ formatCurrency(expected.by_source[source]) }}</td>
                    <td></td>
                  </tr>
                </template>
                <tr class="font-semibold">
                  <td class="py-2" colspan="4">{{ t('steering_expected_total_net') }}</td>
                  <td class="py-2 text-right tabular-nums">{{ formatCurrency(expected.total) }}</td>
                  <td></td>
                </tr>
                <tr v-if="num(expected.by_source.vat) !== 0" class="text-[hsl(var(--muted-foreground))]">
                  <td class="pb-2" colspan="4">{{ t('steering_expected_vat_total') }}</td>
                  <td class="pb-2 text-right tabular-nums">{{ formatCurrency(expected.by_source.vat) }}</td>
                  <td></td>
                </tr>
              </tbody>
            </table>
          </div>

          <h3 class="mb-2 mt-6 text-sm font-semibold">{{ t('steering_planned_title') }}</h3>
          <p class="mb-3 text-sm text-[hsl(var(--muted-foreground))]">{{ t('steering_planned_desc') }}</p>
          <form class="flex flex-wrap items-end gap-2" @submit.prevent="addPlanned">
            <FormInput id="planned_date" v-model="plannedForm.date" type="date" :label="t('date')" :error="plannedForm.errors.date" class="w-40" />
            <FormInput id="planned_label" v-model="plannedForm.label" :label="t('description')" :placeholder="t('steering_planned_placeholder')" :error="plannedForm.errors.label" class="min-w-[14rem] flex-1" />
            <FormSelect id="planned_account" v-model="plannedForm.account_code" :label="t('account')" :options="accountOptions" :error="plannedForm.errors.account_code" class="w-56" />
            <FormInput id="planned_amount" v-model="plannedForm.amount" type="number" step="0.01" :label="t('steering_planned_amount_net')" :error="plannedForm.errors.amount" class="w-32" />
            <FormInput id="planned_vat" v-model="plannedForm.vat_amount" type="number" step="0.01" min="0" :label="t('steering_planned_vat')" :error="plannedForm.errors.vat_amount" class="w-28" />
            <Button type="submit" size="sm" :disabled="plannedForm.processing"><Plus class="mr-1 h-4 w-4" /> {{ t('add') }}</Button>
          </form>
        </CardContent>
      </Card>

      <Card>
        <CardHeader><CardTitle>{{ t('steering_outlook') }}</CardTitle></CardHeader>
        <CardContent>
          <p class="mb-3 text-sm text-[hsl(var(--muted-foreground))]">{{ t('steering_outlook_desc') }}</p>
          <div class="overflow-x-auto">
            <table class="w-full max-w-xl text-sm">
              <thead>
                <tr class="border-b text-left text-[hsl(var(--muted-foreground))]">
                  <th class="pb-2"></th>
                  <th v-for="o in outlook" :key="o.year" class="pb-2 text-right tabular-nums">{{ o.year }}</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td class="py-1">{{ t('steering_outlook_income') }}</td>
                  <td v-for="o in outlook" :key="o.year" class="py-1 text-right tabular-nums">{{ formatCurrency(o.income) }}</td>
                </tr>
                <tr class="border-b border-[hsl(var(--border))]">
                  <td class="py-1">{{ t('steering_outlook_expenses') }}</td>
                  <td v-for="o in outlook" :key="o.year" class="py-1 text-right tabular-nums">{{ formatCurrency(out(o.expenses)) }}</td>
                </tr>
                <tr class="font-semibold">
                  <td class="py-2">{{ t('steering_outlook_result') }}</td>
                  <td
                    v-for="o in outlook"
                    :key="o.year"
                    class="py-2 text-right tabular-nums"
                    :class="o.result < 0 ? 'text-[hsl(var(--destructive))]' : 'text-green-700 dark:text-green-400'"
                  >{{ formatCurrency(o.result) }}</td>
                </tr>
                <template v-if="steering.outlook">
                  <tr class="text-[hsl(var(--muted-foreground))]">
                    <td class="pt-3">{{ t('steering_outlook_bank_today') }}</td>
                    <td class="pt-3 text-right tabular-nums">{{ formatCurrency(steering.outlook.bank_today) }}</td>
                    <td></td>
                  </tr>
                  <tr class="font-semibold">
                    <td class="py-1">{{ t('steering_outlook_bank_end') }}</td>
                    <td v-for="o in outlook" :key="o.year" class="py-1 text-right tabular-nums" :class="o.bank !== null && o.bank < 0 ? 'text-[hsl(var(--destructive))]' : ''">{{ o.bank !== null ? formatCurrency(o.bank) : '' }}</td>
                  </tr>
                </template>
              </tbody>
            </table>
          </div>

          <template v-if="outlookMonths.length">
            <h3 class="mb-1 mt-6 text-sm font-semibold">{{ t('steering_outlook_months') }}</h3>
            <p class="mb-3 text-sm text-[hsl(var(--muted-foreground))]">{{ t('steering_outlook_months_desc') }}</p>
            <div class="grid gap-6 lg:grid-cols-2">
              <div>
                <div class="mb-1 text-sm font-medium">{{ t('steering_outlook_flow') }}</div>
                <div class="h-64"><Bar :data="flowChart" :options="axisOptions" /></div>
              </div>
              <div>
                <div class="mb-1 text-sm font-medium">{{ t('steering_outlook_bank') }}</div>
                <div class="h-64"><Line :data="bankChart" :options="bankOptions" /></div>
              </div>
            </div>
            <div class="mt-4 overflow-x-auto">
              <table class="w-full text-sm [&_td]:pr-3 [&_th]:pr-3">
                <thead>
                  <tr class="border-b text-left text-[hsl(var(--muted-foreground))]">
                    <th class="pb-2">{{ t('month') }}</th>
                    <th class="pb-2 text-right">{{ t('steering_outlook_income') }}</th>
                    <th class="pb-2 text-right">{{ t('steering_outlook_expenses') }}</th>
                    <th class="pb-2 text-right">{{ t('steering_outlook_result_cumulative') }}</th>
                    <th class="pb-2 text-right">{{ t('steering_outlook_cash_in') }}</th>
                    <th class="pb-2 text-right">{{ t('steering_outlook_cash_out') }}</th>
                    <th class="pb-2 text-right">{{ t('steering_outlook_bank') }}</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-[hsl(var(--border))]">
                  <tr v-for="m in outlookMonths" :key="m.key">
                    <td class="py-1">{{ monthLabel(m.key) }}</td>
                    <td class="py-1 text-right tabular-nums">{{ formatCurrency(m.income) }}</td>
                    <td class="py-1 text-right tabular-nums">{{ formatCurrency(out(m.expenses)) }}</td>
                    <td class="py-1 text-right tabular-nums" :class="num(m.result) < 0 ? 'text-[hsl(var(--destructive))]' : ''">{{ formatCurrency(m.result) }}</td>
                    <td class="py-1 text-right tabular-nums">{{ formatCurrency(m.cash_in) }}</td>
                    <td class="py-1 text-right tabular-nums">{{ formatCurrency(out(m.cash_out)) }}</td>
                    <td class="py-1 text-right tabular-nums" :class="num(m.bank) < 0 ? 'text-[hsl(var(--destructive))]' : ''">{{ formatCurrency(m.bank) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </template>
        </CardContent>
      </Card>

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
                  <th v-for="person in people" :key="person.employee_id" class="pb-2 text-right">{{ person.name }}</th>
                  <th class="w-12 pb-2"></th>
                </tr>
              </thead>
              <tbody class="divide-y divide-[hsl(var(--border))]">
                <template v-for="block in steering.blocks" :key="block.id">
                  <tr class="cursor-pointer hover:bg-[hsl(var(--muted))]/40" @click="toggle('block-' + block.id)">
                    <td class="py-1">
                      <span class="flex items-center gap-1">
                        <component :is="open['block-' + block.id] ? ChevronDown : ChevronRight" class="h-4 w-4 shrink-0" />
                        {{ block.name }}
                      </span>
                    </td>
                    <td class="py-1 text-right tabular-nums">{{ formatCurrency(block.plan) }}</td>
                    <td v-for="person in people" :key="person.employee_id" class="py-1 text-right tabular-nums">
                      {{ formatCurrency(block.people[person.employee_id]?.effect ?? 0) }}
                    </td>
                    <td class="py-1 text-right" @click.stop>
                      <Button v-if="canSteer" variant="ghost" size="icon" :aria-label="t('edit')" @click="editBlock(block)"><Pencil class="h-4 w-4" /></Button>
                    </td>
                  </tr>
                  <template v-if="open['block-' + block.id]">
                    <tr class="text-xs text-[hsl(var(--muted-foreground))]">
                      <td class="py-1 pl-5" colspan="2">
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
                      <td class="py-1 pl-5" colspan="2">{{ block.carry_equal ? t('steering_block_carry_equal') : t('steering_block_carry') }}</td>
                      <td v-for="person in people" :key="person.employee_id" class="py-1 text-right tabular-nums">
                        {{ num(block.people[person.employee_id]?.carry_share) }} % · {{ formatCurrency(block.people[person.employee_id]?.burden ?? 0) }}
                      </td>
                      <td></td>
                    </tr>
                    <tr class="text-xs text-[hsl(var(--muted-foreground))]">
                      <td class="py-1 pl-5" colspan="2">{{ Object.values(block.people).some(p => num(p.credit_share) > 0) ? t('steering_block_credit') : t('steering_block_credit_none') }}</td>
                      <td v-for="person in people" :key="person.employee_id" class="py-1 text-right tabular-nums">
                        {{ num(block.people[person.employee_id]?.credit_share) }} % · {{ formatCurrency(block.people[person.employee_id]?.credit ?? 0) }}
                      </td>
                      <td></td>
                    </tr>
                  </template>
                </template>
              </tbody>
            </table>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader><CardTitle>{{ t('steering_items') }}</CardTitle></CardHeader>
        <CardContent>
          <p class="mb-3 text-sm text-[hsl(var(--muted-foreground))]">{{ t('steering_items_desc') }}</p>
          <table class="mb-4 w-full max-w-3xl text-sm">
            <tbody class="divide-y divide-[hsl(var(--border))]">
              <template v-for="person in drawnRows" :key="'drawn-' + person.employee_id">
                <tr class="cursor-pointer hover:bg-[hsl(var(--muted))]/40" @click="toggle('items-' + person.employee_id)">
                  <td class="py-1 font-medium">
                    <span class="flex items-center gap-1">
                      <component :is="open['items-' + person.employee_id] ? ChevronDown : ChevronRight" class="h-4 w-4 shrink-0" />
                      {{ person.name }}
                    </span>
                  </td>
                  <td class="py-1 text-[hsl(var(--muted-foreground))]">{{ t('steering_items_count', { count: person.items.length }) }}</td>
                  <td class="py-1 text-right font-medium tabular-nums">{{ formatCurrency(person.drawn) }}</td>
                  <td></td>
                </tr>
                <template v-if="open['items-' + person.employee_id]">
                  <tr v-for="item in person.items" :key="item.id">
                    <td></td>
                    <td class="py-1">{{ item.label }}</td>
                    <td class="py-1 text-right tabular-nums">{{ formatCurrency(item.amount) }}</td>
                    <td class="py-1 text-right">
                      <Button v-if="canSteer" variant="ghost" size="icon" :aria-label="t('delete')" @click="removeItem(item)"><Trash2 class="h-4 w-4 text-[hsl(var(--destructive))]" /></Button>
                    </td>
                  </tr>
                  <tr v-if="num(person.acquisitions) !== 0" class="text-[hsl(var(--muted-foreground))]">
                    <td></td>
                    <td class="py-1"><Link :href="`/projects/acquisitions?year=${year}`" class="hover:underline">{{ t('acquisitions') }}</Link></td>
                    <td class="py-1 text-right tabular-nums">{{ formatCurrency(person.acquisitions) }}</td>
                    <td></td>
                  </tr>
                  <tr v-if="num(person.still_due) !== 0" class="text-[hsl(var(--muted-foreground))]">
                    <td></td>
                    <td class="py-1">{{ t('steering_still_due') }}</td>
                    <td class="py-1 text-right tabular-nums">{{ formatCurrency(person.still_due) }}</td>
                    <td></td>
                  </tr>
                </template>
              </template>
            </tbody>
          </table>
          <form v-if="canSteer" class="flex flex-wrap items-end gap-2" @submit.prevent="addItem">
            <FormSelect id="item_employee" v-model="itemForm.employee_id" :label="t('acquisition_person')" :options="employees.map(e => ({ value: e.id, label: e.name }))" />
            <FormInput id="item_label" v-model="itemForm.label" :label="t('steering_item_label')" :placeholder="t('steering_item_placeholder')" :error="itemForm.errors.label" class="min-w-[16rem] flex-1" />
            <FormInput id="item_amount" v-model="itemForm.amount" type="number" step="0.01" :label="t('amount')" :error="itemForm.errors.amount" class="w-36" />
            <Button type="submit" size="sm" :disabled="itemForm.processing"><Plus class="mr-1 h-4 w-4" /> {{ t('add') }}</Button>
          </form>
        </CardContent>
      </Card>
    </div>

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
