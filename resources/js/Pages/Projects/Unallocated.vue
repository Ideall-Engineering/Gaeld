<script setup>
import AppLayout from '@/Components/AppLayout.vue'
import Card from '@/Components/UI/Card.vue'
import CardHeader from '@/Components/UI/CardHeader.vue'
import CardTitle from '@/Components/UI/CardTitle.vue'
import CardContent from '@/Components/UI/CardContent.vue'
import Badge from '@/Components/UI/Badge.vue'
import Button from '@/Components/UI/Button.vue'
import Breadcrumb from '@/Components/UI/Breadcrumb.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'
import FormSelect from '@/Components/UI/FormSelect.vue'
import { useTranslations } from '@/lib/useTranslations'
import { useFormatters } from '@/lib/useFormatters'
import { Link, router } from '@inertiajs/vue3'
import { computed, reactive, ref } from 'vue'
import { CheckCheck, ListTodo } from 'lucide-vue-next'

// Revenue and expense lines of a year without a project (spec 009). Each
// row starts with its suggestion, if any; nothing is allocated until the
// chosen rows are taken over.
const props = defineProps({
  lines: Array,
  limit: Number,
  year: Number,
  projects: Array,
})

const { t } = useTranslations()
const { formatCurrency, formatDate } = useFormatters()

const choice = reactive(Object.fromEntries(props.lines.map(l => [l.line_id, l.suggestion ? String(l.suggestion.project_id) : ''])))
const selected = reactive(Object.fromEntries(props.lines.map(l => [l.line_id, false])))
const processing = ref(false)

const projectOptions = computed(() => [
  { value: '', label: t('select_placeholder') },
  ...props.projects.map(p => ({ value: String(p.id), label: `${'· '.repeat(p.depth)}${p.code} ${p.name}` })),
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

function changeYear(value) {
  router.get('/projects/unallocated', { year: value })
}

function apply() {
  const items = props.lines
    .filter(l => selected[l.line_id] && choice[l.line_id])
    .map(l => ({ line_id: l.line_id, project_id: Number(choice[l.line_id]) }))
  if (items.length === 0) return
  processing.value = true
  router.post('/projects/unallocated', { items }, { preserveScroll: true, onFinish: () => { processing.value = false } })
}
</script>

<template>
  <AppLayout :title="t('unallocated_lines')">
    <Breadcrumb :items="[{ label: t('projects'), href: '/projects' }, { label: t('unallocated_lines') }]" class="mb-4" />
    <Card>
      <CardHeader>
        <div class="flex flex-wrap items-end justify-between gap-3">
          <CardTitle>{{ t('unallocated_lines') }}</CardTitle>
          <div class="flex flex-wrap items-end gap-2">
            <FormSelect id="year" :model-value="String(year)" :label="t('year')" :options="yearOptions" @update:model-value="changeYear" />
            <Button size="sm" variant="outline" @click="selectSuggested"><CheckCheck class="mr-1 h-4 w-4" /> {{ t('unallocated_select_suggested') }}</Button>
            <Button size="sm" :disabled="chosenCount === 0 || processing" :loading="processing" @click="apply">
              {{ t('unallocated_apply', { count: chosenCount }) }}
            </Button>
          </div>
        </div>
      </CardHeader>
      <CardContent>
        <p class="mb-4 text-sm text-[hsl(var(--muted-foreground))]">{{ t('unallocated_desc') }}</p>
        <p v-if="lines.length >= limit" class="mb-4 text-sm text-amber-700">{{ t('unallocated_limited', { count: limit }) }}</p>
        <EmptyState v-if="lines.length === 0" :icon="ListTodo" :title="t('unallocated_empty_title')" :description="t('unallocated_empty_desc')" />
        <div v-else class="overflow-x-auto">
          <table class="w-full text-sm [&_td]:pr-3 [&_th]:pr-3">
            <thead>
              <tr class="border-b text-left text-[hsl(var(--muted-foreground))]">
                <th class="w-8 pb-2"></th>
                <th class="pb-2">{{ t('date') }}</th>
                <th class="pb-2">{{ t('reference') }}</th>
                <th class="pb-2">{{ t('description') }}</th>
                <th class="pb-2">{{ t('account') }}</th>
                <th class="pb-2 text-right">{{ t('amount') }}</th>
                <th class="pb-2 pl-4">{{ t('project') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-[hsl(var(--border))]">
              <tr v-for="line in lines" :key="line.line_id">
                <td class="py-2"><input v-model="selected[line.line_id]" type="checkbox" class="h-4 w-4 rounded border-[hsl(var(--input))]" :aria-label="t('select')" /></td>
                <td class="py-2 whitespace-nowrap">{{ formatDate(line.date) }}</td>
                <td class="py-2"><Link :href="`/accounting/journal-entries/${line.entry_id}`" class="hover:underline">{{ line.reference || t('journal_entry') }}</Link></td>
                <td class="py-2">{{ line.description }}<div v-if="line.line_description && line.line_description !== line.description" class="text-xs text-[hsl(var(--muted-foreground))]">{{ line.line_description }}</div></td>
                <td class="py-2">{{ line.account }}</td>
                <td class="py-2 text-right tabular-nums whitespace-nowrap">{{ formatCurrency(line.amount) }}</td>
                <td class="py-2 pl-4">
                  <div class="flex items-center gap-2">
                    <FormSelect :id="`choice_${line.line_id}`" v-model="choice[line.line_id]" :options="projectOptions" class="min-w-[14rem]" @update:model-value="selected[line.line_id] = !!$event" />
                    <Badge v-if="line.suggestion && choice[line.line_id] === String(line.suggestion.project_id)" :variant="sourceVariant[line.suggestion.source]">
                      {{ t('suggestion_source_' + line.suggestion.source) }}
                    </Badge>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </CardContent>
    </Card>
  </AppLayout>
</template>
