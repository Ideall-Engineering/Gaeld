<script setup>
import AppLayout from '@/Components/AppLayout.vue'
import Card from '@/Components/UI/Card.vue'
import CardHeader from '@/Components/UI/CardHeader.vue'
import CardTitle from '@/Components/UI/CardTitle.vue'
import CardContent from '@/Components/UI/CardContent.vue'
import Badge from '@/Components/UI/Badge.vue'
import Button from '@/Components/UI/Button.vue'
import DataTable from '@/Components/UI/DataTable.vue'
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue'
import Modal from '@/Components/UI/Modal.vue'
import FormInput from '@/Components/UI/FormInput.vue'
import FormSelect from '@/Components/UI/FormSelect.vue'
import FormTextarea from '@/Components/UI/FormTextarea.vue'
import SearchableSelect from '@/Components/UI/SearchableSelect.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'
import { useTranslations } from '@/lib/useTranslations'
import { formatCurrency } from '@/lib/utils'
import { computed, ref } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import { Plus, Pencil, Trash2, FolderKanban, BarChart3, ListChecks } from 'lucide-vue-next'

const props = defineProps({
  projects: Array,
  contacts: Array,
  employees: Array,
  kinds: Array,
  statuses: Array,
  openReviews: Number,
  can: Object,
})

const { t } = useTranslations()

const statusFilter = ref('')
const kindFilter = ref('')
const showInactive = ref(false)

const statusVariant = {
  pipeline: 'info',
  possible: 'secondary',
  in_progress: 'warning',
  done: 'success',
  lost: 'destructive',
}

const statusLabel = (value) => props.statuses.find(s => s.value === value)?.label ?? value
const kindLabel = (value) => props.kinds.find(k => k.value === value)?.label ?? value

const rows = computed(() => props.projects.filter(p =>
  (showInactive.value || p.is_active)
  && (!statusFilter.value || p.status === statusFilter.value)
  && (!kindFilter.value || p.kind === kindFilter.value),
))

const columns = computed(() => [
  { key: 'code', label: t('code') },
  { key: 'name', label: t('name') },
  { key: 'kind', label: t('project_kind') },
  { key: 'status', label: t('status') },
  { key: 'contact_name', label: t('project_customer') },
  { key: 'responsible_name', label: t('project_responsible') },
  { key: 'target_date', label: t('project_target_date') },
  { key: 'expected_amount', label: t('project_expected_amount'), align: 'right' },
  { key: 'actions', label: '', sortable: false },
])

const filterStatusOptions = computed(() => [{ value: '', label: t('all') }, ...props.statuses])
const filterKindOptions = computed(() => [{ value: '', label: t('all') }, ...props.kinds])

const parentOptions = computed(() => [
  { value: '', label: t('none') },
  ...props.projects
    .filter(p => p.is_active && p.id !== editing.value?.id)
    .map(p => ({ value: p.id, label: `${'· '.repeat(p.depth)}${p.code} ${p.name}` })),
])
const contactOptions = computed(() => [{ value: '', label: t('none') }, ...props.contacts.map(c => ({ value: c.id, label: c.name }))])
const employeeOptions = computed(() => [{ value: '', label: t('none') }, ...props.employees.map(e => ({ value: e.id, label: e.name }))])

const showForm = ref(false)
const editing = ref(null)

const form = useForm({
  code: '',
  name: '',
  parent_id: '',
  kind: 'project',
  status: 'in_progress',
  contact_id: '',
  responsible_employee_id: '',
  target_date: '',
  expected_amount: '',
  notes: '',
  is_active: true,
})

function openCreate() {
  editing.value = null
  form.reset()
  form.clearErrors()
  showForm.value = true
}

function openEdit(project) {
  editing.value = project
  form.code = project.code
  form.name = project.name
  form.parent_id = project.parent_id ?? ''
  form.kind = project.kind
  form.status = project.status
  form.contact_id = project.contact_id ?? ''
  form.responsible_employee_id = project.responsible_employee_id ?? ''
  form.target_date = project.target_date ?? ''
  form.expected_amount = project.expected_amount ?? ''
  form.notes = project.notes ?? ''
  form.is_active = project.is_active
  form.clearErrors()
  showForm.value = true
}

function submitForm() {
  const options = { preserveScroll: true, onSuccess: () => { showForm.value = false } }
  if (editing.value) {
    form.put(`/projects/${editing.value.id}`, options)
  } else {
    form.post('/projects', options)
  }
}

const showDelete = ref(false)
const deleting = ref(null)
const deleteProcessing = ref(false)

function confirmDelete(project) {
  deleting.value = project
  showDelete.value = true
}

function performDelete() {
  if (!deleting.value) return
  deleteProcessing.value = true
  router.delete(`/projects/${deleting.value.id}`, {
    preserveScroll: true,
    onFinish: () => {
      deleteProcessing.value = false
      showDelete.value = false
      deleting.value = null
    },
  })
}
</script>

