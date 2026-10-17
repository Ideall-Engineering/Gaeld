<script setup>
import { computed } from 'vue'
import Button from '@/Components/UI/Button.vue'
import FormInput from '@/Components/UI/FormInput.vue'
import FormSelect from '@/Components/UI/FormSelect.vue'
import LineAllocationEditor, { allocationsFromLine, serializeAllocations } from '@/Components/Projects/LineAllocationEditor.vue'
import { useFormatters } from '@/lib/useFormatters'
import { useTranslations } from '@/lib/useTranslations'
import { Plus, Trash2 } from 'lucide-vue-next'

// Edits the lines of a journal entry, including the VAT a line carries.
// Shared by the journal form and a correction's replacement draft, so that
// saving an entry can never drop the VAT fields it was loaded with.
const props = defineProps({
  lines: { type: Array, required: true },
  accounts: { type: Array, default: () => [] },
  vatRates: { type: Array, default: () => [] },
  vatTypes: { type: Array, default: () => [] },
  errors: { type: Object, default: () => ({}) },
  idPrefix: { type: String, default: 'line' },
  // Projects and people a line can be allocated to (spec 009); null where
  // projects are off or the user may not allocate.
  allocationOptions: { type: Object, default: null },
})

const { t } = useTranslations()
const { formatCurrency } = useFormatters()

const accountOptions = computed(() => [
  { value: '', label: t('select_placeholder') },
  ...props.accounts.map(a => ({ value: String(a.id), label: `${a.code} ${a.name}` })),
])

const vatTypeOptions = computed(() => [
  { value: '', label: t('no_vat') },
  ...props.vatTypes,
])

const vatRateOptions = computed(() => [
  { value: '', label: t('select_placeholder') },
  ...props.vatRates.map(r => ({ value: r.uuid, label: r.label })),
])

function lineError(index, field) {
  return props.errors[`lines.${index}.${field}`]
}

function addLine() {
  props.lines.push(emptyLine())
}

function removeLine(index) {
  if (props.lines.length <= 2) return
  props.lines.splice(index, 1)
}

function onVatTypeChange(line, value) {
  line.vat_type = value
  if (!value) {
    line.vat_rate_id = ''
    line.vat_amount = ''
    line.vat_figure = ''
  }
}

// Suggests the VAT on the line amount once a rate is picked; the amount
// stays editable because rounding on the source document may differ.
function onVatRateChange(line, value) {
  line.vat_rate_id = value
  const rate = props.vatRates.find(r => r.uuid === value)
  const base = Math.max(parseFloat(line.debit) || 0, parseFloat(line.credit) || 0)
  if (rate && base > 0 && !(parseFloat(line.vat_amount) > 0)) {
    line.vat_amount = (Math.round(base * parseFloat(rate.rate)) / 100).toFixed(2)
  }
}

const totalDebit = computed(() => props.lines.reduce((sum, l) => sum + (parseFloat(l.debit) || 0), 0))
const totalCredit = computed(() => props.lines.reduce((sum, l) => sum + (parseFloat(l.credit) || 0), 0))
const difference = computed(() => +(totalDebit.value - totalCredit.value).toFixed(2))
const isBalanced = computed(() => difference.value === 0 && totalDebit.value > 0)

defineExpose({ isBalanced })
</script>

<script>
export function emptyLine() {
  return { account_id: '', debit: '0.00', credit: '0.00', description: '', vat_type: '', vat_rate_id: '', vat_amount: '', vat_figure: '' }
}

export function lineFromEntry(line) {
  const allocations = allocationsFromLine(line)
  return {
    ...(allocations !== undefined ? { allocations } : {}),
    account_id: String(line.account_id),
    debit: String(line.debit || '0.00'),
    credit: String(line.credit || '0.00'),
    description: line.description || '',
    vat_type: line.vat_type || '',
    vat_rate_id: line.vat_rate_id || '',
    vat_amount: line.vat_amount != null ? String(line.vat_amount) : '',
    vat_figure: line.vat_figure || '',
  }
}

// Empty VAT fields go out as null, so a line without VAT stays without VAT.
export function serializeLines(lines) {
  return lines.map(line => ({
    ...line,
    vat_type: line.vat_type || null,
    vat_rate_id: line.vat_type ? (line.vat_rate_id || null) : null,
    vat_amount: line.vat_type && line.vat_amount !== '' ? line.vat_amount : null,
    vat_figure: line.vat_type ? (line.vat_figure || null) : null,
    allocations: serializeAllocations(line.allocations),
  }))
}
</script>

