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
import JournalCorrectionDialog from '@/Components/Accounting/JournalCorrectionDialog.vue'
import JournalEntryFormModal from '@/Components/Accounting/JournalEntryFormModal.vue'
import JournalLinesEditor, { lineFromEntry, serializeLines } from '@/Components/Accounting/JournalLinesEditor.vue'
import JournalEntryHistory from '@/Components/Accounting/JournalEntryHistory.vue'
import AllocationDialog from '@/Components/Projects/AllocationDialog.vue'
import { ExternalLink, FileText, FolderKanban, Lock, Pencil, Replace, RotateCcw, Trash2, Type } from 'lucide-vue-next'
import { useTranslations } from '@/lib/useTranslations'
import { useFormatters } from '@/lib/useFormatters'

const props = defineProps({
  entry: { type: Object, required: true },
  correction: { type: Object, default: null },
  correctionRole: { type: String, default: null },
  accounts: { type: Array, default: () => [] },
  vatRates: { type: Array, default: () => [] },
  vatTypes: { type: Array, default: () => [] },
  postedEntryEditMode: { type: String, default: 'strict' },
  allocationOptions: { type: Object, default: null },
  revisions: { type: Array, default: () => [] },
  can: { type: Object, default: () => ({ correct: false, reverse: false, amend: false }) },
})

const { t } = useTranslations()
const { formatCurrency, formatDate } = useFormatters()

const totalDebit = computed(() => props.entry.lines?.reduce((total, line) => total + Number(line.debit || 0), 0) || 0)
const totalCredit = computed(() => props.entry.lines?.reduce((total, line) => total + Number(line.credit || 0), 0) || 0)

const isFlexible = computed(() => props.postedEntryEditMode === 'flexible')
const showsLockHint = computed(
  () => isFlexible.value && props.entry.is_posted && !props.entry.can_amend && props.entry.edit_state !== 'open',
)
const vatTypeLabels = computed(() => Object.fromEntries(props.vatTypes.map(type => [type.value, type.label])))

// Link to where the original receipt is kept; a pointer, so it can be set
// on posted entries too.
const editingDocument = ref(false)
const documentForm = useForm({ document_url: props.entry.document_url || '' })
function startDocument() {
  documentForm.document_url = props.entry.document_url || ''
  documentForm.clearErrors()
  editingDocument.value = true
}
function saveDocument() {
  documentForm.put(`/accounting/journal-entries/${props.entry.id}/document`, {
    preserveScroll: true,
    onSuccess: () => { editingDocument.value = false },
  })
}

// Sharpen the texts of a posted entry where amounts, accounts and date
// are locked (settled VAT quarter, correction, source workflow).
const editingText = ref(false)
const textForm = useForm({ revision: props.entry.revision, description: '', lines: [], change_reason: '' })
function startText() {
  textForm.revision = props.entry.revision
  textForm.description = props.entry.description || ''
  textForm.lines = (props.entry.lines ?? []).map(line => ({ id: line.id, description: line.description || '' }))
  textForm.clearErrors()
  editingText.value = true
}
const lineText = id => textForm.lines.find(line => line.id === id)
function saveText() {
  textForm.put(`/accounting/journal-entries/${props.entry.id}/text`, {
    preserveScroll: true,
    onSuccess: () => { editingText.value = false },
  })
}

// Amend or delete a posted entry in place
const editing = ref(false)
const confirmingDelete = ref(false)

function doDelete() {
  router.delete(`/accounting/journal-entries/${props.entry.id}`, {
    data: { revision: props.entry.revision },
    onFinish: () => { confirmingDelete.value = false },
  })
}

const isCorrectedOriginal = computed(() => props.correctionRole === 'original')
const isLockedReversal = computed(() => props.correctionRole === 'reversal')
const isOpenReplacementDraft = computed(
  () => props.correctionRole === 'replacement' && props.correction?.status === 'draft',
)
// A posted replacement can be corrected again; its own correction then
// starts from the replacement as the new original.
const isPostedReplacement = computed(
  () => props.correctionRole === 'replacement' && props.correction?.status === 'posted',
)
const canStartCorrection = computed(
  () => props.can?.correct && (!props.correctionRole || isPostedReplacement.value)
    && !props.entry.reversal_of_entry_id && props.entry.is_posted && !props.entry.can_amend,
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
  lines: (props.entry.lines || []).map(lineFromEntry),
})

const replacementEditor = ref(null)
const replacementBalanced = computed(() => !!replacementEditor.value?.isBalanced)