<template>
  <AppLayout :title="t('projects')">
    <Card>
      <CardHeader>
        <div class="flex flex-wrap items-center justify-between gap-2">
          <CardTitle>{{ t('projects') }}</CardTitle>
          <div class="flex flex-wrap items-center gap-2">
            <Link v-if="openReviews > 0" href="/projects/allocation-reviews">
              <Button size="sm" variant="outline">
                <ListChecks class="mr-1 h-4 w-4" /> {{ t('allocation_reviews') }}
                <Badge variant="warning" class="ml-2">{{ openReviews }}</Badge>
              </Button>
            </Link>
            <Button v-if="can.manage" size="sm" @click="openCreate">
              <Plus class="mr-1 h-4 w-4" /> {{ t('add') }}
            </Button>
          </div>
        </div>
      </CardHeader>
      <CardContent>
        <p class="mb-4 text-sm text-[hsl(var(--muted-foreground))]">{{ t('projects_desc') }}</p>
        <div class="mb-4 grid gap-3 sm:grid-cols-3">
          <FormSelect id="filter_status" v-model="statusFilter" :label="t('status')" :options="filterStatusOptions" />
          <FormSelect id="filter_kind" v-model="kindFilter" :label="t('project_kind')" :options="filterKindOptions" />
          <label class="flex items-end gap-2 pb-2 text-sm">
            <input v-model="showInactive" type="checkbox" class="h-4 w-4 rounded border-[hsl(var(--input))]" />
            {{ t('project_show_inactive') }}
          </label>
        </div>
        <DataTable :columns="columns" :rows="rows">
          <template #empty>
            <EmptyState
              :icon="FolderKanban"
              :title="t('empty_projects_title')"
              :description="t('empty_projects_desc')"
              :action-label="can.manage ? t('create_first') : undefined"
              @action="openCreate"
            />
          </template>
          <template #cell-name="{ row }">
            <span :style="{ paddingLeft: `${row.depth * 1.25}rem` }" :class="[row.kind === 'area' ? 'font-semibold' : '', row.is_active ? '' : 'text-[hsl(var(--muted-foreground))] line-through']">
              {{ row.name }}
            </span>
          </template>
          <template #cell-kind="{ value }">{{ kindLabel(value) }}</template>
          <template #cell-status="{ value }">
            <Badge :variant="statusVariant[value] ?? 'default'">{{ statusLabel(value) }}</Badge>
          </template>
          <template #cell-expected_amount="{ value }">
            <span class="tabular-nums">{{ value !== null && value !== '' ? formatCurrency(value) : '' }}</span>
          </template>
          <template #cell-actions="{ row }">
            <div class="flex items-center justify-end gap-1">
              <Link :href="`/projects/${row.id}/report`">
                <Button variant="ghost" size="icon" :aria-label="t('project_report')"><BarChart3 class="h-4 w-4" /></Button>
              </Link>
              <template v-if="can.manage">
                <Button variant="ghost" size="icon" :aria-label="t('edit')" @click="openEdit(row)"><Pencil class="h-4 w-4" /></Button>
                <Button variant="ghost" size="icon" :aria-label="t('delete')" @click="confirmDelete(row)">
                  <Trash2 class="h-4 w-4 text-[hsl(var(--destructive))]" />
                </Button>
              </template>
            </div>
          </template>
        </DataTable>
      </CardContent>
    </Card>

    <Modal :open="showForm" :title="editing ? t('edit') : t('add')" @close="showForm = false">
      <form class="space-y-4" @submit.prevent="submitForm">
        <div class="grid gap-4 sm:grid-cols-3">
          <FormInput id="code" v-model="form.code" :label="t('code')" :error="form.errors.code" required />
          <FormInput id="name" v-model="form.name" :label="t('name')" :error="form.errors.name" required class="sm:col-span-2" />
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
          <FormSelect id="kind" v-model="form.kind" :label="t('project_kind')" :options="kinds" :error="form.errors.kind" required />
          <FormSelect id="status" v-model="form.status" :label="t('status')" :options="statuses" :error="form.errors.status" required />
        </div>
        <SearchableSelect id="parent_id" v-model="form.parent_id" :label="t('project_parent')" :options="parentOptions" :error="form.errors.parent_id" />
        <div class="grid gap-4 sm:grid-cols-2">
          <SearchableSelect id="contact_id" v-model="form.contact_id" :label="t('project_customer')" :options="contactOptions" :error="form.errors.contact_id" />
          <SearchableSelect id="responsible_employee_id" v-model="form.responsible_employee_id" :label="t('project_responsible')" :options="employeeOptions" :error="form.errors.responsible_employee_id" />
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
          <FormInput id="target_date" v-model="form.target_date" type="date" :label="t('project_target_date')" :error="form.errors.target_date" />
          <FormInput id="expected_amount" v-model="form.expected_amount" type="number" step="0.01" min="0" :label="t('project_expected_amount')" :error="form.errors.expected_amount" />
        </div>
        <FormTextarea id="notes" v-model="form.notes" :label="t('notes')" :error="form.errors.notes" />
        <label v-if="editing" class="flex items-center gap-2 text-sm">
          <input v-model="form.is_active" type="checkbox" class="h-4 w-4 rounded border-[hsl(var(--input))]" />
          {{ t('active') }}
        </label>
        <div class="flex justify-end gap-2">
          <Button variant="outline" type="button" @click="showForm = false">{{ t('cancel') }}</Button>
          <Button type="submit" :disabled="form.processing" :loading="form.processing">{{ t('save') }}</Button>
        </div>
      </form>
    </Modal>

    <ConfirmDialog
      :open="showDelete"
      :title="t('confirm_delete')"
      :message="deleting ? `${deleting.code} ${deleting.name}` : ''"
      :processing="deleteProcessing"
      @confirm="performDelete"
      @cancel="showDelete = false"
    />
  </AppLayout>
</template>
