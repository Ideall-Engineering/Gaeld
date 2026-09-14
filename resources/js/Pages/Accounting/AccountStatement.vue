<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/AppLayout.vue'
import Card from '@/Components/UI/Card.vue'
import CardHeader from '@/Components/UI/CardHeader.vue'
import CardTitle from '@/Components/UI/CardTitle.vue'
import CardDescription from '@/Components/UI/CardDescription.vue'
import CardContent from '@/Components/UI/CardContent.vue'
import Button from '@/Components/UI/Button.vue'
import DataTable from '@/Components/UI/DataTable.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'
import { ArrowLeft, ChevronLeft, ChevronRight, BookOpen } from 'lucide-vue-next'
import { useFormatters } from '@/lib/useFormatters'
import { useTranslations } from '@/lib/useTranslations'

const props = defineProps({
  account: { type: Object, required: true },
  mode: { type: String, default: 'month' },
  month: { type: String, default: null },
  previousMonth: { type: String, default: null },
  nextMonth: { type: String, default: null },
  period: { type: Object, default: null },
  basis: { type: String, default: 'operational' },
  backUrl: { type: String, required: true },
  backLabel: { type: String, required: true },
  statement: { type: Object, required: true },
})

const { t } = useTranslations()
const { formatCurrency, formatDate, intlMonthName } = useFormatters()

const isMonthMode = computed(() => props.mode === 'month')

const periodLabel = computed(() => {
  if (isMonthMode.value) {
    const [year, month] = props.month.split('-')

    return `${intlMonthName(Number(month) - 1)} ${year}`
  }

  return `${formatDate(props.period.from)} – ${formatDate(props.period.to)}`
})

// Only meaningful inside a single fiscal year; the backend sends null otherwise.
const carriesBalance = computed(() => props.statement.openingBalance !== null)

function href(month) {
  return `/accounting/accounts/${props.account.uuid}/statement?month=${month}`
}

const columns = computed(() => [
  { key: 'date', label: t('date'), format: v => formatDate(v) },
  { key: 'reference', label: t('reference') },
  { key: 'description', label: t('description') },
  { key: 'counterAccounts', label: t('statement_counter_account') },
  { key: 'debit', label: t('debit'), class: 'text-right', format: v => Number(v) > 0 ? formatCurrency(v) : '' },
  { key: 'credit', label: t('credit'), class: 'text-right', format: v => Number(v) > 0 ? formatCurrency(v) : '' },
  ...(carriesBalance.value
    ? [{ key: 'balance', label: t('statement_running_balance'), class: 'text-right', format: v => formatCurrency(v) }]
    : []),
])
</script>

<template>
  <AppLayout :title="`${account.code} ${account.name}`" help-page="accounting-basics">
    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
      <div class="flex items-start gap-3">
        <!-- Back to wherever this account was opened from, and for the figure it
             was opened on — the server builds the target, this only follows it. -->
        <Button as="a" :href="backUrl" variant="outline" size="sm">
          <ArrowLeft class="h-4 w-4 sm:mr-2" />
          <span class="hidden sm:inline">{{ backLabel }}</span>
        </Button>
        <div>
          <h1 class="text-xl font-semibold">
            <span class="text-gray-400 tabular-nums dark:text-gray-500">{{ account.code }}</span>
            <span class="ml-2">{{ account.name }}</span>
          </h1>
          <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ account.typeLabel }}</p>
        </div>
      </div>

      <div v-if="isMonthMode" class="flex items-center gap-1">
        <Link
          :href="href(previousMonth)"
          class="rounded-md p-1.5 text-gray-500 transition hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-800"
          :aria-label="t('previous_month')"
        >
          <ChevronLeft class="h-4 w-4" />
        </Link>
        <span class="min-w-[9rem] text-center text-sm font-medium">{{ periodLabel }}</span>
        <Link
          :href="href(nextMonth)"
          class="rounded-md p-1.5 text-gray-500 transition hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-800"
          :aria-label="t('next_month')"
        >
          <ChevronRight class="h-4 w-4" />
        </Link>
      </div>
      <span v-else class="text-sm font-medium tabular-nums text-gray-600 dark:text-gray-300">{{ periodLabel }}</span>
    </div>

    <Card>
      <CardHeader>
        <CardTitle>{{ t('statement_title') }}</CardTitle>
        <CardDescription>{{ isMonthMode ? t('statement_desc') : t('statement_period_desc') }}</CardDescription>
      </CardHeader>
      <CardContent>
        <div class="mb-4 flex flex-wrap gap-6 border-b border-gray-200 pb-4 text-sm dark:border-gray-700">
          <div v-if="carriesBalance">
            <p class="text-gray-500 dark:text-gray-400">{{ t('statement_opening_balance') }}</p>
            <p class="mt-0.5 font-medium tabular-nums">{{ formatCurrency(statement.openingBalance) }}</p>
          </div>
          <div>
            <p class="text-gray-500 dark:text-gray-400">{{ isMonthMode ? t('statement_month_total') : t('statement_period_total') }}</p>
            <p class="mt-0.5 font-medium tabular-nums">{{ formatCurrency(statement.total) }}</p>
          </div>
          <div v-if="carriesBalance">
            <p class="text-gray-500 dark:text-gray-400">{{ t('statement_closing_balance') }}</p>
            <p class="mt-0.5 text-base font-bold tabular-nums">{{ formatCurrency(statement.closingBalance) }}</p>
          </div>
        </div>

        <DataTable :columns="columns" :rows="statement.lines">
          <template #cell-description="{ row }">
            <Link
              :href="`/accounting/journal-entries/${row.entry_id}`"
              class="transition hover:text-[hsl(var(--primary))]"
            >
              {{ row.description || '—' }}
            </Link>
          </template>

          <template #cell-counterAccounts="{ value }">
            <span class="text-gray-500 dark:text-gray-400">{{ value.join(', ') || '—' }}</span>
          </template>

          <template #empty>
            <EmptyState
              :icon="BookOpen"
              :title="t('statement_empty_title')"
              :description="isMonthMode ? t('statement_empty_desc') : t('statement_period_empty_desc')"
            />
          </template>
        </DataTable>
      </CardContent>
    </Card>
  </AppLayout>
</template>
