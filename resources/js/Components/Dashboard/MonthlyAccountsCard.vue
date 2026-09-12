<script setup>
import { computed, ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import Card from '@/Components/UI/Card.vue'
import CardHeader from '@/Components/UI/CardHeader.vue'
import CardTitle from '@/Components/UI/CardTitle.vue'
import CardDescription from '@/Components/UI/CardDescription.vue'
import CardContent from '@/Components/UI/CardContent.vue'
import { ChevronLeft, ChevronRight } from 'lucide-vue-next'
import { useFormatters } from '@/lib/useFormatters'
import { useTranslations } from '@/lib/useTranslations'

const props = defineProps({
  monthlyAccounts: { type: Object, default: () => ({ revenue: {}, expenses: {} }) },
  year: { type: Number, required: true },
  initialMonth: { type: Number, default: () => new Date().getMonth() + 1 },
})

const { t } = useTranslations()
const { formatCurrency, intlMonthName } = useFormatters()

/**
 * Coming back from a statement, the card opens on the month that was being
 * read rather than on the default. Only for this card's year: the page ships
 * one year at a time, so a month from another one would look empty instead of
 * saying so.
 */
function monthFromUrl() {
  if (typeof window === 'undefined') return null

  const raw = new URLSearchParams(window.location.search).get('month')
  const match = /^(\d{4})-(\d{2})$/.exec(raw ?? '')

  if (!match || Number(match[1]) !== props.year) return null

  const value = Number(match[2])

  return value >= 1 && value <= 12 ? value : null
}

const month = ref(monthFromUrl() ?? Math.min(Math.max(props.initialMonth, 1), 12))

function asNumber(value) {
  const parsed = Number(value)
  return Number.isFinite(parsed) ? parsed : 0
}

// The whole year arrives with the page, so paging through months costs no
// request. Only the year is fixed, by the dashboard around us.
function rowsFor(kind) {
  return props.monthlyAccounts?.[kind]?.[String(month.value)] ?? []
}

const sections = computed(() => [
  { key: 'expenses', label: t('monthly_accounts_expenses'), rows: rowsFor('expenses') },
  { key: 'revenue', label: t('monthly_accounts_revenue'), rows: rowsFor('revenue') },
].map(section => ({
  ...section,
  total: section.rows.reduce((sum, row) => sum + asNumber(row.amount), 0),
})))

const isEmpty = computed(() => sections.value.every(section => section.rows.length === 0))

const monthLabel = computed(() => `${intlMonthName(month.value - 1)} ${props.year}`)

function statementHref(row) {
  const paddedMonth = String(month.value).padStart(2, '0')

  return `/accounting/accounts/${row.uuid}/statement?month=${props.year}-${paddedMonth}`
}
</script>

<template>
  <Card id="monthly-accounts">
    <CardHeader>
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <CardTitle>{{ t('monthly_accounts_title') }}</CardTitle>
          <CardDescription>{{ t('monthly_accounts_desc') }}</CardDescription>
        </div>

        <div class="flex items-center gap-1">
          <button
            type="button"
            class="rounded-md p-1.5 text-gray-500 transition hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-40 dark:text-gray-400 dark:hover:bg-gray-800"
            :disabled="month <= 1"
            :aria-label="t('previous_month')"
            @click="month = Math.max(1, month - 1)"
          >
            <ChevronLeft class="h-4 w-4" />
          </button>
          <span class="min-w-[9rem] text-center text-sm font-medium tabular-nums">{{ monthLabel }}</span>
          <button
            type="button"
            class="rounded-md p-1.5 text-gray-500 transition hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-40 dark:text-gray-400 dark:hover:bg-gray-800"
            :disabled="month >= 12"
            :aria-label="t('next_month')"
            @click="month = Math.min(12, month + 1)"
          >
            <ChevronRight class="h-4 w-4" />
          </button>
        </div>
      </div>
    </CardHeader>

    <CardContent>
      <p v-if="isEmpty" class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">
        {{ t('monthly_accounts_empty') }}
      </p>

      <div v-else class="grid gap-6 md:grid-cols-2">
        <div v-for="section in sections" :key="section.key">
          <div class="flex items-baseline justify-between border-b border-gray-200 pb-2 dark:border-gray-700">
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">{{ section.label }}</h3>
            <span class="text-sm font-bold tabular-nums">{{ formatCurrency(section.total) }}</span>
          </div>

          <p v-if="!section.rows.length" class="py-3 text-sm text-gray-500 dark:text-gray-400">
            {{ t('monthly_accounts_none_in_month') }}
          </p>

          <ul v-else class="divide-y divide-gray-100 dark:divide-gray-800">
            <li v-for="row in section.rows" :key="row.uuid">
              <Link
                :href="statementHref(row)"
                class="flex items-baseline justify-between gap-3 py-2 text-sm transition hover:text-[hsl(var(--primary))]"
              >
                <span class="truncate">
                  <span class="text-gray-400 tabular-nums dark:text-gray-500">{{ row.code }}</span>
                  <span class="ml-2 text-gray-700 dark:text-gray-200">{{ row.name }}</span>
                </span>
                <span class="shrink-0 font-medium tabular-nums">{{ formatCurrency(row.amount) }}</span>
              </Link>
            </li>
          </ul>
        </div>
      </div>
    </CardContent>
  </Card>
</template>
