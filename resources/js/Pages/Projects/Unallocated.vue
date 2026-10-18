<script setup>
import AppLayout from '@/Components/AppLayout.vue'
import Card from '@/Components/UI/Card.vue'
import CardContent from '@/Components/UI/CardContent.vue'
import Badge from '@/Components/UI/Badge.vue'
import Button from '@/Components/UI/Button.vue'
import Breadcrumb from '@/Components/UI/Breadcrumb.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'
import FormSelect from '@/Components/UI/FormSelect.vue'
import Modal from '@/Components/UI/Modal.vue'
import SearchableSelect from '@/Components/UI/SearchableSelect.vue'
import { useTranslations } from '@/lib/useTranslations'
import { useFormatters } from '@/lib/useFormatters'
import { Link, router, useForm } from '@inertiajs/vue3'
import { computed, reactive, ref, watch } from 'vue'
import { CheckCheck, CheckCircle2, Layers } from 'lucide-vue-next'

// Receipts without a place, worked through like an inbox (spec 010, US2).
// Each row starts with its suggestion, if any; nothing is allocated until
// the ticked rows are taken over. "General" handles costs that belong to
// no job.
const props = defineProps({
  lines: Array,
  coverage: Object,
  limit: Number,
  year: Number,
  withGeneral: Boolean,
  projects: Array,
  blocks: Array,
  can: Object,
})

const { t } = useTranslations()
const { formatCurrency, formatDate } = useFormatters()

const choice = reactive({})
const selected = reactive({})
watch(() => props.lines, (lines) => {
  for (const key of Object.keys(choice)) delete choice[key]
  for (const key of Object.keys(selected)) delete selected[key]
  lines.forEach((l) => {
    choice[l.line_id] = l.suggestion ? l.suggestion.project_id : null
    selected[l.line_id] = false
  })
}, { immediate: true })
const processing = ref(false)

// Jobs first, grouped by their project; projects themselves at the end.
const projectOptions = computed(() => [
  ...props.projects.filter(p => p.kind !== 'area').map(p => ({ value: p.id, label: `${p.name} (${p.code})`, group: p.group ?? t('project_no_area') })),
  ...props.projects.filter(p => p.kind === 'area').map(p => ({ value: p.id, label: `${p.name} (${p.code})`, group: t('project_kind_area') })),
])
const yearOptions = computed(() => {
  const now = new Date().getFullYear()
  return [now + 1, now, now - 1, now - 2].map(y => ({ value: String(y), label: String(y) }))
})
const chosenCount = computed(() => props.lines.filter(l => selected[l.line_id] && choice[l.line_id]).length)
const sourceVariant = { invoice: 'success', customer: 'info', history: 'secondary' }

function selectSuggested() {
  props.lines.forEach(l => { selected[l.line_id] = !!choice[l.line_id] })
}

function reload(params) {
  router.get('/projects/unallocated', { year: props.year, general: props.withGeneral ? 1 : 0, ...params }, { preserveScroll: true })
}

function apply() {
  const items = props.lines
    .filter(l => selected[l.line_id] && choice[l.line_id])
    .map(l => ({ line_id: l.line_id, project_id: Number(choice[l.line_id]) }))
  if (items.length === 0) return
  processing.value = true
  router.post('/projects/unallocated', { items }, { preserveScroll: true, onFinish: () => { processing.value = false } })
}

// "General"
const generalLine = ref(null)
const generalForm = useForm({ line_id: null, scope: 'account', year: props.year, budget_group_id: '' })
const blockOptions = computed(() => [
  { value: '', label: t('general_block_new', { name: generalLine.value?.account ?? '' }) },
  ...props.blocks.map(b => ({ value: b.id, label: b.name })),
])
function openGeneral(line) {
  if (line.type === 'revenue' || !props.can.manage) {
    router.post('/projects/unallocated/general', { line_id: line.line_id, scope: 'line', year: props.year }, { preserveScroll: true })
    return
  }
  generalLine.value = line
  Object.assign(generalForm, { line_id: line.line_id, scope: 'account', year: props.year, budget_group_id: '' })
  generalForm.clearErrors()
}
function submitGeneral() {
  generalForm
    .transform(data => ({ ...data, budget_group_id: data.scope === 'account' && data.budget_group_id ? Number(data.budget_group_id) : null }))
    .post('/projects/unallocated/general', { preserveScroll: true, onSuccess: () => { generalLine.value = null } })
}
</script>

