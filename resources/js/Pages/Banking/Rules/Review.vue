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
import FormSelect from '@/Components/UI/FormSelect.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'
import { useTranslations } from '@/lib/useTranslations'
import { computed, ref } from 'vue'
import { useForm, Link } from '@inertiajs/vue3'
import { Check, Pencil, X, ListChecks, Scale } from 'lucide-vue-next'

const props = defineProps({
  pending: Array,
  decided: Array,
  options: Object,
  canDecide: { type: Boolean, default: false },
})

const { t } = useTranslations()

const treatmentVariant = {
  standard: 'default',
  reverse_charge: 'warning',
  import_tax: 'warning',
  none: 'secondary',
}

const outcomeVariant = {
  confirmed: 'success',
  corrected: 'warning',
  rejected: 'destructive',
  pending: 'default',
}

const accountOptions = computed(() => [
  ...props.options.accounts.map((a) => ({ value: a.code, label: `${a.code} — ${a.name}` })),
])

const vatRateOptions = computed(() => [
  { value: '', label: t('select_placeholder') },
  ...props.options.vatRates.map((r) => ({ value: r.id, label: `${r.name} (${r.rate} %)` })),
])

const pendingColumns = computed(() => [
  { key: 'transaction', label: t('bank_rule_counterparty'), sortable: false },
  { key: 'suggestion', label: t('bank_rule_suggestion'), sortable: false },
  { key: 'reason', label: t('bank_rule_reason'), sortable: false },
  { key: 'actions', label: '', sortable: false },
])

const decidedColumns = computed(() => [
  { key: 'transaction', label: t('bank_rule_counterparty'), sortable: false },
  { key: 'outcome', label: t('status') },
  { key: 'result', label: t('bank_rule_account'), sortable: false },
  { key: 'decided_by', label: t('bank_rule_decided_by'), sortable: false },
])

function counterparty(row) {
  const tx = row.transaction || {}
  return tx.creditor_name || tx.debtor_name || tx.description || '—'
}

function amount(row) {
  const tx = row.transaction || {}
  return `${tx.type === 'debit' ? '−' : '+'} ${tx.amount}`
}

// Correction dialog
const showCorrect = ref(false)
const active = ref(null)
const form = useForm({ account_code: '', tax_treatment: '', vat_rate_id: '', rejected: false })

function openCorrect(row) {
  active.value = row
  form.clearErrors()
  form.account_code = row.suggested_account_code
  form.tax_treatment = row.suggested_tax_treatment
  form.vat_rate_id = row.suggested_vat_rate_id ?? ''
  form.rejected = false
  showCorrect.value = true
}

function submitCorrection() {
  form.post(`/banking/rule-review/${active.value.id}`, {
    preserveScroll: true,
    onSuccess: () => { showCorrect.value = false },
  })
}

// Confirming sends no payload at all: the absent fields mean "exactly as
// proposed", which is what makes it count as a confirmation rather than a
// correction that happens to match.
function confirm(row) {
  useForm({}).post(`/banking/rule-review/${row.id}`, { preserveScroll: true })
}

function reject(row) {
  useForm({ rejected: true }).post(`/banking/rule-review/${row.id}`, { preserveScroll: true })
}
</script>