function saveReplacement() {
  replacementForm
    .transform(data => ({ ...data, lines: serializeLines(data.lines) }))
    .put(`/accounting/journal-corrections/${props.correction.id}/replacement`, {
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

// Projects (spec 009): shown wherever lines carry allocations, changeable
// for every entry because the entry itself stays untouched.
const allocating = ref(false)
const showsAllocations = computed(() => (props.entry.lines || []).some(line => Array.isArray(line.allocations)))

function allocationLabel(line) {
  return (line.allocations ?? [])
    .map(a => {
      const who = [a.project?.code, a.employee ? `${a.employee.first_name} ${a.employee.last_name}` : null].filter(Boolean).join(' / ')
      return Number(a.share) === 100 ? who : `${who} ${Number(a.share)} %`
    })
    .join(', ')
}
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
              <Badge v-if="entry.revision > 1" variant="outline">
                {{ t('journal_entry_revision_badge', { revision: entry.revision }) }}
              </Badge>
              <Button v-if="entry.can_amend" size="sm" variant="outline" @click="editing = true">
                <Pencil class="mr-1 h-4 w-4" />
                {{ t('edit') }}
              </Button>
              <Button v-if="can.amend_text && !entry.can_amend && !editingText" size="sm" variant="outline" @click="startText">
                <Type class="mr-1 h-4 w-4" />
                {{ t('journal_entry_text_edit') }}
              </Button>
              <Button v-if="entry.can_amend" size="sm" variant="outline" @click="confirmingDelete = true">
                <Trash2 class="mr-1 h-4 w-4 text-[hsl(var(--destructive))]" />
                {{ t('delete') }}
              </Button>
              <Button v-if="canStartCorrection" size="sm" variant="outline" @click="correctingEntry = entry">
                <Replace class="mr-1 h-4 w-4" />
                {{ t('correct') }}
              </Button>
              <Button v-if="allocationOptions" size="sm" variant="outline" @click="allocating = true">
                <FolderKanban class="mr-1 h-4 w-4" />
                {{ t('allocation_dialog_title') }}
              </Button>
              <Button v-if="canReverse" size="sm" variant="outline" @click="confirmingReverse = true">
                <RotateCcw class="mr-1 h-4 w-4" />
                {{ t('reverse') }}
              </Button>
            </div>
          </div>
        </CardHeader>
        <CardContent>
          <p
            v-if="showsLockHint"
            class="mb-4 flex items-start gap-2 rounded-md border border-[hsl(var(--border))] bg-[hsl(var(--muted)/0.4)] p-3 text-sm"
          >
            <Lock class="mt-0.5 h-4 w-4 shrink-0 text-[hsl(var(--muted-foreground))]" />
            <span>
              {{ t('journal_entry_locked_hint', { reason: entry.edit_state_label }) }}
              <template v-if="can.amend_text">{{ t('journal_entry_text_still_editable') }}</template>
              <button
                v-if="canStartCorrection && entry.suggests_correction"
                type="button"
                class="ml-1 text-[hsl(var(--primary))] hover:underline"
                @click="correctingEntry = entry"
              >
                {{ t('journal_entry_correct_instead') }}
              </button>
            </span>
          </p>
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
              <dd v-if="editingText">
                <FormInput id="text_description" v-model="textForm.description" :aria-label="t('description')" :error="textForm.errors.description" />
              </dd>
              <dd v-else class="font-medium">{{ entry.description || '—' }}</dd>
            </div>
            <div class="sm:col-span-3">
              <dt class="text-[hsl(var(--muted-foreground))]">{{ t('journal_entry_document') }}</dt>
              <dd v-if="editingDocument">
                <form class="mt-1 flex flex-wrap items-start gap-2" @submit.prevent="saveDocument">
                  <FormInput
                    id="document_url"
                    v-model="documentForm.document_url"
                    type="url"
                    class="min-w-0 flex-1"
                    :placeholder="t('journal_entry_document_placeholder')"
                    :aria-label="t('journal_entry_document')"
                    :error="documentForm.errors.document_url"
                  />
                  <Button type="submit" size="sm" :loading="documentForm.processing">{{ t('save') }}</Button>
                  <Button type="button" size="sm" variant="outline" @click="editingDocument = false">{{ t('cancel') }}</Button>
                </form>
              </dd>
              <dd v-else class="flex items-center gap-2 font-medium">
                <a
                  v-if="entry.document_url"
                  :href="entry.document_url"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="inline-flex items-center gap-1 text-[hsl(var(--primary))] hover:underline"
                >
                  <FileText class="h-4 w-4" />{{ t('journal_entry_document_open') }}<ExternalLink class="h-3 w-3" />
                </a>
                <span v-else>—</span>
                <button
                  v-if="can.link_document"
                  type="button"
                  class="rounded p-1 text-[hsl(var(--muted-foreground))] hover:bg-[hsl(var(--accent))]"
                  :title="entry.document_url ? t('journal_entry_document_edit') : t('journal_entry_document_add')"
                  :aria-label="entry.document_url ? t('journal_entry_document_edit') : t('journal_entry_document_add')"
                  @click="startDocument"
                >
                  <Pencil class="h-3.5 w-3.5" />
                </button>
              </dd>
            </div>
          </dl>
          <form v-if="editingText" class="mt-4 flex flex-wrap items-end gap-2 border-t border-[hsl(var(--border))] pt-4" @submit.prevent="saveText">
            <p class="w-full text-xs text-[hsl(var(--muted-foreground))]">{{ t('journal_entry_text_hint') }}</p>
            <FormInput id="text_reason" v-model="textForm.change_reason" class="min-w-0 flex-1" :placeholder="t('journal_entry_text_reason')" :aria-label="t('journal_entry_text_reason')" />
            <Button type="button" size="sm" variant="outline" @click="editingText = false">{{ t('cancel') }}</Button>
            <Button type="submit" size="sm" :loading="textForm.processing">{{ t('save') }}</Button>
            <p v-if="textForm.errors.revision" class="w-full text-xs text-[hsl(var(--destructive))]">{{ textForm.errors.revision }}</p>
          </form>
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

              <JournalLinesEditor
                ref="replacementEditor"
                :lines="replacementForm.lines"
                :accounts="accounts"
                :vat-rates="vatRates"
                :vat-types="vatTypes"
                :allocation-options="allocationOptions"
                :errors="replacementForm.errors"
                id-prefix="replacement"
              />

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
                  <th class="pb-2 font-medium">{{ t('journal_line_vat') }}</th>
                  <th class="pb-2 text-right font-medium">{{ t('debit') }}</th>
                  <th class="pb-2 text-right font-medium">{{ t('credit') }}</th>
                  <th v-if="showsAllocations" class="pb-2 pl-4 font-medium">{{ t('project') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-[hsl(var(--border))]">
                <tr v-for="line in entry.lines" :key="line.id">
                  <td class="py-2">{{ line.account?.code }} — {{ line.account?.name }}</td>
                  <td v-if="editingText && lineText(line.id)" class="py-1 pr-2">
                    <input v-model="lineText(line.id).description" type="text" maxlength="500" :aria-label="t('description')" class="h-8 w-full min-w-[12rem] rounded-md border border-[hsl(var(--input))] bg-transparent px-2 text-sm" />
                  </td>
                  <td v-else class="py-2">{{ line.description || '—' }}</td>
                  <td class="py-2">
                    <span v-if="line.vat_type">{{ vatTypeLabels[line.vat_type] || line.vat_type }} · {{ formatCurrency(line.vat_amount) }}</span>
                    <span v-else class="text-[hsl(var(--muted-foreground))]">—</span>
                  </td>
                  <td class="py-2 text-right font-mono">{{ formatCurrency(line.debit) }}</td>
                  <td class="py-2 text-right font-mono">{{ formatCurrency(line.credit) }}</td>
                  <td v-if="showsAllocations" class="py-2 pl-4 font-mono text-xs">{{ allocationLabel(line) || '—' }}</td>
                </tr>
              </tbody>
              <tfoot>
                <tr class="border-t-2 border-[hsl(var(--border))] font-bold">
                  <td class="pt-3" colspan="3">{{ t('total') }}</td>
                  <td class="pt-3 text-right font-mono">{{ formatCurrency(totalDebit) }}</td>
                  <td class="pt-3 text-right font-mono">{{ formatCurrency(totalCredit) }}</td>
                </tr>
              </tfoot>
            </table>
          </div>
        </CardContent>
      </Card>

      <JournalEntryHistory
        v-if="entry.is_posted && (revisions.length > 0 || isFlexible)"
        :entry="entry"
        :revisions="revisions"
        :vat-types="vatTypes"
      />

      <div>
        <Link href="/accounting/journal-entries">
          <Button variant="outline">{{ t('back') }}</Button>
        </Link>
      </div>
    </div>

    <AllocationDialog
      v-if="allocationOptions"
      :open="allocating"
      :entry="entry"
      :options="allocationOptions"
      @close="allocating = false"
    />

    <JournalEntryFormModal
      :open="editing"
      :entry="editing ? entry : null"
      :accounts="accounts"
      :vat-rates="vatRates"
      :vat-types="vatTypes"
      :allocation-options="allocationOptions"
      :redirect-to="`/accounting/journal-entries/${entry.id}`"
      @close="editing = false"
    />

    <ConfirmDialog
      :open="confirmingDelete"
      :title="t('delete_journal_entry')"
      :message="t('confirm_delete_posted_journal_entry')"
      variant="destructive"
      @confirm="doDelete"
      @cancel="confirmingDelete = false"
    />

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
