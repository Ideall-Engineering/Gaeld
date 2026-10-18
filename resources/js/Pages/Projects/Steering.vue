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
import { ChevronDown, ChevronRight, Pencil, Plus, Trash2 } from 'lucide-vue-next'

// What each person earned and drew, and the room left for performance pay
// (spec 009, stage 5; spec 010, US5).
const props = defineProps({
  year: Number,
  steering: Object,
  employees: Array,
  groups: { type: Array, default: () => [] },
  blockOptions: { type: Object, default: () => ({ accounts: [], kinds: [] }) },
})

const { t } = useTranslations()
const { formatCurrency } = useFormatters()

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

// Drawn, one foldable row per person: plan items and acquisitions.
const drawnRows = computed(() => people.value
  .map(p => ({ ...p, items: itemsOf(p.employee_id) }))
  .filter(p => p.items.length || num(p.acquisitions) !== 0))

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
                              <span class="text-[hsl(var(--muted-foreground))]"><template v-if="job.group"> · {{ job.group }}</template> · {{ t('project_status_' + job.status) }}<template v-if="!job.booked"> · {{ t('person_expected') }}</template></span>
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
                          <tr v-if="!itemsOf(person.employee_id).length && Number(person.acquisitions) === 0">
                            <td colspan="2" class="py-1 text-[hsl(var(--muted-foreground))]">{{ t('steering_drawn_empty') }}</td>
                          </tr>
                        </tbody>
                      </table>
                    </td>
                  </tr>
                  <tr class="border-t text-base font-semibold">
                    <td class="py-2">{{ t('steering_play') }}</td>
                    <td class="py-2 text-right tabular-nums" :class="Number(person.play) < 0 ? 'text-[hsl(var(--destructive))]' : 'text-green-700 dark:text-green-400'">{{ formatCurrency(person.play) }}</td>
                  </tr>
                  <tr class="text-xs text-[hsl(var(--muted-foreground))]">
                    <td class="py-1 pl-5">{{ t('steering_play_without_possible') }}</td>
                    <td class="py-1 text-right tabular-nums">{{ formatCurrency(person.play_without_possible) }}</td>
                  </tr>
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
                      <Button variant="ghost" size="icon" :aria-label="t('edit')" @click="editBlock(block)"><Pencil class="h-4 w-4" /></Button>
                    </td>
                  </tr>
                  <template v-if="open['block-' + block.id]">
                    <tr class="text-xs text-[hsl(var(--muted-foreground))]">
                      <td class="py-1 pl-5" colspan="2">
                        <template v-if="block.plan_fixed">{{ t('steering_block_plan_fixed') }}</template>
                        <template v-else-if="block.accounts?.length">
                          <div v-for="a in block.accounts" :key="a.code" class="flex justify-between gap-3">
                            <span>{{ a.code }} {{ a.name }}: {{ t('steering_block_monthly', { amount: formatCurrency(a.monthly) }) }}</span>
                            <span class="tabular-nums">{{ formatCurrency(a.yearly) }}</span>
                          </div>
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
                      <Button variant="ghost" size="icon" :aria-label="t('delete')" @click="removeItem(item)"><Trash2 class="h-4 w-4 text-[hsl(var(--destructive))]" /></Button>
                    </td>
                  </tr>
                  <tr v-if="num(person.acquisitions) !== 0" class="text-[hsl(var(--muted-foreground))]">
                    <td></td>
                    <td class="py-1"><Link :href="`/projects/acquisitions?year=${year}`" class="hover:underline">{{ t('acquisitions') }}</Link></td>
                    <td class="py-1 text-right tabular-nums">{{ formatCurrency(person.acquisitions) }}</td>
                    <td></td>
                  </tr>
                </template>
              </template>
            </tbody>
          </table>
          <form class="flex flex-wrap items-end gap-2" @submit.prevent="addItem">
            <FormSelect id="item_employee" v-model="itemForm.employee_id" :label="t('acquisition_person')" :options="employees.map(e => ({ value: e.id, label: e.name }))" />
            <FormInput id="item_label" v-model="itemForm.label" :label="t('steering_item_label')" :placeholder="t('steering_item_placeholder')" :error="itemForm.errors.label" class="min-w-[16rem] flex-1" />
            <FormInput id="item_amount" v-model="itemForm.amount" type="number" step="0.01" :label="t('amount')" :error="itemForm.errors.amount" class="w-36" />
            <Button type="submit" size="sm" :disabled="itemForm.processing"><Plus class="mr-1 h-4 w-4" /> {{ t('add') }}</Button>
          </form>
        </CardContent>
      </Card>
    </div>

    <BudgetGroupFormModal
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
