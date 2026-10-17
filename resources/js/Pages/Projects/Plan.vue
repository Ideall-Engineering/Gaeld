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
import { router, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { Copy, Download, Pencil, Plus, Target, Trash2 } from 'lucide-vue-next'

// Plan against actual per cost block (spec 009, stage 3). The plan is typed
// once; the actual comes from the books, without shares allocated to
// customer projects.
const props = defineProps({
  year: Number,
  plan: Object,
  accounts: Array,
  kinds: Array,
  hasPreviousYear: Boolean,
  employees: { type: Array, default: () => [] },
  can: Object,
})

const { t } = useTranslations()
const { formatCurrency } = useFormatters()

const yearOptions = computed(() => {
  const now = new Date().getFullYear()
  return [now + 1, now, now - 1, now - 2].map(y => ({ value: String(y), label: String(y) }))
})
const kindLabel = value => props.kinds.find(k => k.value === value)?.label ?? value
const elapsedPercent = computed(() => Math.round(Number(props.plan.elapsed) * 100))

function changeYear(value) {
  router.get('/projects/plan', { year: value }, { preserveScroll: true })
}

const showForm = ref(false)
const editing = ref(null)
const accountFilter = ref('')
// Stage 5: everyone bears an equal part unless set otherwise; nobody is
// credited until someone is named as having done the work.
const blankPeople = () => props.employees.map(e => ({ employee_id: e.id, carry_share: String(+(100 / Math.max(props.employees.length, 1)).toFixed(4)), credit_share: '0' }))
const blank = () => ({ year: props.year, name: '', kind: 'running', plan_amount: '', notes: '', account_ids: [], ...(props.can.steer ? { people: blankPeople() } : {}) })
const form = useForm(blank())

const visibleAccounts = computed(() => {
  const q = accountFilter.value.trim().toLowerCase()
  return q ? props.accounts.filter(a => `${a.code} ${a.name}`.toLowerCase().includes(q)) : props.accounts
})

function openCreate() {
  editing.value = null
  Object.assign(form, blank())
  form.clearErrors()
  accountFilter.value = ''
  showForm.value = true
}

function openEdit(group) {
  editing.value = group
  Object.assign(form, {
    year: props.year,
    name: group.name,
    kind: group.kind,
    plan_amount: group.plan_from_budgets ? '' : group.plan,
    notes: group.notes ?? '',
    account_ids: group.accounts.map(a => a.id),
    ...(props.can.steer ? {
      people: props.employees.map(e => {
        const p = group.people.find(x => x.employee_id === e.id)
        return { employee_id: e.id, carry_share: p ? p.carry_share : '0', credit_share: p ? p.credit_share : '0' }
      }),
    } : {}),
  })
  if (props.can.steer && group.people.length === 0) form.people = blankPeople()
  form.clearErrors()
  accountFilter.value = ''
  showForm.value = true
}

function toggleAccount(id) {
  const i = form.account_ids.indexOf(id)
  if (i === -1) form.account_ids.push(id)
  else form.account_ids.splice(i, 1)
}

function submit() {
  const options = { preserveScroll: true, onSuccess: () => { showForm.value = false } }
  if (editing.value) form.put(`/projects/plan/${editing.value.id}`, options)
  else form.post('/projects/plan', options)
}

const deleting = ref(null)
function performDelete() {
  router.delete(`/projects/plan/${deleting.value.id}`, { preserveScroll: true, onFinish: () => { deleting.value = null } })
}

function copyPrevious() {
  router.post('/projects/plan/copy-previous', { year: props.year }, { preserveScroll: true })
}

const accountError = computed(() => Object.entries(form.errors).find(([key]) => key.startsWith('account_ids'))?.[1])
</script>

<template>
  <AppLayout :title="t('budget_groups')">
    <Breadcrumb :items="[{ label: t('projects'), href: '/projects' }, { label: t('budget_groups') }]" class="mb-4" />
    <Card>
      <CardHeader>
        <div class="flex flex-wrap items-end justify-between gap-3">
          <CardTitle>{{ t('budget_groups') }}</CardTitle>
          <div class="flex flex-wrap items-end gap-2">
            <FormSelect id="year" :model-value="String(year)" :label="t('year')" :options="yearOptions" @update:model-value="changeYear" />
            <a :href="`/projects/plan/export?year=${year}`"><Button size="sm" variant="outline"><Download class="mr-1 h-4 w-4" /> CSV</Button></a>
            <Button v-if="can.manage && hasPreviousYear" size="sm" variant="outline" @click="copyPrevious"><Copy class="mr-1 h-4 w-4" /> {{ t('budget_groups_copy_previous') }}</Button>
            <Button v-if="can.manage" size="sm" @click="openCreate"><Plus class="mr-1 h-4 w-4" /> {{ t('add') }}</Button>
          </div>
        </div>
      </CardHeader>
      <CardContent>
        <p class="mb-4 text-sm text-[hsl(var(--muted-foreground))]">{{ t('budget_groups_desc', { percent: elapsedPercent }) }}</p>
        <EmptyState
          v-if="plan.groups.length === 0"
          :icon="Target"
          :title="t('budget_groups_empty_title')"
          :description="t('budget_groups_empty_desc')"
          :action-label="can.manage ? t('create_first') : undefined"
          @action="openCreate"
        />
        <div v-else class="overflow-x-auto">
          <table class="w-full text-sm [&_td]:pr-3 [&_th]:pr-3">
            <thead>
              <tr class="border-b text-left text-[hsl(var(--muted-foreground))]">
                <th class="pb-2">{{ t('name') }}</th>
                <th class="pb-2">{{ t('budget_group_accounts') }}</th>
                <th class="pb-2 text-right">{{ t('budget_group_plan') }}</th>
                <th class="pb-2 text-right">{{ t('budget_group_actual') }}</th>
                <th class="pb-2 text-right">{{ t('budget_group_rest') }}</th>
                <th class="pb-2 text-right">{{ t('budget_group_projection') }}</th>
                <th class="pb-2"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-[hsl(var(--border))]">
              <tr v-for="group in plan.groups" :key="group.id">
                <td class="py-2">
                  <div class="font-medium">{{ group.name }}</div>
                  <div class="text-xs text-[hsl(var(--muted-foreground))]">{{ kindLabel(group.kind) }}<span v-if="group.notes"> · {{ group.notes }}</span></div>
                </td>
                <td class="py-2 font-mono text-xs">{{ group.accounts.map(a => a.code).join(', ') }}</td>
                <td class="py-2 text-right tabular-nums">
                  {{ formatCurrency(group.plan) }}
                  <div v-if="group.plan_from_budgets" class="text-xs text-[hsl(var(--muted-foreground))]">{{ t('budget_group_plan_from_budgets') }}</div>
                </td>
                <td class="py-2 text-right tabular-nums">
                  {{ formatCurrency(group.actual) }}
                  <div v-if="group.progress !== null" class="mt-1 h-1.5 w-full rounded bg-[hsl(var(--muted))]">
                    <div class="h-1.5 rounded" :class="group.progress > 100 ? 'bg-[hsl(var(--destructive))]' : 'bg-[hsl(var(--primary))]'" :style="{ width: `${Math.min(group.progress, 100)}%` }" />
                  </div>
                  <div v-if="Number(group.customer_projects) !== 0" class="text-xs text-[hsl(var(--muted-foreground))]">{{ t('budget_group_customer_projects_short', { amount: formatCurrency(group.customer_projects) }) }}</div>
                </td>
                <td class="py-2 text-right tabular-nums" :class="Number(group.rest) < 0 ? 'text-[hsl(var(--destructive))]' : ''">{{ formatCurrency(group.rest) }}</td>
                <td class="py-2 text-right tabular-nums">
                  <Badge v-if="group.over_plan" variant="destructive">{{ formatCurrency(group.projection) }}</Badge>
                  <span v-else>{{ formatCurrency(group.projection) }}</span>
                </td>
                <td class="py-2">
                  <div v-if="can.manage" class="flex justify-end gap-1">
                    <Button variant="ghost" size="icon" :aria-label="t('edit')" @click="openEdit(group)"><Pencil class="h-4 w-4" /></Button>
                    <Button variant="ghost" size="icon" :aria-label="t('delete')" @click="deleting = group"><Trash2 class="h-4 w-4 text-[hsl(var(--destructive))]" /></Button>
                  </div>
                </td>
              </tr>
              <tr class="border-t-2 font-semibold">
                <td class="pt-3" colspan="2">{{ t('total') }}</td>
                <td class="pt-3 text-right tabular-nums">{{ formatCurrency(plan.totals.plan) }}</td>
                <td class="pt-3 text-right tabular-nums">{{ formatCurrency(plan.totals.actual) }}</td>
                <td class="pt-3 text-right tabular-nums">{{ formatCurrency(plan.totals.rest) }}</td>
                <td class="pt-3 text-right tabular-nums">{{ formatCurrency(plan.totals.projection) }}</td>
                <td></td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="plan.ungrouped.length" class="mt-8">
          <h3 class="mb-1 text-sm font-semibold">{{ t('budget_groups_ungrouped') }}</h3>
          <p class="mb-2 text-xs text-[hsl(var(--muted-foreground))]">{{ t('budget_groups_ungrouped_desc') }}</p>
          <table class="w-full max-w-xl text-sm">
            <tbody class="divide-y divide-[hsl(var(--border))]">
              <tr v-for="account in plan.ungrouped" :key="account.id">
                <td class="py-1 font-mono text-xs">{{ account.code }}</td>
                <td class="py-1">{{ account.name }}</td>
                <td class="py-1 text-right tabular-nums">{{ formatCurrency(account.actual) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </CardContent>
    </Card>

    <Modal :open="showForm" :title="editing ? t('edit') : t('add')" size="xl" @close="showForm = false">
      <form class="space-y-4" @submit.prevent="submit">
        <div class="grid gap-4 sm:grid-cols-3">
          <FormInput id="name" v-model="form.name" :label="t('name')" :error="form.errors.name" required class="sm:col-span-2" />
          <FormSelect id="kind" v-model="form.kind" :label="t('budget_group_kind')" :options="kinds" :error="form.errors.kind" />
        </div>
        <FormInput id="plan_amount" v-model="form.plan_amount" type="number" step="0.01" min="0" :label="t('budget_group_plan')" :placeholder="t('budget_group_plan_placeholder')" :error="form.errors.plan_amount" />
        <div>
          <div class="mb-1 flex items-end justify-between gap-2">
            <label class="text-sm font-medium">{{ t('budget_group_accounts') }} ({{ form.account_ids.length }})</label>
            <input v-model="accountFilter" type="search" :placeholder="t('search')" class="h-8 rounded-md border border-[hsl(var(--input))] px-2 text-sm" />
          </div>
          <div class="max-h-64 overflow-y-auto rounded-md border border-[hsl(var(--border))] p-2">
            <label v-for="account in visibleAccounts" :key="account.id" class="flex items-center gap-2 py-0.5 text-sm">
              <input type="checkbox" class="h-4 w-4 rounded border-[hsl(var(--input))]" :checked="form.account_ids.includes(account.id)" @change="toggleAccount(account.id)" />
              <span class="font-mono text-xs">{{ account.code }}</span> {{ account.name }}
            </label>
          </div>
          <p v-if="accountError" class="mt-1 text-xs text-[hsl(var(--destructive))]">{{ accountError }}</p>
        </div>
        <div v-if="can.steer && form.people" class="rounded-md border border-[hsl(var(--border))] p-3">
          <div class="mb-1 text-sm font-medium">{{ t('budget_group_keys') }}</div>
          <p class="mb-2 text-xs text-[hsl(var(--muted-foreground))]">{{ t('budget_group_keys_hint') }}</p>
          <div class="grid grid-cols-3 gap-2 text-xs text-[hsl(var(--muted-foreground))]">
            <span></span><span>{{ t('budget_group_carry') }}</span><span>{{ t('budget_group_credit') }}</span>
          </div>
          <div v-for="(person, index) in form.people" :key="person.employee_id" class="grid grid-cols-3 items-center gap-2">
            <span class="text-sm">{{ employees.find(e => e.id === person.employee_id)?.name }}</span>
            <FormInput :id="`carry_${index}`" v-model="person.carry_share" type="number" step="0.01" min="0" max="100" />
            <FormInput :id="`credit_${index}`" v-model="person.credit_share" type="number" step="0.01" min="0" max="100" />
          </div>
        </div>
        <FormTextarea id="notes" v-model="form.notes" :label="t('notes')" :error="form.errors.notes" />
        <div class="flex justify-end gap-2">
          <Button variant="outline" type="button" @click="showForm = false">{{ t('cancel') }}</Button>
          <Button type="submit" :disabled="form.processing" :loading="form.processing">{{ t('save') }}</Button>
        </div>
      </form>
    </Modal>

    <ConfirmDialog :open="!!deleting" :title="t('confirm_delete')" :message="deleting?.name ?? ''" @confirm="performDelete" @cancel="deleting = null" />
  </AppLayout>
</template>
