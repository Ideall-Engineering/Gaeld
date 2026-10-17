<script setup>
import { computed, ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Modal from '@/Components/UI/Modal.vue'
import Button from '@/Components/UI/Button.vue'
import FormInput from '@/Components/UI/FormInput.vue'
import JournalLinesEditor, { emptyLine, lineFromEntry, serializeLines } from '@/Components/Accounting/JournalLinesEditor.vue'
import { useTranslations } from '@/lib/useTranslations'
import { History } from 'lucide-vue-next'

// Creates a journal entry, edits a draft, or amends a posted entry in place.
// For a posted entry it sends the revision the form was opened with, so a
// change made elsewhere in the meantime is refused instead of overwritten.
const props = defineProps({
  open: { type: Boolean, default: false },
  entry: { type: Object, default: null },
  accounts: { type: Array, default: () => [] },
  vatRates: { type: Array, default: () => [] },
  vatTypes: { type: Array, default: () => [] },
  allocationOptions: { type: Object, default: null },
  redirectTo: { type: String, default: null },
})

const emit = defineEmits(['close'])

const { t } = useTranslations()

const today = () => new Date().toISOString().split('T')[0]

const form = useForm({
  date: today(),
  reference: '',
  description: '',
  is_posted: true,
  revision: null,
  change_reason: '',
  acknowledge_bank_amount_change: false,
  lines: [emptyLine(), emptyLine()],
})

const editor = ref(null)
const isPostedEntry = computed(() => !!props.entry?.is_posted)
const title = computed(() => {
  if (!props.entry) return t('new_journal_entry')
  return isPostedEntry.value ? t('edit_posted_journal_entry') : t('edit_journal_entry')
})
const needsBankConfirmation = computed(() => !!form.errors.acknowledge_bank_amount_change)

// Keyed on the entry's id, not the object: a failed save reloads the page
// props, and resetting the form then would throw away the user's edits.
watch(() => [props.open, props.entry?.id], ([open]) => {
  if (!open) return
  form.clearErrors()
  const entry = props.entry
  if (!entry) {
    form.date = today()
    form.reference = ''
    form.description = ''
    form.is_posted = true
    form.revision = null
    form.lines = [emptyLine(), emptyLine()]
  } else {
    const dateStr = typeof entry.date === 'string' ? entry.date : entry.date?.toString()
    form.date = dateStr ? dateStr.split('T')[0].split(' ')[0] : today()
    form.reference = entry.reference || ''
    form.description = entry.description || ''
    form.is_posted = entry.is_posted
    form.revision = entry.revision ?? 1
    form.lines = (entry.lines || []).map(lineFromEntry)
  }
  form.change_reason = ''
  form.acknowledge_bank_amount_change = false
}, { immediate: true })

function submit(post = true) {
  const options = {
    preserveScroll: true,
    onSuccess: () => emit('close'),
  }

  form.transform(data => ({
    ...data,
    is_posted: post,
    lines: serializeLines(data.lines),
    ...(props.redirectTo ? { redirect_to: props.redirectTo } : {}),
  }))

  if (props.entry) {
    form.put(`/accounting/journal-entries/${props.entry.id}`, options)
  } else {
    form.post('/accounting/journal-entries', options)
  }
}
</script>

<template>
  <Modal :open="open" :title="title" size="xl" @close="emit('close')">
    <form @submit.prevent="submit(true)">
      <div class="space-y-6">
        <p
          v-if="isPostedEntry"
          class="flex items-start gap-2 rounded-md border border-[hsl(var(--border))] bg-[hsl(var(--muted)/0.4)] p-3 text-sm text-[hsl(var(--muted-foreground))]"
        >
          <History class="mt-0.5 h-4 w-4 shrink-0" />
          <span>{{ t('help_journal_text_flexible') }}</span>
        </p>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <FormInput
            id="journal_date"
            v-model="form.date"
            type="date"
            :label="t('date')"
            :error="form.errors.date"
            required
          />
          <FormInput
            id="journal_reference"
            v-model="form.reference"
            :label="t('reference')"
            :error="form.errors.reference"
            :placeholder="t('reference_placeholder')"
          />
          <FormInput
            id="journal_description"
            v-model="form.description"
            :label="t('description')"
            :error="form.errors.description"
          />
        </div>

        <JournalLinesEditor
          ref="editor"
          :lines="form.lines"
          :accounts="accounts"
          :vat-rates="vatRates"
          :vat-types="vatTypes"
          :allocation-options="allocationOptions"
          :errors="form.errors"
          id-prefix="journal"
        />

        <FormInput
          v-if="isPostedEntry"
          id="journal_change_reason"
          v-model="form.change_reason"
          :label="t('change_reason_label')"
          :placeholder="t('change_reason_placeholder')"
          :error="form.errors.change_reason"
        />

        <div
          v-if="needsBankConfirmation"
          class="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200"
        >
          <p>{{ form.errors.acknowledge_bank_amount_change }}</p>
          <label class="mt-2 flex items-start gap-2">
            <input
              v-model="form.acknowledge_bank_amount_change"
              type="checkbox"
              class="mt-0.5 h-4 w-4 rounded border-[hsl(var(--border))]"
            />
            <span>{{ t('acknowledge_bank_amount_change_label') }}</span>
          </label>
        </div>

        <div class="flex justify-end gap-2 pt-2">
          <Button type="button" variant="outline" @click="emit('close')">{{ t('cancel') }}</Button>
          <Button
            v-if="!isPostedEntry"
            type="button"
            variant="outline"
            :disabled="form.processing"
            @click="submit(false)"
          >
            {{ t('save_as_draft') }}
          </Button>
          <Button
            type="submit"
            :disabled="form.processing || !editor?.isBalanced || (needsBankConfirmation && !form.acknowledge_bank_amount_change)"
            :loading="form.processing"
          >
            {{ entry ? t('save_changes') : t('post_entry') }}
          </Button>
        </div>
      </div>
    </form>
  </Modal>
</template>