<template>
  <AppLayout :title="t('bank_rule_review')">
    <div class="space-y-6">
      <Card>
        <CardHeader>
          <div class="flex items-center justify-between">
            <CardTitle>{{ t('bank_rule_review') }}</CardTitle>
            <Link href="/banking/rules">
              <Button variant="outline" size="sm">
                <Scale class="mr-1 h-4 w-4" /> {{ t('bank_rules') }}
              </Button>
            </Link>
          </div>
        </CardHeader>
        <CardContent>
          <p class="mb-4 text-sm text-[hsl(var(--muted-foreground))]">{{ t('bank_rule_review_desc') }}</p>

          <DataTable :columns="pendingColumns" :rows="pending">
            <template #empty>
              <EmptyState
                :icon="ListChecks"
                :title="t('empty_bank_rule_review_title')"
                :description="t('empty_bank_rule_review_desc')"
              />
            </template>

            <template #cell-transaction="{ row }">
              <div>
                <p class="font-medium">{{ counterparty(row) }}</p>
                <p class="text-xs text-[hsl(var(--muted-foreground))]">
                  {{ row.transaction?.date }} · {{ amount(row) }}
                </p>
              </div>
            </template>

            <template #cell-suggestion="{ row }">
              <div class="flex flex-wrap items-center gap-1">
                <Badge variant="outline">{{ row.suggested_account_code }}</Badge>
                <Badge :variant="treatmentVariant[row.suggested_tax_treatment] || 'default'">
                  {{ t(`expense_tax_treatment_${row.suggested_tax_treatment}`) }}
                </Badge>
                <span v-if="row.suggested_vat_rate" class="text-xs text-[hsl(var(--muted-foreground))]">
                  {{ row.suggested_vat_rate.rate }} %
                </span>
              </div>
            </template>

            <template #cell-reason="{ row }">
              <span class="text-xs text-[hsl(var(--muted-foreground))]">{{ row.reason || '—' }}</span>
            </template>

            <template #cell-actions="{ row }">
              <div class="flex items-center justify-end gap-1">
                <Button v-if="canDecide" variant="ghost" size="icon" :title="t('bank_rule_confirm')" @click="confirm(row)">
                  <Check class="h-4 w-4" />
                </Button>
                <Button v-if="canDecide" variant="ghost" size="icon" :title="t('bank_rule_correct')" @click="openCorrect(row)">
                  <Pencil class="h-4 w-4" />
                </Button>
                <Button v-if="canDecide" variant="ghost" size="icon" :title="t('bank_rule_reject')" @click="reject(row)">
                  <X class="h-4 w-4" />
                </Button>
              </div>
            </template>
          </DataTable>
        </CardContent>
      </Card>

      <Card v-if="decided.length">
        <CardHeader>
          <CardTitle>{{ t('bank_rule_decision_log') }}</CardTitle>
        </CardHeader>
        <CardContent>
          <DataTable :columns="decidedColumns" :rows="decided">
            <template #cell-transaction="{ row }">
              <div>
                <p class="font-medium">{{ counterparty(row) }}</p>
                <p class="text-xs text-[hsl(var(--muted-foreground))]">{{ row.transaction?.date }}</p>
              </div>
            </template>

            <template #cell-outcome="{ value }">
              <Badge :variant="outcomeVariant[value] || 'default'">
                {{ t(`bank_rule_outcome_${value}`) }}
              </Badge>
            </template>

            <template #cell-result="{ row }">
              <span class="text-sm">
                <template v-if="row.outcome === 'corrected'">
                  <span class="line-through opacity-60">{{ row.suggested_account_code }}</span>
                  → {{ row.final_account_code }}
                </template>
                <template v-else>{{ row.final_account_code || '—' }}</template>
              </span>
            </template>

            <template #cell-decided_by="{ row }">
              <span class="text-sm">{{ row.decided_by?.name || '—' }}</span>
            </template>
          </DataTable>
        </CardContent>
      </Card>
    </div>

    <Modal :open="showCorrect" :title="t('bank_rule_correct')" @close="showCorrect = false">
      <form class="space-y-4" @submit.prevent="submitCorrection">
        <FormSelect
          id="account_code" v-model="form.account_code" :label="t('bank_rule_account')"
          :options="accountOptions" :error="form.errors.account_code" required
        />
        <FormSelect
          id="tax_treatment" v-model="form.tax_treatment" :label="t('expense_tax_treatment')"
          :options="options.taxTreatments" :error="form.errors.tax_treatment" required
        />
        <FormSelect
          id="vat_rate_id" v-model="form.vat_rate_id" :label="t('bank_rule_vat_rate')"
          :options="vatRateOptions" :error="form.errors.vat_rate_id"
        />
        <div class="flex justify-end gap-2">
          <Button variant="outline" type="button" @click="showCorrect = false">{{ t('cancel') }}</Button>
          <Button type="submit" :disabled="form.processing" :loading="form.processing">{{ t('save') }}</Button>
        </div>
      </form>
    </Modal>
  </AppLayout>
</template>
