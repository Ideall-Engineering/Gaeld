<script setup>
import { computed } from 'vue'
import Card from '@/Components/UI/Card.vue'
import CardHeader from '@/Components/UI/CardHeader.vue'
import CardTitle from '@/Components/UI/CardTitle.vue'
import CardContent from '@/Components/UI/CardContent.vue'
import Badge from '@/Components/UI/Badge.vue'
import { useFormatters } from '@/lib/useFormatters'
import { useTranslations } from '@/lib/useTranslations'

// Every state a posted entry has had, newest first, each compared with the
// state that replaced it.
const props = defineProps({
  entry: { type: Object, required: true },
  revisions: { type: Array, default: () => [] },
  vatTypes: { type: Array, default: () => [] },
})

const { t } = useTranslations()
const { formatCurrency, formatDate } = useFormatters()

const vatTypeLabels = computed(() => Object.fromEntries(props.vatTypes.map(type => [type.value, type.label])))

function dateOnly(value) {
  if (!value) return null
  return String(value).split('T')[0].split(' ')[0]
}

const currentState = computed(() => ({
  entry: {
    date: dateOnly(props.entry.date),
    reference: props.entry.reference,
    description: props.entry.description,
  },
  lines: (props.entry.lines || []).map(line => ({
    // Same label the revisions carry, so an unchanged account reads the same on both sides.
    account: line.account ? { code: line.account.code, name: line.account.display_name ?? line.account.name } : null,
    debit: String(line.debit),
    credit: String(line.credit),
    description: line.description,
    vat_type: line.vat_type,
    vat_amount: line.vat_amount,
  })),
}))

function lineKey(line) {
  return [
    line.account?.code ?? '',
    Number(line.debit || 0).toFixed(2),
    Number(line.credit || 0).toFixed(2),
    line.vat_type ?? '',
    line.vat_type ? Number(line.vat_amount || 0).toFixed(2) : '',
    line.description ?? '',
  ].join('|')
}

function headerChanges(before, after) {
  return ['date', 'reference', 'description']
    .filter(field => (before.entry[field] ?? '') !== (after.entry[field] ?? ''))
    .map(field => ({
      field,
      before: field === 'date' ? formatDate(before.entry[field]) : (before.entry[field] || '—'),
      after: field === 'date' ? formatDate(after.entry[field]) : (after.entry[field] || '—'),
    }))
}

function markLines(lines, otherLines) {
  const remaining = otherLines.map(lineKey)
  return lines.map(line => {
    const index = remaining.indexOf(lineKey(line))
    if (index !== -1) remaining.splice(index, 1)
    return { ...line, changed: index === -1 }
  })
}

const items = computed(() => {
  const states = props.revisions.map(revision => ({ entry: revision.entry, lines: revision.lines }))
  return props.revisions
    .map((revision, index) => {
      const after = index + 1 < states.length ? states[index + 1] : currentState.value
      const before = states[index]
      return {
        ...revision,
        changes: headerChanges(before, after),
        linesBefore: markLines(before.lines, after.lines),
        linesAfter: markLines(after.lines, before.lines),
      }
    })
    .reverse()
})

function sourceLabel(source) {
  return source === 'api' ? t('journal_history_source_api') : t('journal_history_source_web')
}

function lineLabel(line) {
  const account = line.account ? `${line.account.code} ${line.account.name}` : '—'
  const vat = line.vat_type ? ` · ${vatTypeLabels.value[line.vat_type] || line.vat_type} ${formatCurrency(line.vat_amount)}` : ''
  return `${account}${vat}`
}
</script>

<template>
  <Card>
    <CardHeader>
      <CardTitle>{{ t('journal_history_title') }}</CardTitle>
    </CardHeader>
    <CardContent>
      <p v-if="revisions.length === 0" class="text-sm text-[hsl(var(--muted-foreground))]">
        {{ t('journal_history_empty') }}
      </p>

      <ol v-else class="space-y-4">
        <li class="flex flex-wrap items-center gap-2 text-sm">
          <Badge variant="success">{{ t('journal_history_current') }}</Badge>
          <span class="font-medium">{{ t('journal_history_version', { revision: entry.revision }) }}</span>
        </li>

        <li
          v-for="item in items"
          :key="item.id"
          class="rounded-md border border-[hsl(var(--border))] p-3 text-sm"
        >
          <div class="flex flex-wrap items-center gap-2">
            <span class="font-medium">{{ t('journal_history_version', { revision: item.revision }) }}</span>
            <span class="text-[hsl(var(--muted-foreground))]">
              {{ t('journal_history_replaced', { date: formatDate(item.recorded_at), user: item.user || t('journal_history_unknown_user') }) }}
            </span>
            <Badge variant="outline">{{ sourceLabel(item.source) }}</Badge>
          </div>

          <p class="mt-2">
            <span class="text-[hsl(var(--muted-foreground))]">{{ t('journal_history_reason') }}:</span>
            {{ item.reason || t('journal_history_no_reason') }}
          </p>

          <div class="mt-2">
            <div class="text-[hsl(var(--muted-foreground))]">{{ t('journal_history_changes') }}</div>
            <ul v-if="item.changes.length" class="mt-1 space-y-1">
              <li v-for="change in item.changes" :key="change.field">
                <span class="font-medium">{{ t(change.field) }}:</span>
                <span class="line-through decoration-[hsl(var(--destructive))]">{{ change.before }}</span>
                →
                <span>{{ change.after }}</span>
              </li>
            </ul>
            <div class="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-2">
              <div>
                <div class="mb-1 text-xs uppercase tracking-wide text-[hsl(var(--muted-foreground))]">
                  {{ t('journal_history_version', { revision: item.revision }) }}
                </div>
                <ul class="space-y-1">
                  <li
                    v-for="(line, index) in item.linesBefore"
                    :key="`b-${index}`"
                    class="flex justify-between gap-2 rounded px-1"
                    :class="line.changed ? 'bg-[hsl(var(--destructive)/0.1)]' : ''"
                  >
                    <span>{{ lineLabel(line) }}</span>
                    <span class="font-mono">{{ Number(line.debit) > 0 ? formatCurrency(line.debit) : '' }}{{ Number(line.credit) > 0 ? formatCurrency(line.credit) : '' }} {{ Number(line.debit) > 0 ? t('debit') : t('credit') }}</span>
                  </li>
                </ul>
              </div>
              <div>
                <div class="mb-1 text-xs uppercase tracking-wide text-[hsl(var(--muted-foreground))]">
                  {{ t('journal_history_version', { revision: item.revision + 1 }) }}
                </div>
                <ul class="space-y-1">
                  <li
                    v-for="(line, index) in item.linesAfter"
                    :key="`a-${index}`"
                    class="flex justify-between gap-2 rounded px-1"
                    :class="line.changed ? 'bg-[hsl(var(--success)/0.1)]' : ''"
                  >
                    <span>{{ lineLabel(line) }}</span>
                    <span class="font-mono">{{ Number(line.debit) > 0 ? formatCurrency(line.debit) : '' }}{{ Number(line.credit) > 0 ? formatCurrency(line.credit) : '' }} {{ Number(line.debit) > 0 ? t('debit') : t('credit') }}</span>
                  </li>
                </ul>
              </div>
            </div>
            <p
              v-if="!item.changes.length && !item.linesBefore.some(l => l.changed) && !item.linesAfter.some(l => l.changed)"
              class="mt-1 text-[hsl(var(--muted-foreground))]"
            >
              {{ t('journal_history_no_changes') }}
            </p>
          </div>
        </li>
      </ol>
    </CardContent>
  </Card>
</template>