<template>
  <AppLayout :title="t('unallocated_lines')">
    <Breadcrumb :items="[{ label: t('jobs'), href: `/projects?year=${year}` }, { label: t('unallocated_lines') }]" class="mb-4" />

    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
      <div class="flex items-end gap-3">
        <h1 class="text-2xl font-semibold">{{ t('unallocated_lines') }}</h1>
        <FormSelect id="year" :model-value="String(year)" :options="yearOptions" :aria-label="t('year')" class="w-28" @update:model-value="v => reload({ year: v })" />
      </div>
      <div v-if="lines.length" class="flex flex-wrap gap-2">
        <Button variant="outline" @click="selectSuggested"><CheckCheck class="mr-1 h-4 w-4" /> {{ t('unallocated_select_suggested') }}</Button>
        <Button :disabled="chosenCount === 0 || processing" :loading="processing" @click="apply">{{ t('unallocated_apply', { count: chosenCount }) }}</Button>
      </div>
    </div>

    <Card class="mb-4">
      <CardContent class="pt-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div>
            <div class="font-medium">{{ coverage.open === 0 ? t('coverage_all_done', { year }) : t('coverage_open', { count: coverage.open }) }}</div>
            <div class="text-sm text-[hsl(var(--muted-foreground))]">{{ t('coverage_progress', { done: coverage.done, total: coverage.total }) }}</div>
          </div>
          <label class="flex items-center gap-2 text-sm">
            <input :checked="withGeneral" type="checkbox" class="h-4 w-4 rounded border-[hsl(var(--input))]" @change="reload({ general: $event.target.checked ? 1 : 0 })" />
            {{ t('unallocated_show_general') }}
          </label>
        </div>
        <p class="mt-2 text-sm text-[hsl(var(--muted-foreground))]">{{ t('unallocated_desc') }}</p>
        <p v-if="lines.length >= limit" class="mt-2 text-sm text-amber-700">{{ t('unallocated_limited', { count: limit }) }}</p>
      </CardContent>
    </Card>

    <EmptyState v-if="lines.length === 0" :icon="CheckCircle2" :title="t('unallocated_empty_title')" :description="t('unallocated_empty_desc')" />

    <div v-else class="space-y-2">
      <div
        v-for="line in lines"
        :key="line.line_id"
        class="flex flex-wrap items-center gap-3 rounded-lg border border-[hsl(var(--border))] p-3"
        :class="selected[line.line_id] ? 'border-[hsl(var(--primary))] bg-[hsl(var(--primary))]/5' : ''"
      >
        <input v-model="selected[line.line_id]" type="checkbox" class="h-4 w-4 rounded border-[hsl(var(--input))]" :aria-label="t('select')" />
        <div class="min-w-[14rem] flex-1">
          <div class="flex flex-wrap items-baseline gap-x-2">
            <span class="font-medium">{{ line.description || line.line_description || line.reference }}</span>
            <Badge v-if="line.category === 'general'" variant="secondary">{{ t('general') }}</Badge>
          </div>
          <div class="text-xs text-[hsl(var(--muted-foreground))]">
            {{ formatDate(line.date) }} ·
            <Link :href="`/accounting/journal-entries/${line.entry_id}`" class="hover:underline">{{ line.reference || t('journal_entry') }}</Link>
            · {{ line.account }}
          </div>
        </div>
        <div class="font-medium tabular-nums sm:w-32 sm:text-right" :class="line.type === 'revenue' ? 'text-green-700 dark:text-green-400' : ''">
          {{ line.type === 'revenue' ? '+' : '−' }} {{ formatCurrency(line.amount) }}
        </div>
        <div class="flex w-full min-w-0 items-center gap-2 sm:w-auto">
          <SearchableSelect
            :id="`choice_${line.line_id}`"
            v-model="choice[line.line_id]"
            :options="projectOptions"
            group-key="group"
            :placeholder="t('unallocated_choose')"
            force-searchable
            class="min-w-0 flex-1 sm:min-w-[16rem]"
            @update:model-value="selected[line.line_id] = !!$event"
          />
          <Badge v-if="line.suggestion && String(choice[line.line_id]) === String(line.suggestion.project_id)" :variant="sourceVariant[line.suggestion.source]">
            {{ t('suggestion_source_' + line.suggestion.source) }}
          </Badge>
          <Button v-if="line.category !== 'general'" variant="outline" size="sm" class="shrink-0" @click="openGeneral(line)"><Layers class="mr-1 h-4 w-4" /> {{ t('general') }}</Button>
        </div>
      </div>
    </div>

    <Modal :open="!!generalLine" :title="t('general_dialog_title')" @close="generalLine = null">
      <form v-if="generalLine" class="space-y-4" @submit.prevent="submitGeneral">
        <p class="text-sm">{{ generalLine.description || generalLine.line_description }} · {{ formatCurrency(generalLine.amount) }}</p>
        <label class="flex items-start gap-3 rounded-md border border-[hsl(var(--border))] p-3" :class="generalForm.scope === 'account' ? 'border-[hsl(var(--primary))]' : ''">
          <input v-model="generalForm.scope" type="radio" value="account" class="mt-1" />
          <span class="flex-1">
            <span class="block text-sm font-medium">{{ t('general_scope_account', { account: generalLine.account }) }}</span>
            <span class="block text-xs text-[hsl(var(--muted-foreground))]">{{ t('general_scope_account_hint') }}</span>
            <FormSelect v-if="generalForm.scope === 'account'" id="budget_group_id" v-model="generalForm.budget_group_id" :label="t('budget_groups')" :options="blockOptions" :error="generalForm.errors.budget_group_id" class="mt-2" />
          </span>
        </label>
        <label class="flex items-start gap-3 rounded-md border border-[hsl(var(--border))] p-3" :class="generalForm.scope === 'line' ? 'border-[hsl(var(--primary))]' : ''">
          <input v-model="generalForm.scope" type="radio" value="line" class="mt-1" />
          <span>
            <span class="block text-sm font-medium">{{ t('general_scope_line') }}</span>
            <span class="block text-xs text-[hsl(var(--muted-foreground))]">{{ t('general_scope_line_hint') }}</span>
          </span>
        </label>
        <p v-if="generalForm.errors.scope" class="text-sm text-[hsl(var(--destructive))]">{{ generalForm.errors.scope }}</p>
        <div class="flex justify-end gap-2">
          <Button type="button" variant="outline" @click="generalLine = null">{{ t('cancel') }}</Button>
          <Button type="submit" :disabled="generalForm.processing" :loading="generalForm.processing">{{ t('save') }}</Button>
        </div>
      </form>
    </Modal>
  </AppLayout>
</template>
