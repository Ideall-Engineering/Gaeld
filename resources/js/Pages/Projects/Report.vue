<script setup>
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/AppLayout.vue'
import Breadcrumb from '@/Components/UI/Breadcrumb.vue'
import Card from '@/Components/UI/Card.vue'
import CardHeader from '@/Components/UI/CardHeader.vue'
import CardTitle from '@/Components/UI/CardTitle.vue'
import CardContent from '@/Components/UI/CardContent.vue'
import Badge from '@/Components/UI/Badge.vue'
import Button from '@/Components/UI/Button.vue'
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue'
import FormInput from '@/Components/UI/FormInput.vue'
import FormSelect from '@/Components/UI/FormSelect.vue'
import ExportDropdown from '@/Components/UI/ExportDropdown.vue'
import ProjectFormModal from '@/Components/Projects/ProjectFormModal.vue'
import { useFormatters } from '@/lib/useFormatters'
import { useTranslations } from '@/lib/useTranslations'
import { ref, computed } from 'vue'
import { Pencil, Plus, Trash2 } from 'lucide-vue-next'

// The page of a job or a project (spec 010, US4): what it brought in and
// cost, who works on it with which net share, and every allocated receipt.
const props = defineProps({
  project: Object,
  report: Object,
  value: String,
  split: Array,
  filters: Object,
  options: Object,
  can: Object,
})

const { t } = useTranslations()
const { formatCurrency, formatDate } = useFormatters()

const isGroup = computed(() => props.project.kind === 'area')
const statusVariant = { possible: 'info', in_progress: 'warning', done: 'success', lost: 'destructive' }
const statusLabel = value => props.options.statuses.find(s => s.value === value)?.label ?? value

// A year is what people pick; a free period stays possible under "more".
const year = computed(() => props.filters.from.slice(0, 4))
const isWholeYear = computed(() => props.filters.from.endsWith('-01-01') && props.filters.to === `${year.value}-12-31`)
const yearOptions = computed(() => {
  const now = new Date().getFullYear()
  return [now + 1, now, now - 1, now - 2, now - 3].map(y => ({ value: String(y), label: String(y) }))
})
function changeYear(value) {
  router.get(`/projects/${props.project.id}/report`, { from: `${value}-01-01`, to: `${value}-12-31` }, { preserveScroll: true })
}
const from = ref(props.filters.from)
const to = ref(props.filters.to)
const showPeriod = ref(!isWholeYear.value)
function applyPeriod() {
  router.get(`/projects/${props.project.id}/report`, { from: from.value, to: to.value }, { preserveState: true })
}
const exportParams = computed(() => ({ from: props.filters.from, to: props.filters.to }))

const margin = computed(() => Number(props.report.total_revenue) > 0
  ? Math.round((Number(props.report.contribution) / Number(props.report.total_revenue)) * 100)
  : null)
const usesExpected = computed(() => !isGroup.value && Number(props.report.total_revenue) === 0 && Number(props.value) !== Number(props.report.contribution))

const jobs = computed(() => props.report.projects.filter(p => p.id !== props.project.id))
const jobName = computed(() => Object.fromEntries(props.report.projects.map(p => [p.id, p.name])))

const showForm = ref(false)
const showDelete = ref(false)
const deleting = ref(false)
function performDelete() {
  deleting.value = true
  router.delete(`/projects/${props.project.id}`, {
    data: { to_index: 1 },
    onFinish: () => { deleting.value = false; showDelete.value = false },
  })
}
const showNewJob = ref(false)
</script>

