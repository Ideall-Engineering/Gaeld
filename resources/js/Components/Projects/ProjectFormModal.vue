<script setup>
import { computed, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import Modal from '@/Components/UI/Modal.vue'
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue'
import Button from '@/Components/UI/Button.vue'
import FormInput from '@/Components/UI/FormInput.vue'
import FormSelect from '@/Components/UI/FormSelect.vue'
import FormTextarea from '@/Components/UI/FormTextarea.vue'
import SearchableSelect from '@/Components/UI/SearchableSelect.vue'
import { useTranslations } from '@/lib/useTranslations'
import { ChevronDown, ChevronRight, Plus, Trash2 } from 'lucide-vue-next'

// Create or edit a job or a project (spec 010). The everyday fields come
// first; code, responsible person, billing month and notes sit under
// "more". Without a code Gäld picks the next free one.
const props = defineProps({
  open: { type: Boolean, default: false },
  project: { type: Object, default: null },
  // Preset for a new record, e.g. { kind: 'area' } or { parent_id: 7 }.
  preset: { type: Object, default: () => ({}) },
  options: { type: Object, required: true },
  canSteer: { type: Boolean, default: false },
  // Offer deleting the record; the server refuses it while receipts or
  // jobs hang on it, and the reason shows in the confirmation.
  canDelete: { type: Boolean, default: false },
  // Sent along with a new record, e.g. { allocate_line_id: 12 } to give the
  // new job a receipt right away (spec 010).
  extra: { type: Object, default: () => ({}) },
})

const emit = defineEmits(['close'])
const { t } = useTranslations()

const blank = () => ({
  code: '',
  name: '',
  parent_id: '',
  kind: 'project',
  status: 'in_progress',
  contact_id: '',
  responsible_employee_id: '',
  target_date: '',
  billing_month: '',
  payment_expected_on: '',
  expected_amount: '',
  notes: '',
  is_active: true,
  shares: [],
})

const form = useForm(blank())
const showMore = ref(false)

// Assigned field by field: after a save Inertia keeps the submitted values
// as the form's defaults, so reset() would bring back the last record.
watch(() => [props.open, props.project?.id], ([open]) => {
  if (!open) return
  const p = props.project
  Object.assign(form, blank(), p
    ? {
        code: p.code,
        name: p.name,
        parent_id: p.parent_id ?? '',
        kind: p.kind,
        status: p.status,
        contact_id: p.contact_id ?? '',
        responsible_employee_id: p.responsible_employee_id ?? '',
        target_date: p.target_date ?? '',
        billing_month: p.billing_month ?? '',
        payment_expected_on: p.payment_expected_on ?? '',
        expected_amount: p.expected_amount ?? '',
        notes: p.notes ?? '',
        is_active: p.is_active,
        shares: (p.shares ?? []).map(s => ({ ...s })),
      }
    : { ...props.preset })
  form.clearErrors()
  showMore.value = !!(p && (p.notes || p.responsible_employee_id || p.billing_month || p.payment_expected_on))
}, { immediate: true })

const isGroup = computed(() => form.kind === 'area')
const title = computed(() => {
  if (props.project) return isGroup.value ? t('group_edit') : t('job_edit')
  return isGroup.value ? t('group_new') : t('job_new')
})

const kindOptions = computed(() => props.options.kinds.map(k => ({ value: k.value, label: k.label })))
const groupOptions = computed(() => [
  { value: '', label: t('project_no_area') },
  ...props.options.projects
    .filter(p => p.kind === 'area' && p.is_active && p.id !== props.project?.id)
    .map(p => ({ value: p.id, label: p.name })),
])
const contactOptions = computed(() => [{ value: '', label: t('none') }, ...props.options.contacts.map(c => ({ value: c.id, label: c.name }))])
const employeeOptions = computed(() => [{ value: '', label: t('none') }, ...props.options.employees.map(e => ({ value: e.id, label: e.name }))])
const shareTotal = computed(() => form.shares.reduce((sum, s) => sum + (parseFloat(s.share) || 0), 0))

function alone(employee) {
  form.shares = [{ employee_id: employee.id, share: '100' }]
}

function evenly() {
  const people = props.options.employees
  const part = Math.floor((10000 / people.length)) / 100
  form.shares = people.map((e, i) => ({
    employee_id: e.id,
    share: String(i === people.length - 1 ? +(100 - part * (people.length - 1)).toFixed(2) : part),
  }))
}

const confirmingDelete = ref(false)
const deleteErrors = ref({})
const deleteProcessing = ref(false)
function askDelete() {
  deleteErrors.value = {}
  confirmingDelete.value = true
}
function performDelete() {
  deleteProcessing.value = true
  router.delete(`/projects/${props.project.id}`, {
    preserveScroll: true,
    onSuccess: () => { confirmingDelete.value = false; emit('close') },
    onError: errors => { deleteErrors.value = errors },
    onFinish: () => { deleteProcessing.value = false },
  })
}

function submit() {
  const options = { preserveScroll: true, onSuccess: () => emit('close') }
  if (props.project) {
    form.put(`/projects/${props.project.id}`, options)
  } else {
    form.transform(data => ({ ...data, ...props.extra })).post('/projects', options)
  }
}
</script>

<template>
  <Modal :open="open" :title="title" @close="emit('close')">
    <form class="space-y-4" @submit.prevent="submit">
      <FormInput id="name" v-model="form.name" :label="t('name')" :error="form.errors.name || form.errors.code" required autofocus />

      <div class="grid gap-4 sm:grid-cols-2">
        <FormSelect id="kind" v-model="form.kind" :label="t('project_kind')" :options="kindOptions" :error="form.errors.kind" required />
        <FormSelect v-if="!isGroup" id="parent_id" v-model="form.parent_id" :label="t('project_parent')" :options="groupOptions" :error="form.errors.parent_id" />
      </div>

      <template v-if="!isGroup">
        <div class="grid gap-4 sm:grid-cols-2">
          <SearchableSelect id="contact_id" v-model="form.contact_id" :label="t('project_customer')" :options="contactOptions" :error="form.errors.contact_id" />
          <FormSelect id="status" v-model="form.status" :label="t('status')" :options="options.statuses" :error="form.errors.status" required />
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
          <FormInput id="expected_amount" v-model="form.expected_amount" type="number" step="0.01" min="0" :label="t('project_expected_amount')" :error="form.errors.expected_amount" />
          <FormInput id="target_date" v-model="form.target_date" type="date" :label="t('project_target_date')" :error="form.errors.target_date" />
        </div>
      </template>

      <div v-if="canSteer" class="rounded-md border border-[hsl(var(--border))] p-3">
        <div class="mb-1 text-sm font-medium">{{ t('project_shares') }}</div>
        <p class="mb-2 text-xs text-[hsl(var(--muted-foreground))]">{{ t('project_shares_hint') }}</p>
        <div class="mb-2 flex flex-wrap gap-2">
          <Button v-for="employee in options.employees" :key="employee.id" type="button" size="sm" variant="outline" @click="alone(employee)">
            {{ t('job_quick_alone', { name: employee.name.split(' ')[0] }) }}
          </Button>
          <Button v-if="options.employees.length > 1" type="button" size="sm" variant="outline" @click="evenly">{{ t('job_quick_half') }}</Button>
        </div>
        <div v-for="(share, index) in form.shares" :key="index" class="mb-2 flex items-end gap-2">
          <FormSelect :id="`share_person_${index}`" v-model="share.employee_id" :options="employeeOptions.slice(1)" class="flex-1" />
          <FormInput :id="`share_value_${index}`" v-model="share.share" type="number" step="0.01" min="0" max="100" class="w-28" :aria-label="t('allocation_share')" />
          <Button type="button" variant="ghost" size="icon" :aria-label="t('delete')" @click="form.shares.splice(index, 1)"><Trash2 class="h-4 w-4" /></Button>
        </div>
        <div class="flex items-center justify-between text-xs">
          <Button type="button" size="sm" variant="ghost" @click="form.shares.push({ employee_id: options.employees[0]?.id ?? '', share: form.shares.length ? '' : '100' })">
            <Plus class="mr-1 h-3 w-3" /> {{ t('acquisition_add_person') }}
          </Button>
          <span v-if="form.shares.length" :class="Math.abs(shareTotal - 100) > 0.001 ? 'text-[hsl(var(--destructive))]' : 'text-[hsl(var(--muted-foreground))]'">
            {{ shareTotal.toFixed(2) }} %
          </span>
        </div>
        <p v-if="form.errors.shares" class="mt-1 text-xs text-[hsl(var(--destructive))]">{{ form.errors.shares }}</p>
      </div>

      <button type="button" class="flex items-center gap-1 text-sm text-[hsl(var(--muted-foreground))] hover:text-[hsl(var(--foreground))]" @click="showMore = !showMore">
        <component :is="showMore ? ChevronDown : ChevronRight" class="h-4 w-4" /> {{ t('more') }}
      </button>
      <div v-if="showMore" class="space-y-4">
        <div class="grid gap-4 sm:grid-cols-2">
          <FormInput id="code" v-model="form.code" :label="t('code')" :error="form.errors.code" />
          <FormInput v-if="!isGroup" id="billing_month" v-model="form.billing_month" type="month" :label="t('project_billing_month')" :error="form.errors.billing_month" />
          <FormInput v-if="!isGroup" id="payment_expected_on" v-model="form.payment_expected_on" type="date" :label="t('project_payment_expected_on')" :error="form.errors.payment_expected_on" />
        </div>
        <SearchableSelect v-if="!isGroup" id="responsible_employee_id" v-model="form.responsible_employee_id" :label="t('project_responsible')" :options="employeeOptions" :error="form.errors.responsible_employee_id" />
        <FormTextarea id="notes" v-model="form.notes" :label="t('notes')" :error="form.errors.notes" />
        <label v-if="project" class="flex items-center gap-2 text-sm">
          <input v-model="form.is_active" type="checkbox" class="h-4 w-4 rounded border-[hsl(var(--input))]" />
          {{ t('active') }}
        </label>
      </div>

      <div class="flex justify-end gap-2">
        <Button v-if="project && canDelete" variant="ghost" type="button" class="mr-auto text-[hsl(var(--destructive))]" @click="askDelete">
          <Trash2 class="mr-1 h-4 w-4" /> {{ t('delete') }}
        </Button>
        <Button variant="outline" type="button" @click="emit('close')">{{ t('cancel') }}</Button>
        <Button type="submit" :disabled="form.processing" :loading="form.processing">{{ t('save') }}</Button>
      </div>
    </form>
  </Modal>
  <ConfirmDialog
    :open="confirmingDelete"
    :title="t('confirm_delete')"
    :message="project?.name ?? ''"
    :processing="deleteProcessing"
    :errors="deleteErrors"
    @confirm="performDelete"
    @cancel="confirmingDelete = false"
  />
</template>
