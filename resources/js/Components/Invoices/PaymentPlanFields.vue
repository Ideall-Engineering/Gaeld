<script setup>
import { ref, computed } from 'vue'
import Button from '@/Components/UI/Button.vue'
import { useTranslations } from '@/lib/useTranslations'
import { useFormatters } from '@/lib/useFormatters'

// Spec 013: the rates of a payment plan. The last rate always carries the
// rest of the total and cannot be typed in; Gäld sets it when saving.
const rates = defineModel({ type: Array, default: () => [] })
const enabled = defineModel('enabled', { type: Boolean, default: false })

const props = defineProps({
  total: { type: Number, default: 0 },
  issueDate: { type: String, default: '' },
  currency: { type: String, default: 'CHF' },
  errors: { type: Object, default: () => ({}) },
})

const { t } = useTranslations()
const { formatCurrency } = useFormatters()

const count = ref(Math.max(rates.value.length, 3))
const intervalDays = ref(30)

const rest = computed(() => {
  const others = rates.value.slice(0, -1).reduce((sum, rate) => sum + (parseFloat(rate.amount) || 0), 0)
  return Math.round((props.total - others) * 100) / 100
})

function addDays(date, days) {
  const d = new Date(`${date}T00:00:00Z`)
  d.setUTCDate(d.getUTCDate() + days)
  return d.toISOString().slice(0, 10)
}

// Equal rates rounded down to 5 centimes; the last one takes what is left.
function splitEvenly() {
  const n = Math.min(12, Math.max(2, parseInt(count.value) || 2))
  const days = Math.max(1, parseInt(intervalDays.value) || 30)
  const share = Math.floor(props.total / n / 0.05) * 0.05
  const start = props.issueDate || new Date().toISOString().slice(0, 10)
  rates.value = Array.from({ length: n }, (_, i) => ({
    due_date: addDays(start, days * (i + 1)),
    amount: i < n - 1 ? share.toFixed(2) : '',
  }))
}

function toggle(event) {
  // The model follows the parent only after this tick; read the box itself.
  const on = event.target.checked
  enabled.value = on
  if (on && rates.value.length === 0) splitEvenly()
}

function rateError(index, field) {
  return props.errors[`installments.${index}.${field}`]
}
</script>

<template>
  <section class="space-y-3" aria-labelledby="payment-plan-heading">
    <label class="flex items-center gap-2 text-sm font-medium text-[hsl(var(--foreground))]">
      <input
        id="payment_plan"
        type="checkbox"
        class="h-4 w-4 rounded border-[hsl(var(--input))]"
        :checked="enabled"
        @change="toggle"
      />
      <span id="payment-plan-heading">{{ t('payment_plan') }}</span>
    </label>
    <p class="text-xs text-[hsl(var(--muted-foreground))]">{{ t('payment_plan_hint') }}</p>

    <template v-if="enabled">
      <div class="flex flex-wrap items-end gap-3">
        <label class="space-y-1 text-sm">
          <span class="block text-[hsl(var(--muted-foreground))]">{{ t('payment_plan_rate_count') }}</span>
          <input v-model="count" type="number" min="2" max="12" class="h-9 w-20 rounded-md border border-[hsl(var(--input))] bg-[hsl(var(--background))] px-2 tabular-nums" />
        </label>
        <label class="space-y-1 text-sm">
          <span class="block text-[hsl(var(--muted-foreground))]">{{ t('payment_plan_interval_days') }}</span>
          <input v-model="intervalDays" type="number" min="1" class="h-9 w-20 rounded-md border border-[hsl(var(--input))] bg-[hsl(var(--background))] px-2 tabular-nums" />
        </label>
        <Button type="button" variant="outline" size="sm" @click="splitEvenly">{{ t('payment_plan_split_evenly') }}</Button>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left text-xs text-[hsl(var(--muted-foreground))]">
              <th class="py-1 pr-3 font-medium">{{ t('installment') }}</th>
              <th class="py-1 pr-3 font-medium">{{ t('due_date') }}</th>
              <th class="py-1 text-right font-medium">{{ t('amount') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(rate, index) in rates" :key="index" class="align-top">
              <td class="py-1 pr-3 tabular-nums">{{ index + 1 }}/{{ rates.length }}</td>
              <td class="py-1 pr-3">
                <input
                  v-model="rate.due_date"
                  type="date"
                  :aria-label="`${t('due_date')} ${index + 1}`"
                  class="h-9 w-36 rounded-md border border-[hsl(var(--input))] bg-[hsl(var(--background))] px-2 sm:w-auto"
                />
                <p v-if="rateError(index, 'due_date')" class="mt-1 text-xs text-[hsl(var(--destructive))]">{{ rateError(index, 'due_date') }}</p>
              </td>
              <td class="py-1 text-right">
                <template v-if="index < rates.length - 1">
                  <input
                    v-model="rate.amount"
                    type="number"
                    step="0.01"
                    min="0.01"
                    :aria-label="`${t('amount')} ${index + 1}`"
                    class="h-9 w-24 sm:w-32 rounded-md border border-[hsl(var(--input))] bg-[hsl(var(--background))] px-2 text-right tabular-nums"
                  />
                  <p v-if="rateError(index, 'amount')" class="mt-1 text-xs text-[hsl(var(--destructive))]">{{ rateError(index, 'amount') }}</p>
                </template>
                <span v-else class="inline-block py-2 tabular-nums" :class="rest <= 0 ? 'text-[hsl(var(--destructive))]' : ''">
                  {{ formatCurrency(rest, currency) }}
                  <span class="block text-xs text-[hsl(var(--muted-foreground))]">{{ t('payment_plan_rest') }}</span>
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p v-if="errors.installments" class="text-sm text-[hsl(var(--destructive))]">{{ errors.installments }}</p>
    </template>
  </section>
</template>
