<script setup>
import { watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Modal from '@/Components/UI/Modal.vue'
import Button from '@/Components/UI/Button.vue'
import LineAllocationEditor, { allocationsFromLine, serializeAllocations } from '@/Components/Projects/LineAllocationEditor.vue'
import { useFormatters } from '@/lib/useFormatters'
import { useTranslations } from '@/lib/useTranslations'
import { FolderKanban } from 'lucide-vue-next'

// Changes only the projects and people of an entry's lines (spec 009). The
// entry itself stays as it is, which is why this works for every entry,
// also in settled, closed or archived periods.
const props = defineProps({
  open: { type: Boolean, default: false },
  entry: { type: Object, default: null },
  options: { type: Object, required: true },
})

const emit = defineEmits(['close'])

const { t } = useTranslations()
const { formatCurrency } = useFormatters()

const form = useForm({ lines: [] })

watch(() => [props.open, props.entry?.id], ([open]) => {
  if (!open || !props.entry) return
  form.clearErrors()
  form.lines = (props.entry.lines || []).map(line => ({
    id: line.id,
    label: `${line.account?.code ?? ''} ${line.account?.name ?? ''}`.trim(),
    debit: line.debit,
    credit: line.credit,
    description: line.description,
    allocations: allocationsFromLine(line) ?? [],
  }))
}, { immediate: true })

function submit() {
  form.transform(data => ({
    lines: data.lines.map(line => ({ id: line.id, allocations: serializeAllocations(line.allocations) })),
  })).put(`/accounting/journal-entries/${props.entry.id}/allocations`, {
    preserveScroll: true,
    onSuccess: () => emit('close'),
  })
}
</script>

<template>
  <Modal :open="open" :title="t('allocation_dialog_title')" size="xl" @close="emit('close')">
    <form class="space-y-4" @submit.prevent="submit">
      <p class="flex items-start gap-2 rounded-md border border-[hsl(var(--border))] bg-[hsl(var(--muted)/0.4)] p-3 text-sm text-[hsl(var(--muted-foreground))]">
        <FolderKanban class="mt-0.5 h-4 w-4 shrink-0" />
        <span>{{ t('allocation_dialog_hint') }}</span>
      </p>
      <div v-if="entry" class="text-sm font-medium">
        {{ entry.reference || t('journal_entry') }} · {{ entry.description }}
      </div>
      <div v-for="(line, index) in form.lines" :key="line.id" class="rounded-md border border-[hsl(var(--border))] p-2">
        <div class="mb-2 flex flex-wrap items-baseline justify-between gap-2 text-sm">
          <span>{{ line.label }}<span v-if="line.description" class="text-[hsl(var(--muted-foreground))]"> · {{ line.description }}</span></span>
          <span class="tabular-nums">
            <template v-if="Number(line.debit) > 0">{{ t('debit') }} {{ formatCurrency(line.debit) }}</template>
            <template v-else>{{ t('credit') }} {{ formatCurrency(line.credit) }}</template>
          </span>
        </div>
        <LineAllocationEditor
          v-model="line.allocations"
          :options="options"
          :errors="form.errors"
          :error-prefix="`lines.${index}.allocations`"
          :id-prefix="`allocation_${index}`"
        />
      </div>
      <div v-if="form.errors.lines" class="text-sm text-[hsl(var(--destructive))]">{{ form.errors.lines }}</div>
      <div class="flex justify-end gap-2">
        <Button type="button" variant="outline" @click="emit('close')">{{ t('cancel') }}</Button>
        <Button type="submit" :disabled="form.processing" :loading="form.processing">{{ t('save') }}</Button>
      </div>
    </form>
  </Modal>
</template>