<template>
  <div>
    <div class="mb-2 flex items-center justify-between">
      <label class="text-sm font-medium">{{ t('entry_lines') }}</label>
      <Button type="button" size="sm" variant="outline" @click="addLine">
        <Plus class="mr-1 h-3 w-3" /> {{ t('add_line') }}
      </Button>
    </div>

    <div class="space-y-3">
      <div
        v-for="(line, index) in lines"
        :key="index"
        class="rounded-md border border-[hsl(var(--border))] p-2"
      >
        <div class="grid grid-cols-12 items-start gap-2">
          <div class="col-span-12 sm:col-span-5">
            <FormSelect
              :id="`${idPrefix}_account_${index}`"
              v-model="line.account_id"
              :label="index === 0 ? t('account') : ''"
              :options="accountOptions"
              :error="lineError(index, 'account_id')"
              required
            />
          </div>
          <div class="col-span-5 sm:col-span-2">
            <FormInput
              :id="`${idPrefix}_debit_${index}`"
              v-model="line.debit"
              type="number"
              step="0.01"
              min="0"
              :label="index === 0 ? t('debit') : ''"
              :error="lineError(index, 'debit')"
            />
          </div>
          <div class="col-span-5 sm:col-span-2">
            <FormInput
              :id="`${idPrefix}_credit_${index}`"
              v-model="line.credit"
              type="number"
              step="0.01"
              min="0"
              :label="index === 0 ? t('credit') : ''"
              :error="lineError(index, 'credit')"
            />
          </div>
          <div class="col-span-10 sm:col-span-2">
            <FormInput
              :id="`${idPrefix}_desc_${index}`"
              v-model="line.description"
              :label="index === 0 ? t('description') : ''"
              :error="lineError(index, 'description')"
            />
          </div>
          <div class="col-span-2 flex items-end pb-2 sm:col-span-1">
            <Button
              type="button"
              variant="ghost"
              size="icon"
              :disabled="lines.length <= 2"
              :aria-label="t('delete')"
              @click="removeLine(index)"
            >
              <Trash2 class="h-4 w-4" />
            </Button>
          </div>
        </div>

        <div class="mt-2 grid grid-cols-12 items-start gap-2">
          <div class="col-span-12 sm:col-span-4">
            <FormSelect
              :id="`${idPrefix}_vat_type_${index}`"
              :model-value="line.vat_type"
              :label="t('journal_line_vat')"
              :options="vatTypeOptions"
              :error="lineError(index, 'vat_type')"
              @update:model-value="value => onVatTypeChange(line, value)"
            />
          </div>
          <template v-if="line.vat_type">
            <div class="col-span-12 sm:col-span-4">
              <FormSelect
                :id="`${idPrefix}_vat_rate_${index}`"
                :model-value="line.vat_rate_id"
                :label="t('vat_rate')"
                :options="vatRateOptions"
                :error="lineError(index, 'vat_rate_id')"
                required
                @update:model-value="value => onVatRateChange(line, value)"
              />
            </div>
            <div class="col-span-6 sm:col-span-2">
              <FormInput
                :id="`${idPrefix}_vat_amount_${index}`"
                v-model="line.vat_amount"
                type="number"
                step="0.01"
                min="0"
                :label="t('vat_amount')"
                :error="lineError(index, 'vat_amount')"
              />
            </div>
            <div class="col-span-6 sm:col-span-2">
              <FormInput
                :id="`${idPrefix}_vat_figure_${index}`"
                v-model="line.vat_figure"
                :label="t('journal_line_vat_figure')"
                :error="lineError(index, 'vat_figure')"
              />
            </div>
          </template>
        </div>

        <LineAllocationEditor
          v-if="allocationOptions"
          v-model="line.allocations"
          class="mt-2"
          :options="allocationOptions"
          :errors="errors"
          :error-prefix="`lines.${index}.allocations`"
          :id-prefix="`${idPrefix}_alloc_${index}`"
        />
      </div>
    </div>

    <div class="mt-4 rounded-md border border-[hsl(var(--border))] bg-[hsl(var(--muted)/0.4)] p-4">
      <div class="grid grid-cols-3 gap-2 text-sm">
        <div>
          <div class="text-[hsl(var(--muted-foreground))]">{{ t('total_debit') }}</div>
          <div class="text-base font-semibold">{{ formatCurrency(totalDebit) }}</div>
        </div>
        <div>
          <div class="text-[hsl(var(--muted-foreground))]">{{ t('total_credit') }}</div>
          <div class="text-base font-semibold">{{ formatCurrency(totalCredit) }}</div>
        </div>
        <div>
          <div class="text-[hsl(var(--muted-foreground))]">{{ t('difference') }}</div>
          <div
            class="text-base font-semibold"
            :class="isBalanced ? 'text-[hsl(var(--success))]' : 'text-[hsl(var(--destructive))]'"
          >
            {{ formatCurrency(difference) }}
            <span class="ml-1 text-xs font-normal">
              {{ isBalanced ? t('entry_balanced') : t('entry_unbalanced') }}
            </span>
          </div>
        </div>
      </div>
    </div>

    <div v-if="errors.lines" class="mt-2 text-xs text-[hsl(var(--destructive))]">{{ errors.lines }}</div>
  </div>
</template>
