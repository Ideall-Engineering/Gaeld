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
import { useTranslations } from '@/lib/useTranslations'
import { useFormatters } from '@/lib/useFormatters'
import { router, useForm } from '@inertiajs/vue3'
import { computed } from 'vue'
import { Plus, Trash2 } from 'lucide-vue-next'

// Contribution, drawings and what is left per person (spec 009, stage 5).
const props = defineProps({
  year: Number,
  steering: Object,
  employees: Array,
})

const { t } = useTranslations()
const { formatCurrency } = useFormatters()

const people = computed(() => props.steering.people)
const statuses = ['done', 'in_progress', 'pipeline', 'possible']
const yearOptions = computed(() => {
  const now = new Date().getFullYear()
  return [now + 1, now, now - 1, now - 2].map(y => ({ value: String(y), label: String(y) }))
})

const summary = [
  { field: 'contribution', strong: false },
  { field: 'effect', strong: false },
  { field: 'value', strong: true },
  { field: 'drawn', strong: false, negative: true },
  { field: 'play', strong: true },
  { field: 'play_without_possible', strong: false },
]

const sum = (field) => people.value.reduce((total, p) => total + Number(p[field] || 0), 0)
const statusTotal = (status) => people.value.reduce((total, p) => total + Number(p.by_status[status] || 0), 0)
const nameOf = id => props.employees.find(e => e.id === id)?.name ?? ''

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
    <Breadcrumb :items="[{ label: t('projects'), href: '/projects' }, { label: t('steering') }]" class="mb-4" />
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
          <div class="overflow-x-auto">
            <table class="w-full text-sm [&_td]:pr-3 [&_th]:pr-3">
              <thead>
                <tr class="border-b text-left text-[hsl(var(--muted-foreground))]">
                  <th class="pb-2"></th>
                  <th v-for="person in people" :key="person.employee_id" class="pb-2 text-right">{{ person.name }}</th>
                  <th class="pb-2 text-right">{{ t('total') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-[hsl(var(--border))]">
                <tr v-for="status in statuses" :key="status" class="text-[hsl(var(--muted-foreground))]">
                  <td class="py-1 pl-4">{{ t('steering_projects_' + status) }}</td>
                  <td v-for="person in people" :key="person.employee_id" class="py-1 text-right tabular-nums">{{ formatCurrency(person.by_status[status]) }}</td>
                  <td class="py-1 text-right tabular-nums">{{ formatCurrency(statusTotal(status)) }}</td>
                </tr>
                <tr v-for="row in summary" :key="row.field" :class="row.strong ? 'font-semibold' : ''">
                  <td class="py-2">{{ t('steering_' + row.field) }}</td>
                  <td v-for="person in people" :key="person.employee_id" class="py-2 text-right tabular-nums" :class="row.field.startsWith('play') && Number(person[row.field]) < 0 ? 'text-[hsl(var(--destructive))]' : ''">
                    {{ formatCurrency(row.negative ? -person[row.field] : person[row.field]) }}
                  </td>
                  <td class="py-2 text-right tabular-nums">{{ formatCurrency(row.negative ? -sum(row.field) : sum(row.field)) }}</td>
                </tr>
                <tr class="text-xs text-[hsl(var(--muted-foreground))]">
                  <td class="py-2">{{ t('steering_booked_salary') }}</td>
                  <td v-for="person in people" :key="person.employee_id" class="py-2 text-right tabular-nums">{{ formatCurrency(person.booked_salary) }}</td>
                  <td class="py-2 text-right tabular-nums">{{ formatCurrency(sum('booked_salary')) }}</td>
                </tr>
              </tbody>
            </table>
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
                </tr>
              </thead>
              <tbody class="divide-y divide-[hsl(var(--border))]">
                <tr v-for="block in steering.blocks" :key="block.name">
                  <td class="py-1">{{ block.name }}</td>
                  <td class="py-1 text-right tabular-nums">{{ formatCurrency(block.plan) }}</td>
                  <td v-for="person in people" :key="person.employee_id" class="py-1 text-right tabular-nums">
                    {{ formatCurrency(block.people[person.employee_id]?.effect ?? 0) }}
                  </td>
                </tr>
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
              <tr v-for="item in steering.items" :key="item.id">
                <td class="py-1">{{ nameOf(item.employee_id) }}</td>
                <td class="py-1">{{ item.label }}</td>
                <td class="py-1 text-right tabular-nums">{{ formatCurrency(item.amount) }}</td>
                <td class="py-1 text-right">
                  <Button variant="ghost" size="icon" :aria-label="t('delete')" @click="removeItem(item)"><Trash2 class="h-4 w-4 text-[hsl(var(--destructive))]" /></Button>
                </td>
              </tr>
              <tr v-for="person in people" :key="'acq-' + person.employee_id" class="text-[hsl(var(--muted-foreground))]">
                <td class="py-1">{{ person.name }}</td>
                <td class="py-1">{{ t('acquisitions') }}</td>
                <td class="py-1 text-right tabular-nums">{{ formatCurrency(person.acquisitions) }}</td>
                <td></td>
              </tr>
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
  </AppLayout>
</template>
