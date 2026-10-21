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
import { useTranslations } from '@/lib/useTranslations'
import { useFormatters } from '@/lib/useFormatters'
import { router } from '@inertiajs/vue3'
import { computed, reactive, ref } from 'vue'
import { ChevronDown, ChevronRight, Pencil } from 'lucide-vue-next'

// The general costs of the steering on a page of their own: each cost
// block, who bears it and who is credited. Only owners (canSteer) edit.
const props = defineProps({
  year: Number,
  canSteer: { type: Boolean, default: false },
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
const num = v => Number(v ?? 0)
const open = reactive({})
const toggle = key => { open[key] = !open[key] }
const totalOf = id => props.steering.blocks.reduce((sum, b) => sum + num(b.people[id]?.effect), 0)

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
                <tr class="border-t font-semibold">
                  <td class="py-2">{{ t('total') }}</td>
                  <td class="py-2 text-right tabular-nums">{{ formatCurrency(steering.blocks.reduce((sum, b) => sum + num(b.plan), 0)) }}</td>
                  <td v-for="person in people" :key="person.employee_id" class="py-2 text-right tabular-nums">{{ formatCurrency(totalOf(person.employee_id)) }}</td>
                  <td></td>
                </tr>
              </tbody>
            </table>
          </div>
        </CardContent>
      </Card>


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
