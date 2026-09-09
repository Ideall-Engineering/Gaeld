<script setup>
import AppLayout from '@/Components/AppLayout.vue'
import Card from '@/Components/UI/Card.vue'
import CardHeader from '@/Components/UI/CardHeader.vue'
import CardTitle from '@/Components/UI/CardTitle.vue'
import CardContent from '@/Components/UI/CardContent.vue'
import Badge from '@/Components/UI/Badge.vue'
import Button from '@/Components/UI/Button.vue'
import DataTable from '@/Components/UI/DataTable.vue'
import Modal from '@/Components/UI/Modal.vue'
import FormInput from '@/Components/UI/FormInput.vue'
import FormSelect from '@/Components/UI/FormSelect.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'
import { useTranslations } from '@/lib/useTranslations'
import { computed, ref } from 'vue'
import { useForm, Link } from '@inertiajs/vue3'
import { Plus, Pencil, Trash2, ListChecks, Scale } from 'lucide-vue-next'

const props = defineProps({
  rules: Array,
  options: Object,
  autoApplyEnabled: { type: Boolean, default: false },
  canManage: { type: Boolean, default: false },
})

const { t } = useTranslations()

const treatmentVariant = {
  standard: 'default',
  reverse_charge: 'warning',
  import_tax: 'warning',
  none: 'secondary',
}

const accountOptions = computed(() => [
  { value: '', label: t('select_placeholder') },
  ...props.options.accounts.map((a) => ({ value: a.code, label: `${a.code} — ${a.name}` })),
])

const vatRateOptions = computed(() => [
  { value: '', label: t('select_placeholder') },
  ...props.options.vatRates.map((r) => ({ value: r.id, label: `${r.name} (${r.rate} %)` })),
])

const columns = computed(() => [
  { key: 'priority', label: t('bank_rule_priority') },
  { key: 'name', label: t('bank_rule_name') },
  { key: 'match_text', label: t('bank_rule_match_text') },
  { key: 'account_code', label: t('bank_rule_account') },
  { key: 'tax_treatment', label: t('expense_tax_treatment') },
  { key: 'action', label: t('bank_rule_action') },
  { key: 'track_record', label: t('bank_rule_track_record'), sortable: false },
  { key: 'actions', label: '', sortable: false },
])

const showForm = ref(false)
const editing = ref(null)

const form = useForm({
  name: '',
  match_text: '',
  match_field: 'counterparty',
  direction: 'debit',
  account_code: '',
  tax_treatment: 'standard',
  vat_rate_id: '',
  priority: 100,
  action: 'suggest',
  is_active: true,
  valid_from: '',
  valid_until: '',
  reason: '',
})

// Only Swiss input VAT and acquisition tax are computed from a rate; import VAT
// comes off the customs assessment and exempt purchases have none at all.
const needsVatRate = computed(() =>
  ['standard', 'reverse_charge'].includes(form.tax_treatment)
)

function openCreate() {
  editing.value = null
  form.reset()
  form.clearErrors()
  showForm.value = true
}

function openEdit(rule) {
  editing.value = rule
  form.clearErrors()
  form.name = rule.name
  form.match_text = rule.match_text
  form.match_field = rule.match_field
  form.direction = rule.direction
  form.account_code = rule.account_code
  form.tax_treatment = rule.tax_treatment
  form.vat_rate_id = rule.vat_rate_id ?? ''
  form.priority = rule.priority
  form.action = rule.action
  form.is_active = rule.is_active
  form.valid_from = rule.valid_from ?? ''
  form.valid_until = rule.valid_until ?? ''
  form.reason = rule.reason ?? ''
  showForm.value = true
}

function submitForm() {
  const options = { preserveScroll: true, onSuccess: () => { showForm.value = false } }

  if (editing.value) {
    form.put(`/banking/rules/${editing.value.id}`, options)
  } else {
    form.post('/banking/rules', options)
  }
}

function destroyRule(rule) {
  if (!window.confirm(t('confirm_delete'))) return
  useForm({}).delete(`/banking/rules/${rule.id}`, { preserveScroll: true })
}

function accuracyLabel(record) {
  if (!record || record.accuracy === null) return t('bank_rule_no_decisions_yet')
  return `${Math.round(record.accuracy * 100)} % (${record.confirmed}/${record.total})`
}
</script>

