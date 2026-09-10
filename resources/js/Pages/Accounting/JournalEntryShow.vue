<script setup>
import { computed, ref } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Components/AppLayout.vue'
import Card from '@/Components/UI/Card.vue'
import CardHeader from '@/Components/UI/CardHeader.vue'
import CardTitle from '@/Components/UI/CardTitle.vue'
import CardContent from '@/Components/UI/CardContent.vue'
import Button from '@/Components/UI/Button.vue'
import Badge from '@/Components/UI/Badge.vue'
import Breadcrumb from '@/Components/UI/Breadcrumb.vue'
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue'
import FormInput from '@/Components/UI/FormInput.vue'
import FormSelect from '@/Components/UI/FormSelect.vue'
import JournalCorrectionDialog from '@/Components/Accounting/JournalCorrectionDialog.vue'
import { Plus, Replace, RotateCcw, Trash2 } from 'lucide-vue-next'
import { useTranslations } from '@/lib/useTranslations'
import { useFormatters } from '@/lib/useFormatters'

const props = defineProps({
  entry: { type: Object, required: true },
  correction: { type: Object, default: null },
  correctionRole: { type: String, default: null },
  accounts: { type: Array, default: () => [] },
  can: { type: Object, default: () => ({ correct: false, reverse: false }) },
})

const { t } = useTranslations()
const { formatCurrency, formatDate } = useFormatters()

const totalDebit = computed(() => props.entry.lines?.reduce((total, line) => total + Number(line.debit || 0), 0) || 0)
const totalCredit = computed(() => props.entry.lines?.reduce((total, line) => total + Number(line.credit || 0), 0) || 0)

const accountOptions = computed(() => [
  { value: '', label: t('select_placeholder') },
  ...props.accounts.map(a => ({ value: String(a.id), label: `${a.code} — ${a.name}` })),
])

const isCorrectedOriginal = computed(() => props.correctionRole === 'original')
const isLockedReversal = computed(() => props.correctionRole === 'reversal')
const isOpenReplacementDraft = computed(
  () => props.correctionRole === 'replacement' && props.correction?.status === 'draft',
)
const canStartCorrection = computed(
  () => props.can?.correct && !props.correctionRole && !props.entry.reversal_of_entry_id && props.entry.is_posted,
)
const canReverse = computed(
  () => props.can?.reverse && !props.correctionRole && !props.entry.reversal_of_entry_id && props.entry.is_posted,
)

// Guided correction: start dialog
const correctingEntry = ref(null)

// Guided correction: edit the replacement draft's lines
const replacementForm = useForm({
  date: props.entry.date ? String(props.entry.date).split('T')[0].split(' ')[0] : '',
  reference: props.entry.reference || '',
  description: props.entry.description || '',
  lines: (props.entry.lines || []).map(line => ({
    account_id: String(line.account_id),
    debit: String(line.debit || '0.00'),
    credit: String(line.credit || '0.00'),
    description: line.description || '',
  })),
})

function addReplacementLine() {
  replacementForm.lines.push({ account_id: '', debit: '0.00', credit: '0.00', description: '' })
}

function removeReplacementLine(index) {
  if (replacementForm.lines.length <= 2) return
  replacementForm.lines.splice(index, 1)
}

function replacementLineError(index, field) {
  return replacementForm.errors[`lines.${index}.${field}`]
}

const replacementTotalDebit = computed(() =>
  replacementForm.lines.reduce((sum, l) => sum + (parseFloat(l.debit) || 0), 0)
)
const replacementTotalCredit = computed(() =>
  replacementForm.lines.reduce((sum, l) => sum + (parseFloat(l.credit) || 0), 0)
)
const replacementBalanced = computed(
  () => +(replacementTotalDebit.value - replacementTotalCredit.value).toFixed(2) === 0 && replacementTotalDebit.value > 0,
)

function saveReplacement() {
  replacementForm.put(`/accounting/journal-corrections/${props.correction.id}/replacement`, {
    preserveScroll: true,
  })
}

