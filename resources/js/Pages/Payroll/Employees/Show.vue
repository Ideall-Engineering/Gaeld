<script setup>
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/AppLayout.vue'
import Card from '@/Components/UI/Card.vue'
import CardHeader from '@/Components/UI/CardHeader.vue'
import CardTitle from '@/Components/UI/CardTitle.vue'
import CardContent from '@/Components/UI/CardContent.vue'
import Button from '@/Components/UI/Button.vue'
import Badge from '@/Components/UI/Badge.vue'
import DataTable from '@/Components/UI/DataTable.vue'
import Breadcrumb from '@/Components/UI/Breadcrumb.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'
import { useTranslations } from '@/lib/useTranslations'
import { useFormatters } from '@/lib/useFormatters'
import { computed } from 'vue'
import { FileText, Pencil } from 'lucide-vue-next'

const { t } = useTranslations()
const { formatDate, formatCurrency } = useFormatters()

const props = defineProps({
  employee: Object,
  payrollWritable: { type: Boolean, default: true },
  salarySlips: { type: Array, default: () => [] },
  certificateYears: { type: Array, default: () => [] },
  // Which Form 11 header fields are still empty. Computed server-side because
  // ahv_number is hidden from serialization and cannot be checked here.
  missingCertificateFields: { type: Array, default: () => [] },
})



const postalAddress = computed(() => [
  props.employee.address,
  [props.employee.postal_code, props.employee.city].filter(Boolean).join(' '),
].filter(Boolean).join(', '))

const salaryColumns = computed(() => [
  { key: 'period', label: t('period'), minWidth: 140 },
  { key: 'gross_salary', label: t('gross_salary'), class: 'text-right whitespace-nowrap', minWidth: 140 },
  { key: 'net_salary', label: t('net_salary'), class: 'text-right whitespace-nowrap', minWidth: 140 },
  { key: 'status', label: t('status'), minWidth: 110 },
  { key: 'actions', label: '', class: 'text-right w-auto' },
])
</script>

