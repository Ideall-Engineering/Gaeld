<script setup>
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Components/AppLayout.vue'
import Breadcrumb from '@/Components/UI/Breadcrumb.vue'
import Card from '@/Components/UI/Card.vue'
import CardHeader from '@/Components/UI/CardHeader.vue'
import CardTitle from '@/Components/UI/CardTitle.vue'
import CardContent from '@/Components/UI/CardContent.vue'
import DataTable from '@/Components/UI/DataTable.vue'
import FormInput from '@/Components/UI/FormInput.vue'
import Button from '@/Components/UI/Button.vue'
import ExportDropdown from '@/Components/UI/ExportDropdown.vue'
import SharePrintButton from '@/Components/UI/SharePrintButton.vue'
import { useFormatters } from '@/lib/useFormatters'
import { useTranslations } from '@/lib/useTranslations'
import { ref, computed } from 'vue'

// Revenue, expenses and contribution of one project and everything below it
// (spec 009), read from line allocations only.
const props = defineProps({
  project: Object,
  report: Object,
  filters: Object,
})

const { t } = useTranslations()
const { formatCurrency } = useFormatters()

const from = ref(props.filters.from)
const to = ref(props.filters.to)

function applyFilter() {
  router.get(`/projects/${props.project.id}/report`, { from: from.value, to: to.value }, { preserveState: true })
}

const exportParams = computed(() => ({ from: from.value, to: to.value }))
const title = computed(() => `${t('project_report')} ${props.project.code} ${props.project.name}`)

const accountColumns = [
  { key: 'code', label: t('code') },
  { key: 'name', label: t('account') },
  { key: 'amount', label: t('amount'), class: 'text-right', format: v => formatCurrency(v) },
]

const projectColumns = [
  { key: 'code', label: t('code') },
  { key: 'name', label: t('name') },
  { key: 'revenue', label: t('revenue'), class: 'text-right', format: v => formatCurrency(v) },
  { key: 'expenses', label: t('expenses'), class: 'text-right', format: v => formatCurrency(v) },
  { key: 'contribution', label: t('project_contribution'), class: 'text-right', format: v => formatCurrency(v) },
]
</script>

<template>
  <AppLayout :title="title">
    <Breadcrumb :items="[{ label: t('projects'), href: '/projects' }, { label: `${project.code} ${project.name}` }]" class="mb-4" />
    <p class="mb-4 text-sm text-[hsl(var(--muted-foreground))]">{{ t('project_report_desc') }}</p>

    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
      <div class="flex flex-wrap items-end gap-4">
        <FormInput id="from" v-model="from" type="date" :label="t('from')" />
        <FormInput id="to" v-model="to" type="date" :label="t('to')" />
        <Button @click="applyFilter">{{ t('apply') }}</Button>
      </div>
      <div class="flex items-center gap-2">
        <SharePrintButton :title="title" />
        <ExportDropdown :base-url="`/projects/${project.id}/report/export`" :params="exportParams" />
      </div>
    </div>

    <div class="space-y-6">
      <Card>
        <CardHeader><CardTitle>{{ t('revenue') }}</CardTitle></CardHeader>
        <CardContent>
          <DataTable v-if="report.revenue?.length" :columns="accountColumns" :rows="report.revenue" />
          <p v-else class="text-sm text-[hsl(var(--muted-foreground))]">{{ t('no_data') }}</p>
          <div class="mt-4 flex justify-between border-t pt-3 text-sm font-semibold">
            <span>{{ t('total_revenue') }}</span>
            <span>{{ formatCurrency(report.total_revenue) }}</span>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader><CardTitle>{{ t('expenses') }}</CardTitle></CardHeader>
        <CardContent>
          <DataTable v-if="report.expenses?.length" :columns="accountColumns" :rows="report.expenses" />
          <p v-else class="text-sm text-[hsl(var(--muted-foreground))]">{{ t('no_data') }}</p>
          <div class="mt-4 flex justify-between border-t pt-3 text-sm font-semibold">
            <span>{{ t('total_expenses') }}</span>
            <span>{{ formatCurrency(report.total_expenses) }}</span>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardContent>
          <div class="flex justify-between text-lg font-bold">
            <span>{{ t('project_contribution') }}</span>
            <span :class="Number(report.contribution) >= 0 ? 'text-green-600' : 'text-red-600'">
              {{ formatCurrency(report.contribution) }}
            </span>
          </div>
        </CardContent>
      </Card>

      <Card v-if="report.projects.length > 1">
        <CardHeader><CardTitle>{{ t('project_report_by_project') }}</CardTitle></CardHeader>
        <CardContent>
          <DataTable :columns="projectColumns" :rows="report.projects" />
        </CardContent>
      </Card>
    </div>
  </AppLayout>
</template>