// Post / cancel the correction as a whole
const confirmingPostCorrection = ref(false)
const confirmingCancelCorrection = ref(false)

function postCorrection() {
  router.post(`/accounting/journal-corrections/${props.correction.id}/post`, {}, {
    preserveScroll: true,
    onFinish: () => { confirmingPostCorrection.value = false },
  })
}

function cancelCorrection() {
  router.delete(`/accounting/journal-corrections/${props.correction.id}`, {
    preserveScroll: true,
    onFinish: () => { confirmingCancelCorrection.value = false },
  })
}

function doReverse() {
  router.post(`/accounting/journal-entries/${props.entry.id}/reverse`, {}, { preserveScroll: true })
}
const confirmingReverse = ref(false)
</script>

<template>
  <AppLayout :title="t('journal_entry')" help-page="accounting-basics">
    <Breadcrumb
      :items="[{ label: t('journal_entries'), href: '/accounting/journal-entries' }, { label: entry.reference || t('journal_entry') }]"
      class="mb-4"
    />

    <div class="max-w-4xl space-y-6">
      <div v-if="correction" class="rounded-md border border-[hsl(var(--border))] bg-[hsl(var(--muted)/0.4)] p-4 text-sm">
        <div class="mb-2 flex flex-wrap items-center gap-2 font-medium">
          <template v-if="isCorrectedOriginal">
            <Badge variant="warning">{{ t('journal_correction_corrected_badge') }}</Badge>
            <span>{{ t('journal_correction_draft_notice') }}</span>
          </template>
          <template v-else-if="isLockedReversal">
            <Badge variant="secondary">{{ t('journal_correction_reversal_badge') }}</Badge>
            <span>{{ t('journal_correction_reversal_locked_notice') }}</span>
          </template>
          <template v-else>
            <Badge variant="info">{{ t('journal_correction_replacement_badge') }}</Badge>
            <span v-if="isOpenReplacementDraft">{{ t('journal_correction_draft_notice') }}</span>
          </template>
        </div>
        <div class="flex flex-wrap gap-3">
          <Link
            v-if="correction.original_journal_entry_id !== entry.id"
            :href="`/accounting/journal-entries/${correction.original_journal_entry_id}`"
            class="text-[hsl(var(--primary))] hover:underline"
          >
            {{ t('view_original') }}
          </Link>
          <Link
            v-if="correction.reversal_journal_entry_id !== entry.id"
            :href="`/accounting/journal-entries/${correction.reversal_journal_entry_id}`"
            class="text-[hsl(var(--primary))] hover:underline"
          >
            {{ t('view_reversal') }}
          </Link>
          <Link
            v-if="correction.replacement_journal_entry_id !== entry.id"
            :href="`/accounting/journal-entries/${correction.replacement_journal_entry_id}`"
            class="text-[hsl(var(--primary))] hover:underline"
          >
            {{ t('view_replacement') }}
          </Link>
        </div>
      </div>

      <Card>
        <CardHeader>
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
              <CardTitle>{{ entry.description || entry.reference || t('journal_entry') }}</CardTitle>
              <p class="mt-1 text-sm text-[hsl(var(--muted-foreground))]">{{ entry.reference || '—' }}</p>
            </div>
            <div class="flex items-center gap-2">
              <Badge :variant="entry.is_posted ? 'success' : 'warning'">
                {{ entry.is_posted ? t('posted') : t('draft') }}
              </Badge>
              <Button v-if="canStartCorrection" size="sm" variant="outline" @click="correctingEntry = entry">
                <Replace class="mr-1 h-4 w-4" />
                {{ t('correct') }}
              </Button>
              <Button v-if="canReverse" size="sm" variant="outline" @click="confirmingReverse = true">
                <RotateCcw class="mr-1 h-4 w-4" />
                {{ t('reverse') }}
              </Button>
            </div>
          </div>
        </CardHeader>
        <CardContent>
          <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-3">
            <div>
              <dt class="text-[hsl(var(--muted-foreground))]">{{ t('date') }}</dt>
              <dd class="font-medium">{{ formatDate(entry.date) }}</dd>
            </div>
            <div>
              <dt class="text-[hsl(var(--muted-foreground))]">{{ t('reference') }}</dt>
              <dd class="font-medium">{{ entry.reference || '—' }}</dd>
            </div>
            <div>
              <dt class="text-[hsl(var(--muted-foreground))]">{{ t('description') }}</dt>
              <dd class="font-medium">{{ entry.description || '—' }}</dd>
            </div>
          </dl>
        </CardContent>
      </Card>

      <!-- Editable replacement draft lines -->
      <Card v-if="isOpenReplacementDraft">
        <CardHeader><CardTitle>{{ t('journal_correction_replacement_label') }}</CardTitle></CardHeader>
        <CardContent>
          <form @submit.prevent="saveReplacement">
            <div class="space-y-4">
              <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <FormInput
                  id="replacement_date"
                  v-model="replacementForm.date"
                  type="date"
                  :label="t('date')"
                  :error="replacementForm.errors.date"
                  required
                />
                <FormInput
                  id="replacement_reference"
                  v-model="replacementForm.reference"
                  :label="t('reference')"
                  :error="replacementForm.errors.reference"
                />
                <FormInput
                  id="replacement_description"
                  v-model="replacementForm.description"
                  :label="t('description')"
                  :error="replacementForm.errors.description"
                />
              </div>

              <div>
                <div class="mb-2 flex items-center justify-between">
                  <label class="text-sm font-medium">{{ t('entry_lines') }}</label>
                  <Button type="button" size="sm" variant="outline" @click="addReplacementLine">
                    <Plus class="mr-1 h-3 w-3" /> {{ t('add_line') }}
                  </Button>
                </div>
                <div class="space-y-2">
                  <div
                    v-for="(line, index) in replacementForm.lines"
                    :key="index"
                    class="grid grid-cols-12 items-start gap-2"
                  >
                    <div class="col-span-12 sm:col-span-5">
                      <FormSelect
                        :id="`replacement_account_${index}`"
                        v-model="line.account_id"
                        :label="index === 0 ? t('account') : ''"
                        :options="accountOptions"
                        :error="replacementLineError(index, 'account_id')"
                        required
                      />
                    </div>
                    <div class="col-span-5 sm:col-span-2">
                      <FormInput
                        :id="`replacement_debit_${index}`"
                        v-model="line.debit"
                        type="number"
                        step="0.01"
                        min="0"
                        :label="index === 0 ? t('debit') : ''"
                        :error="replacementLineError(index, 'debit')"
                      />
                    </div>
                    <div class="col-span-5 sm:col-span-2">
                      <FormInput
                        :id="`replacement_credit_${index}`"
                        v-model="line.credit"
                        type="number"
                        step="0.01"
                        min="0"
                        :label="index === 0 ? t('credit') : ''"
                        :error="replacementLineError(index, 'credit')"
                      />
                    </div>
                    <div class="col-span-10 sm:col-span-2">
                      <FormInput
                        :id="`replacement_line_desc_${index}`"
                        v-model="line.description"
                        :label="index === 0 ? t('description') : ''"
                        :error="replacementLineError(index, 'description')"
                      />
                    </div>
                    <div class="col-span-2 sm:col-span-1 flex items-end pb-2">
                      <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        :disabled="replacementForm.lines.length <= 2"
                        @click="removeReplacementLine(index)"
                      >
                        <Trash2 class="h-4 w-4" />
                      </Button>
                    </div>
                  </div>
                </div>
              </div>

              <div class="rounded-md border border-[hsl(var(--border))] bg-[hsl(var(--muted)/0.4)] p-4">
                <div class="grid grid-cols-3 gap-2 text-sm">
                  <div>
                    <div class="text-[hsl(var(--muted-foreground))]">{{ t('total_debit') }}</div>
                    <div class="text-base font-semibold">{{ formatCurrency(replacementTotalDebit) }}</div>
                  </div>
                  <div>
                    <div class="text-[hsl(var(--muted-foreground))]">{{ t('total_credit') }}</div>
                    <div class="text-base font-semibold">{{ formatCurrency(replacementTotalCredit) }}</div>
                  </div>
                  <div>
                    <div class="text-[hsl(var(--muted-foreground))]">{{ t('difference') }}</div>
                    <div
                      class="text-base font-semibold"
                      :class="replacementBalanced ? 'text-[hsl(var(--success))]' : 'text-[hsl(var(--destructive))]'"
                    >
                      {{ formatCurrency(+(replacementTotalDebit - replacementTotalCredit).toFixed(2)) }}
                    </div>
                  </div>
                </div>
              </div>

              <div v-if="replacementForm.errors.lines" class="text-xs text-[hsl(var(--destructive))]">
                {{ replacementForm.errors.lines }}
              </div>

              <div class="flex flex-wrap justify-end gap-2 pt-2">
                <Button type="button" variant="destructive" :disabled="replacementForm.processing" @click="confirmingCancelCorrection = true">
                  {{ t('cancel_correction') }}
                </Button>
                <Button type="submit" variant="outline" :disabled="replacementForm.processing || !replacementBalanced" :loading="replacementForm.processing">
                  {{ t('save_changes') }}
                </Button>
                <Button type="button" :disabled="replacementForm.processing" @click="confirmingPostCorrection = true">
                  {{ t('post_correction') }}
                </Button>
              </div>
            </div>
          </form>
        </CardContent>
      </Card>

      <!-- Read-only lines for the original / locked reversal / posted replacement -->
      <Card v-else>
        <CardHeader><CardTitle>{{ t('entry_lines') }}</CardTitle></CardHeader>
        <CardContent>
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead>
                <tr class="border-b border-[hsl(var(--border))] text-left text-[hsl(var(--muted-foreground))]">
                  <th class="pb-2 font-medium">{{ t('account') }}</th>
                  <th class="pb-2 font-medium">{{ t('description') }}</th>
                  <th class="pb-2 text-right font-medium">{{ t('debit') }}</th>
                  <th class="pb-2 text-right font-medium">{{ t('credit') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-[hsl(var(--border))]">
                <tr v-for="line in entry.lines" :key="line.id">
                  <td class="py-2">{{ line.account?.code }} — {{ line.account?.name }}</td>
                  <td class="py-2">{{ line.description || '—' }}</td>
                  <td class="py-2 text-right font-mono">{{ formatCurrency(line.debit) }}</td>
                  <td class="py-2 text-right font-mono">{{ formatCurrency(line.credit) }}</td>
                </tr>
              </tbody>
              <tfoot>
                <tr class="border-t-2 border-[hsl(var(--border))] font-bold">
                  <td class="pt-3" colspan="2">{{ t('total') }}</td>
                  <td class="pt-3 text-right font-mono">{{ formatCurrency(totalDebit) }}</td>
                  <td class="pt-3 text-right font-mono">{{ formatCurrency(totalCredit) }}</td>
                </tr>
              </tfoot>
            </table>
          </div>
        </CardContent>
      </Card>

      <div>
        <Link href="/accounting/journal-entries">
          <Button variant="outline">{{ t('back') }}</Button>
        </Link>
      </div>
    </div>

    <JournalCorrectionDialog :entry="correctingEntry" @close="correctingEntry = null" />

    <ConfirmDialog
      :open="confirmingPostCorrection"
      :title="t('post_correction')"
      :message="t('confirm_post_correction')"
      @confirm="postCorrection"
      @cancel="confirmingPostCorrection = false"
    />

    <ConfirmDialog
      :open="confirmingCancelCorrection"
      :title="t('cancel_correction')"
      :message="t('confirm_cancel_correction')"
      variant="destructive"
      @confirm="cancelCorrection"
      @cancel="confirmingCancelCorrection = false"
    />

    <ConfirmDialog
      :open="confirmingReverse"
      :title="t('reverse')"
      :message="t('confirm_reverse_journal_entry')"
      @confirm="doReverse"
      @cancel="confirmingReverse = false"
    />
  </AppLayout>
</template>
