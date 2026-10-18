<script setup>
import { computed, ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Modal from '@/Components/UI/Modal.vue'
import Button from '@/Components/UI/Button.vue'
import FormInput from '@/Components/UI/FormInput.vue'
import FormSelect from '@/Components/UI/FormSelect.vue'
import FormTextarea from '@/Components/UI/FormTextarea.vue'
import { useTranslations } from '@/lib/useTranslations'
import { ChevronDown, ChevronRight } from 'lucide-vue-next'

// Create or edit a cost block (spec 009, stage 3 and 5; spec 010). Used on
// the jobs page and on the people page, so a block can be changed where
// its effect is seen. Who bears it comes first, with quick buttons for
// "shared" and "one person alone", as in the spreadsheet.
const props = defineProps({
  open: { type: Boolean, default: false },
  group: { type: Object, default: null },
  year: { type: Number, required: true },
  accounts: { type: Array, default: () => [] },
  kinds: { type: Array, default: () => [] },
  employees: { type: Array, default: () => [] },
  canSteer: { type: Boolean, default: false },
})

const emit = defineEmits(['close'])
const { t } = useTranslations()

const accountFilter = ref('')
const showAccounts = ref(false)

// Everyone bears an equal part unless set otherwise; nobody is credited
// until someone is named as having done the work.
function equalCarry() {
  const n = Math.max(props.employees.length, 1)
  const equal = Math.floor((100 / n) * 10000) / 10000
  return props.employees.map((e, i) => ({
    employee_id: e.id,
    carry_share: String(i === props.employees.length - 1 ? +(100 - equal * (n - 1)).toFixed(4) : equal),
    credit_share: '0',
  }))
}

const blank = () => ({
  year: props.year,
  name: '',
  kind: 'running',
  plan_amount: '',
  notes: '',
  account_ids: [],
  ...(props.canSteer ? { people: equalCarry() } : {}),
})
const form = useForm(blank())

watch(() => [props.open, props.group?.id], ([open]) => {
  if (!open) return
  const g = props.group
  Object.assign(form, blank(), g
    ? {
        name: g.name,
        kind: g.kind,
        plan_amount: g.plan_from_budgets ? '' : g.plan,
        notes: g.notes ?? '',
        account_ids: g.accounts.map(a => a.id),
        ...(props.canSteer && g.people?.length
          ? {
              people: props.employees.map(e => {
                const p = g.people.find(x => x.employee_id === e.id)
                return { employee_id: e.id, carry_share: p ? p.carry_share : '0', credit_share: p ? p.credit_share : '0' }
              }),
            }
          : {}),
      }
    : {})
  form.clearErrors()
  accountFilter.value = ''
  showAccounts.value = !g
}, { immediate: true })

const visibleAccounts = computed(() => {
  const q = accountFilter.value.trim().toLowerCase()
  return q ? props.accounts.filter(a => `${a.code} ${a.name}`.toLowerCase().includes(q)) : props.accounts
})
const chosenAccounts = computed(() => props.accounts.filter(a => form.account_ids.includes(a.id)))

function toggleAccount(id) {
  const i = form.account_ids.indexOf(id)
  if (i === -1) form.account_ids.push(id)
  else form.account_ids.splice(i, 1)
}

function shared() {
  const credit = Object.fromEntries((form.people ?? []).map(p => [p.employee_id, p.credit_share]))
  form.people = equalCarry().map(p => ({ ...p, credit_share: credit[p.employee_id] ?? '0' }))
}
function alone(employee) {
  form.people = form.people.map(p => ({ ...p, carry_share: p.employee_id === employee.id ? '100' : '0' }))
}
function creditTo(employee) {
  form.people = form.people.map(p => ({ ...p, credit_share: employee && p.employee_id === employee.id ? '100' : '0' }))
}
const firstName = e => e.name.split(' ')[0]

function submit() {
  const options = { preserveScroll: true, onSuccess: () => emit('close') }
  // Rows without carry or credit say nothing; leave them out.
  form.transform(data => (data.people
    ? { ...data, people: data.people.filter(p => Number(p.carry_share) > 0 || Number(p.credit_share) > 0) }
    : data))
  if (props.group) form.put(`/projects/plan/${props.group.id}`, options)
  else form.post('/projects/plan', options)
}

const accountError = computed(() => Object.entries(form.errors).find(([key]) => key.startsWith('account_ids'))?.[1])
</script>

<template>
  <Modal :open="open" :title="group ? t('budget_group_edit') : t('budget_group_new')" size="xl" @close="emit('close')">
    <form class="space-y-4" @submit.prevent="submit">
      <div class="grid gap-4 sm:grid-cols-3">
        <FormInput id="block_name" v-model="form.name" :label="t('name')" :error="form.errors.name" required class="sm:col-span-2" />
        <FormSelect id="block_kind" v-model="form.kind" :label="t('budget_group_kind')" :options="kinds" :error="form.errors.kind" />
      </div>
      <FormInput id="block_plan" v-model="form.plan_amount" type="number" step="0.01" min="0" :label="t('budget_group_plan')" :placeholder="t('budget_group_plan_placeholder')" :error="form.errors.plan_amount" />

      <div v-if="canSteer && form.people" class="rounded-md border border-[hsl(var(--border))] p-3">
        <div class="mb-1 text-sm font-medium">{{ t('budget_group_keys') }}</div>
        <p class="mb-2 text-xs text-[hsl(var(--muted-foreground))]">{{ t('budget_group_keys_hint') }}</p>
        <div class="mb-1 text-xs text-[hsl(var(--muted-foreground))]">{{ t('budget_group_carry') }}</div>
        <div class="mb-3 flex flex-wrap gap-2">
          <Button v-if="employees.length > 1" type="button" size="sm" variant="outline" @click="shared">{{ t('budget_group_quick_shared') }}</Button>
          <Button v-for="employee in employees" :key="'carry-' + employee.id" type="button" size="sm" variant="outline" @click="alone(employee)">
            {{ t('job_quick_alone', { name: firstName(employee) }) }}
          </Button>
        </div>
        <div class="mb-1 text-xs text-[hsl(var(--muted-foreground))]">{{ t('budget_group_credit') }}</div>
        <div class="mb-3 flex flex-wrap gap-2">
          <Button type="button" size="sm" variant="outline" @click="creditTo(null)">{{ t('budget_group_quick_external') }}</Button>
          <Button v-for="employee in employees" :key="'credit-' + employee.id" type="button" size="sm" variant="outline" @click="creditTo(employee)">
            {{ t('budget_group_quick_did', { name: firstName(employee) }) }}
          </Button>
        </div>
        <div class="grid grid-cols-3 gap-2 text-xs text-[hsl(var(--muted-foreground))]">
          <span></span><span>{{ t('budget_group_carry') }}</span><span>{{ t('budget_group_credit') }}</span>
        </div>
        <div v-for="(person, index) in form.people" :key="person.employee_id" class="grid grid-cols-3 items-center gap-2">
          <span class="text-sm">{{ employees.find(e => e.id === person.employee_id)?.name }}</span>
          <FormInput :id="`carry_${index}`" v-model="person.carry_share" type="number" step="0.01" min="0" max="100" :aria-label="t('budget_group_carry')" />
          <FormInput :id="`credit_${index}`" v-model="person.credit_share" type="number" step="0.01" min="0" max="100" :aria-label="t('budget_group_credit')" />
        </div>
        <p v-if="form.errors.people" class="mt-1 text-xs text-[hsl(var(--destructive))]">{{ form.errors.people }}</p>
      </div>

      <div>
        <button type="button" class="flex items-center gap-1 text-sm font-medium" @click="showAccounts = !showAccounts">
          <component :is="showAccounts ? ChevronDown : ChevronRight" class="h-4 w-4" />
          {{ t('budget_group_accounts') }} ({{ form.account_ids.length }})
          <span v-if="!showAccounts && chosenAccounts.length" class="font-mono text-xs font-normal text-[hsl(var(--muted-foreground))]">{{ chosenAccounts.map(a => a.code).join(', ') }}</span>
        </button>
        <div v-if="showAccounts" class="mt-2">
          <input v-model="accountFilter" type="search" :placeholder="t('search')" :aria-label="t('search')" class="mb-1 h-8 w-full rounded-md border border-[hsl(var(--input))] bg-transparent px-2 text-sm" />
          <div class="max-h-64 overflow-y-auto rounded-md border border-[hsl(var(--border))] p-2">
            <label v-for="account in visibleAccounts" :key="account.id" class="flex items-center gap-2 py-0.5 text-sm">
              <input type="checkbox" class="h-4 w-4 rounded border-[hsl(var(--input))]" :checked="form.account_ids.includes(account.id)" @change="toggleAccount(account.id)" />
              <span class="font-mono text-xs">{{ account.code }}</span> {{ account.name }}
            </label>
          </div>
        </div>
        <p v-if="accountError" class="mt-1 text-xs text-[hsl(var(--destructive))]">{{ accountError }}</p>
      </div>

      <FormTextarea id="block_notes" v-model="form.notes" :label="t('notes')" :error="form.errors.notes" />
      <div class="flex justify-end gap-2">
        <Button variant="outline" type="button" @click="emit('close')">{{ t('cancel') }}</Button>
        <Button type="submit" :disabled="form.processing" :loading="form.processing">{{ t('save') }}</Button>
      </div>
    </form>
  </Modal>
</template>