<template>
  <AppLayout :title="t('bank_rules')">
    <Card>
      <CardHeader>
        <div class="flex items-center justify-between">
          <CardTitle>{{ t('bank_rules') }}</CardTitle>
          <div class="flex items-center gap-2">
            <Link href="/banking/rule-review">
              <Button variant="outline" size="sm">
                <ListChecks class="mr-1 h-4 w-4" /> {{ t('bank_rule_review') }}
              </Button>
            </Link>
            <Button v-if="canManage" size="sm" @click="openCreate">
              <Plus class="mr-1 h-4 w-4" /> {{ t('add') }}
            </Button>
          </div>
        </div>
      </CardHeader>
      <CardContent>
        <p class="mb-4 text-sm text-[hsl(var(--muted-foreground))]">{{ t('bank_rules_desc') }}</p>

        <p
          v-if="!autoApplyEnabled"
          class="mb-4 rounded-md border border-[hsl(var(--border))] bg-[hsl(var(--muted))] px-3 py-2 text-sm"
        >
          {{ t('bank_rule_auto_apply_disabled_hint') }}
        </p>

        <DataTable :columns="columns" :rows="rules">
          <template #empty>
            <EmptyState
              :icon="Scale"
              :title="t('empty_bank_rules_title')"
              :description="t('empty_bank_rules_desc')"
              :action-label="canManage ? t('create_first') : null"
              @action="openCreate"
            />
          </template>

          <template #cell-name="{ row }">
            <div>
              <span :class="{ 'line-through opacity-60': !row.is_active }">{{ row.name }}</span>
              <p v-if="row.reason" class="text-xs text-[hsl(var(--muted-foreground))]">{{ row.reason }}</p>
            </div>
          </template>

          <template #cell-tax_treatment="{ value }">
            <Badge :variant="treatmentVariant[value] || 'default'">
              {{ t(`expense_tax_treatment_${value}`) }}
            </Badge>
          </template>

          <template #cell-action="{ value }">
            <Badge :variant="value === 'auto_apply' ? 'warning' : 'secondary'">
              {{ t(`bank_rule_action_${value}`) }}
            </Badge>
          </template>

          <template #cell-track_record="{ row }">
            <span class="text-sm">{{ accuracyLabel(row.track_record) }}</span>
          </template>

          <template #cell-actions="{ row }">
            <div class="flex items-center justify-end gap-1">
              <Button v-if="canManage" variant="ghost" size="icon" @click="openEdit(row)">
                <Pencil class="h-4 w-4" />
              </Button>
              <Button v-if="canManage" variant="ghost" size="icon" @click="destroyRule(row)">
                <Trash2 class="h-4 w-4" />
              </Button>
            </div>
          </template>
        </DataTable>
      </CardContent>
    </Card>

    <Modal :open="showForm" :title="t('bank_rules')" @close="showForm = false">
      <form class="space-y-4" @submit.prevent="submitForm">
        <FormInput
          id="name" v-model="form.name" :label="t('bank_rule_name')"
          :error="form.errors.name" required
        />

        <div class="grid gap-4 sm:grid-cols-2">
          <FormInput
            id="match_text" v-model="form.match_text" :label="t('bank_rule_match_text')"
            :error="form.errors.match_text" required
          />
          <FormSelect
            id="match_field" v-model="form.match_field" :label="t('bank_rule_match_field')"
            :options="options.matchFields" :error="form.errors.match_field" required
          />
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
          <FormSelect
            id="direction" v-model="form.direction" :label="t('bank_rule_direction')"
            :options="options.directions" :error="form.errors.direction" required
          />
          <FormSelect
            id="account_code" v-model="form.account_code" :label="t('bank_rule_account')"
            :options="accountOptions" :error="form.errors.account_code" required
          />
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
          <FormSelect
            id="tax_treatment" v-model="form.tax_treatment" :label="t('expense_tax_treatment')"
            :options="options.taxTreatments" :error="form.errors.tax_treatment" required
          />
          <FormSelect
            v-if="needsVatRate"
            id="vat_rate_id" v-model="form.vat_rate_id" :label="t('bank_rule_vat_rate')"
            :options="vatRateOptions" :error="form.errors.vat_rate_id" required
          />
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
          <FormInput
            id="priority" v-model="form.priority" type="number"
            :label="t('bank_rule_priority')" :error="form.errors.priority" required
          />
          <FormSelect
            id="action" v-model="form.action" :label="t('bank_rule_action')"
            :options="options.actions" :error="form.errors.action" required
          />
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
          <FormInput
            id="valid_from" v-model="form.valid_from" type="date"
            :label="t('bank_rule_valid_from')" :error="form.errors.valid_from"
          />
          <FormInput
            id="valid_until" v-model="form.valid_until" type="date"
            :label="t('bank_rule_valid_until')" :error="form.errors.valid_until"
          />
        </div>

        <FormInput
          id="reason" v-model="form.reason" :label="t('bank_rule_reason')"
          :error="form.errors.reason"
        />

        <label class="flex items-center gap-2 text-sm">
          <input v-model="form.is_active" type="checkbox" class="rounded border-[hsl(var(--border))]" />
          {{ t('active') }}
        </label>

        <div class="flex justify-end gap-2">
          <Button variant="outline" type="button" @click="showForm = false">{{ t('cancel') }}</Button>
          <Button type="submit" :disabled="form.processing" :loading="form.processing">{{ t('save') }}</Button>
        </div>
      </form>
    </Modal>
  </AppLayout>
</template>