<template>
  <AppLayout :title="`${employee.first_name} ${employee.last_name}`" help-page="payroll">
    <Breadcrumb
      :items="[{ label: t('payroll'), href: '/payroll/employees' }, { label: t('employees'), href: '/payroll/employees' }, { label: `${employee.first_name} ${employee.last_name}` }]"
      class="mb-4"
    />

    <div class="mb-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
      <!-- Employee details -->
      <Card class="lg:col-span-2">
        <CardHeader>
          <div class="flex flex-wrap items-center justify-between gap-3">
            <CardTitle>{{ employee.first_name }} {{ employee.last_name }}</CardTitle>
            <div class="flex flex-wrap items-center gap-2">
              <Badge :variant="employee.status === 'active' ? 'default' : 'secondary'">
                {{ t('employee_status_' + employee.status) }}
              </Badge>
              <Button v-if="payrollWritable" variant="outline" size="sm" as="a" :href="`/payroll/employees/${employee.id}/edit`">
                <Pencil class="mr-2 h-4 w-4" />
                {{ t('edit') }}
              </Button>
            </div>
          </div>
        </CardHeader>
        <CardContent>
          <div class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2">
            <div>
              <p class="text-[hsl(var(--muted-foreground))]">{{ t('email') }}</p>
              <p class="font-medium">{{ employee.email ?? '—' }}</p>
            </div>
            <div>
              <p class="text-[hsl(var(--muted-foreground))]">{{ t('ahv_number') }}</p>
              <p class="font-medium font-mono tracking-wider">{{ employee.ahv_number ?? '—' }}</p>
            </div>
            <div>
              <p class="text-[hsl(var(--muted-foreground))]">{{ t('start_date') }}</p>
              <p class="font-medium">{{ formatDate(employee.entry_date) }}</p>
            </div>
            <div>
              <p class="text-[hsl(var(--muted-foreground))]">{{ t('gross_salary') }}</p>
              <p class="font-medium font-mono">{{ formatCurrency(employee.gross_salary) }}</p>
            </div>
            <div v-if="employee.date_of_birth">
              <p class="text-[hsl(var(--muted-foreground))]">{{ t('date_of_birth') }}</p>
              <p class="font-medium">{{ formatDate(employee.date_of_birth) }}</p>
            </div>
            <div v-if="employee.job_title">
              <p class="text-[hsl(var(--muted-foreground))]">{{ t('job_title') }}</p>
              <p class="font-medium">{{ employee.job_title }}</p>
            </div>
            <div v-if="employee.employment_rate">
              <p class="text-[hsl(var(--muted-foreground))]">{{ t('employment_rate') }}</p>
              <p class="font-medium">{{ Math.round(Number(employee.employment_rate)) }}%</p>
            </div>
            <div v-if="employee.expense_allowance">
              <p class="text-[hsl(var(--muted-foreground))]">{{ t('expense_allowance') }}</p>
              <p class="font-medium font-mono">{{ formatCurrency(employee.expense_allowance) }}</p>
            </div>
            <div v-if="employee.place_of_origin">
              <p class="text-[hsl(var(--muted-foreground))]">{{ t('place_of_origin') }}</p>
              <p class="font-medium">{{ employee.place_of_origin }}</p>
            </div>
            <div v-if="postalAddress" class="col-span-2">
              <p class="text-[hsl(var(--muted-foreground))]">{{ t('address') }}</p>
              <p class="font-medium">{{ postalAddress }}</p>
            </div>
            <div v-if="employee.iban" class="col-span-2">
              <p class="text-[hsl(var(--muted-foreground))]">{{ t('iban') }}</p>
              <p class="font-medium font-mono">{{ employee.iban }}</p>
            </div>
          </div>

          <p v-if="missingCertificateFields.length" class="mt-4 text-xs text-[hsl(var(--muted-foreground))]">
            {{ t('salary_certificate_incomplete', { fields: missingCertificateFields.join(', ') }) }}
          </p>
        </CardContent>
      </Card>

      <!-- Quick actions -->
      <Card>
        <CardHeader>
          <CardTitle>{{ t('actions') }}</CardTitle>
        </CardHeader>
        <CardContent class="flex flex-col gap-3">
          <Button as="a" href="/payroll/salary-slips" class="w-full" variant="outline">
            {{ t('view_salary_slips') }}
          </Button>
          <Button v-if="payrollWritable" as="a" href="/payroll/run" class="w-full">
            {{ t('run_payroll') }}
          </Button>
          <Button
            v-for="certificateYear in certificateYears"
            :key="certificateYear"
            as="a"
            variant="outline"
            class="w-full"
            :href="`/payroll/employees/${employee.id}/salary-certificate/${certificateYear}`"
          >
            <FileText class="mr-2 h-4 w-4" />
            {{ t('download_salary_certificate', { year: certificateYear }) }}
          </Button>
        </CardContent>
      </Card>
    </div>

    <!-- Salary history -->
    <Card>
      <CardHeader>
        <CardTitle>{{ t('salary_history') }}</CardTitle>
      </CardHeader>
      <CardContent>
        <DataTable
          :columns="salaryColumns"
          :rows="salarySlips"
          :pagination="null"
        >
          <template #cell-period="{ row }">
            {{ row.month_label }}
          </template>
          <template #cell-gross_salary="{ row }">
            <span class="font-mono">{{ formatCurrency(row.gross_salary) }}</span>
          </template>
          <template #cell-net_salary="{ row }">
            <span class="font-mono">{{ formatCurrency(row.net_salary) }}</span>
          </template>
          <template #cell-status="{ row }">
            <Badge :variant="row.status === 'posted' ? 'default' : 'secondary'">
              {{ t('slip_status_' + row.status) }}
            </Badge>
          </template>
          <template #cell-actions="{ row }">
            <Link :href="`/payroll/salary-slips/${row.id}`" class="text-[hsl(var(--primary))] hover:underline text-sm">
              {{ t('view') }}
            </Link>
          </template>
        </DataTable>
        <EmptyState v-if="!salarySlips.length" :title="t('no_salary_slips')" />
      </CardContent>
    </Card>
  </AppLayout>
</template>