<template>
  <AppLayout :title="project.name">
    <Breadcrumb
      :items="[
        { label: t('jobs'), href: `/projects?year=${year}` },
        ...(project.parent ? [{ label: project.parent.name, href: `/projects/${project.parent.id}/report?from=${filters.from}&to=${filters.to}` }] : []),
        { label: project.name },
      ]"
      class="mb-4"
    />

    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold">{{ project.name }}</h1>
        <div class="mt-1 flex flex-wrap items-center gap-2 text-sm text-[hsl(var(--muted-foreground))]">
          <span>{{ isGroup ? t('project_kind_area') : t('project_kind_' + project.kind) }} · {{ project.code }}</span>
          <Badge v-if="!isGroup" :variant="statusVariant[project.status] ?? 'default'">{{ statusLabel(project.status) }}</Badge>
          <span v-if="project.contact_name">· {{ project.contact_name }}</span>
          <span v-if="project.target_date">· {{ t('project_target_date') }} {{ formatDate(project.target_date) }}</span>
        </div>
      </div>
      <div class="flex flex-wrap items-end gap-2">
        <FormSelect id="year" :model-value="isWholeYear ? year : ''" :options="isWholeYear ? yearOptions : [{ value: '', label: `${formatDate(filters.from)} – ${formatDate(filters.to)}` }, ...yearOptions]" :aria-label="t('year')" class="w-40" @update:model-value="v => v && changeYear(v)" />
        <ExportDropdown :base-url="`/projects/${project.id}/report/export`" :params="exportParams" />
        <Button v-if="can.manage" variant="outline" @click="showForm = true"><Pencil class="mr-1 h-4 w-4" /> {{ t('edit') }}</Button>
        <Button v-if="can.manage && report.lines.length === 0 && jobs.length === 0" variant="ghost" size="icon" :aria-label="t('delete')" @click="showDelete = true">
          <Trash2 class="h-4 w-4 text-[hsl(var(--destructive))]" />
        </Button>
      </div>
    </div>

    <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
      <div class="rounded-lg border border-[hsl(var(--border))] p-4">
        <div class="text-xs text-[hsl(var(--muted-foreground))]">{{ t('revenue') }}</div>
        <div class="text-xl font-semibold tabular-nums">{{ formatCurrency(report.total_revenue) }}</div>
        <div v-if="!isGroup && project.expected_amount && Number(report.total_revenue) === 0" class="text-xs text-[hsl(var(--muted-foreground))]">{{ t('job_expected_hint', { amount: formatCurrency(project.expected_amount) }) }}</div>
      </div>
      <div class="rounded-lg border border-[hsl(var(--border))] p-4">
        <div class="text-xs text-[hsl(var(--muted-foreground))]">{{ t('expenses') }}</div>
        <div class="text-xl font-semibold tabular-nums">{{ formatCurrency(report.total_expenses) }}</div>
      </div>
      <div class="rounded-lg border border-[hsl(var(--border))] p-4">
        <div class="text-xs text-[hsl(var(--muted-foreground))]">{{ t('project_net') }}</div>
        <div class="text-xl font-semibold tabular-nums" :class="Number(report.contribution) < 0 ? 'text-[hsl(var(--destructive))]' : 'text-green-700 dark:text-green-400'">{{ formatCurrency(report.contribution) }}</div>
      </div>
      <div class="rounded-lg border border-[hsl(var(--border))] p-4">
        <div class="text-xs text-[hsl(var(--muted-foreground))]">{{ t('job_margin') }}</div>
        <div class="text-xl font-semibold tabular-nums">{{ margin === null ? '·' : `${margin} %` }}</div>
      </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
      <div class="space-y-4 lg:col-span-2">
        <Card v-if="isGroup || jobs.length">
          <CardHeader>
            <div class="flex items-center justify-between gap-2">
              <CardTitle>{{ t('jobs') }}</CardTitle>
              <Button v-if="can.manage" size="sm" variant="outline" @click="showNewJob = true"><Plus class="mr-1 h-3 w-3" /> {{ t('job_new') }}</Button>
            </div>
          </CardHeader>
          <CardContent>
            <table class="w-full text-sm">
              <thead>
                <tr class="text-left text-xs text-[hsl(var(--muted-foreground))]">
                  <th class="pb-2 font-medium">{{ t('project') }}</th>
                  <th class="pb-2 text-right font-medium">{{ t('revenue') }}</th>
                  <th class="pb-2 text-right font-medium">{{ t('expenses') }}</th>
                  <th class="pb-2 text-right font-medium">{{ t('project_net') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-[hsl(var(--border))]">
                <tr v-for="job in jobs" :key="job.id">
                  <td class="py-2"><Link :href="`/projects/${job.id}/report?from=${filters.from}&to=${filters.to}`" class="hover:underline">{{ job.name }}</Link></td>
                  <td class="py-2 text-right tabular-nums">{{ formatCurrency(job.revenue) }}</td>
                  <td class="py-2 text-right tabular-nums">{{ formatCurrency(job.expenses) }}</td>
                  <td class="py-2 text-right font-medium tabular-nums" :class="Number(job.contribution) < 0 ? 'text-[hsl(var(--destructive))]' : ''">{{ formatCurrency(job.contribution) }}</td>
                </tr>
              </tbody>
            </table>
          </CardContent>
        </Card>

        <Card>
          <CardHeader><CardTitle>{{ t('job_lines') }}</CardTitle></CardHeader>
          <CardContent>
            <p v-if="report.lines.length === 0" class="text-sm text-[hsl(var(--muted-foreground))]">{{ t('job_lines_empty') }}</p>
            <div v-else class="overflow-x-auto">
              <table class="w-full text-sm [&_td]:pr-3 [&_th]:pr-3">
                <thead>
                  <tr class="text-left text-xs text-[hsl(var(--muted-foreground))]">
                    <th class="pb-2 font-medium">{{ t('date') }}</th>
                    <th class="pb-2 font-medium">{{ t('description') }}</th>
                    <th class="pb-2 font-medium">{{ t('account') }}</th>
                    <th class="pb-2 text-right font-medium">{{ t('revenue') }}</th>
                    <th class="pb-2 text-right font-medium">{{ t('expenses') }}</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-[hsl(var(--border))]">
                  <tr v-for="line in report.lines" :key="`${line.line_id}-${line.project_id}`">
                    <td class="py-2 whitespace-nowrap">{{ formatDate(line.date) }}</td>
                    <td class="py-2">
                      <Link :href="`/accounting/journal-entries/${line.entry_id}`" class="hover:underline">{{ line.description || line.reference }}</Link>
                      <div class="text-xs text-[hsl(var(--muted-foreground))]">
                        {{ line.reference }}<template v-if="Number(line.share) !== 100"> · {{ Number(line.share) }} %</template><template v-if="line.project_id !== project.id"> · {{ jobName[line.project_id] }}</template>
                      </div>
                    </td>
                    <td class="py-2 text-xs">{{ line.account }}</td>
                    <td class="py-2 text-right tabular-nums whitespace-nowrap">{{ line.side === 'revenue' ? formatCurrency(line.amount) : '' }}</td>
                    <td class="py-2 text-right tabular-nums whitespace-nowrap">{{ line.side === 'expenses' ? formatCurrency(line.amount) : '' }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </CardContent>
        </Card>
      </div>

      <div class="space-y-4">
        <Card v-if="!isGroup">
          <CardHeader><CardTitle>{{ t('project_shares') }}</CardTitle></CardHeader>
          <CardContent>
            <p v-if="!can.steer" class="text-sm text-[hsl(var(--muted-foreground))]">{{ t('job_people_owner_only') }}</p>
            <template v-else>
              <p v-if="split.length === 0" class="text-sm text-[hsl(var(--muted-foreground))]">{{ t('job_people_none') }}</p>
              <table v-else class="w-full text-sm">
                <thead>
                  <tr class="text-left text-xs text-[hsl(var(--muted-foreground))]">
                    <th class="pb-2 font-medium">{{ t('acquisition_person') }}</th>
                    <th class="pb-2 text-right font-medium">{{ t('allocation_share') }}</th>
                    <th class="pb-2 text-right font-medium">{{ t('job_part') }}</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-[hsl(var(--border))]">
                  <tr v-for="person in split" :key="person.employee_id">
                    <td class="py-2">{{ person.name }}</td>
                    <td class="py-2 text-right tabular-nums">{{ Number(person.share) }}</td>
                    <td class="py-2 text-right font-medium tabular-nums">{{ formatCurrency(person.part) }}</td>
                  </tr>
                </tbody>
              </table>
              <p v-if="split.length && split[0].inherited" class="mt-2 text-xs text-[hsl(var(--muted-foreground))]">{{ t('job_people_inherited') }}</p>
              <p v-if="usesExpected" class="mt-2 text-xs text-[hsl(var(--muted-foreground))]">{{ t('job_value_expected') }}</p>
              <Button v-if="can.manage" size="sm" variant="outline" class="mt-3" @click="showForm = true"><Pencil class="mr-1 h-3 w-3" /> {{ t('edit') }}</Button>
            </template>
          </CardContent>
        </Card>

        <Card v-if="project.notes">
          <CardHeader><CardTitle>{{ t('notes') }}</CardTitle></CardHeader>
          <CardContent><p class="whitespace-pre-line text-sm">{{ project.notes }}</p></CardContent>
        </Card>

        <div class="text-sm">
          <button type="button" class="text-[hsl(var(--muted-foreground))] hover:underline" @click="showPeriod = !showPeriod">{{ t('more') }}</button>
          <div v-if="showPeriod" class="mt-2 flex flex-wrap items-end gap-2">
            <FormInput id="from" v-model="from" type="date" :label="t('from')" />
            <FormInput id="to" v-model="to" type="date" :label="t('to')" />
            <Button size="sm" @click="applyPeriod">{{ t('apply') }}</Button>
          </div>
        </div>
      </div>
    </div>

    <ProjectFormModal :open="showForm" :project="project" :options="options" :can-steer="can.steer" @close="showForm = false" />
    <ProjectFormModal :open="showNewJob" :preset="{ parent_id: project.id }" :options="options" :can-steer="can.steer" @close="showNewJob = false" />
    <ConfirmDialog
      :open="showDelete"
      :title="t('confirm_delete')"
      :message="project.name"
      :processing="deleting"
      @confirm="performDelete"
      @cancel="showDelete = false"
    />
  </AppLayout>
</template>
