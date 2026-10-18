<script setup>
import { computed, ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/AppLayout.vue'
import Card from '@/Components/UI/Card.vue'
import CardHeader from '@/Components/UI/CardHeader.vue'
import CardTitle from '@/Components/UI/CardTitle.vue'
import CardContent from '@/Components/UI/CardContent.vue'
import Button from '@/Components/UI/Button.vue'
import Badge from '@/Components/UI/Badge.vue'
import Breadcrumb from '@/Components/UI/Breadcrumb.vue'
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue'
import { useFormatters } from '@/lib/useFormatters'
import { useTranslations } from '@/lib/useTranslations'
import { Check, FileText, Pencil, Receipt, RotateCcw, Send, Trash2, X } from 'lucide-vue-next'

// A quote: its lines and totals, and the steps of its life. Sending is
// recorded here (the PDF goes out by mail as usual); the answer is recorded
// with accepted or declined, and an accepted quote becomes an invoice draft.
const props = defineProps({
  quote: { type: Object, required: true },
  can: { type: Object, default: () => ({}) },
})

const { t } = useTranslations()
const { formatCurrency, formatDate } = useFormatters()

const statusVariant = { draft: 'secondary', sent: 'default', accepted: 'success', declined: 'outline' }
const busy = ref(false)
const confirming = ref(null)

function setStatus(status) {
  busy.value = true
  router.post(`/quotes/${props.quote.id}/status`, { status }, { preserveScroll: true, onFinish: () => { busy.value = false } })
}
function convert() {
  busy.value = true
  router.post(`/quotes/${props.quote.id}/convert`, {}, { onFinish: () => { busy.value = false; confirming.value = null } })
}
function destroy() {
  busy.value = true
  router.delete(`/quotes/${props.quote.id}`, { onFinish: () => { busy.value = false; confirming.value = null } })
}

const s = computed(() => props.quote.status)
const customerName = computed(() => props.quote.customer_snapshot?.name ?? props.quote.customer?.name ?? '—')
const vatLabel = line => (line.vat_rate ? `${Number(line.vat_rate.rate)}%` : '—')
const lineTotal = (line) => {
  if (line.type === 'text') return null
  return line.type === 'discount' ? -Number(line.amount) : Number(line.amount)
}
</script>

<template>
  <AppLayout :title="`${t('quote')} ${quote.number}`">
    <Breadcrumb :items="[{ label: t('quotes'), href: '/quotes' }, { label: quote.number }]" class="mb-4" />

    <div class="max-w-5xl space-y-6">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <div class="mb-1 flex flex-wrap items-center gap-1.5">
            <Badge :variant="statusVariant[s] ?? 'secondary'">{{ t(`quote_status_${s}`) }}</Badge>
            <Badge v-if="quote.is_expired" variant="warning">{{ t('quote_expired') }}</Badge>
            <Badge v-if="quote.tax_treatment === 'reverse_charge'" variant="outline">{{ t('invoice_tax_treatment_reverse_charge') }}</Badge>
          </div>
          <p class="text-sm text-[hsl(var(--muted-foreground))]">
            {{ customerName }} · {{ t('issued') }} {{ formatDate(quote.issue_date) }}<template v-if="quote.valid_until"> · {{ t('quote_valid_until') }} {{ formatDate(quote.valid_until) }}</template>
          </p>
          <p v-if="quote.invoice" class="mt-1 text-sm">
            {{ t('quote_invoiced_as') }}
            <Link :href="`/invoices/${quote.invoice.id}`" class="font-medium text-[hsl(var(--primary))] hover:underline">{{ quote.invoice.number }}</Link>
          </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <Button v-if="can.update" as="a" :href="`/quotes/${quote.id}/edit`" variant="outline" size="sm"><Pencil class="mr-1 h-4 w-4" />{{ t('edit') }}</Button>
          <Button as="a" :href="`/quotes/${quote.id}/pdf`" target="_blank" variant="outline" size="sm"><FileText class="mr-1 h-4 w-4" />{{ t('quote_pdf') }}</Button>
          <template v-if="can.change_status">
            <Button v-if="s === 'draft'" size="sm" :disabled="busy" @click="setStatus('sent')"><Send class="mr-1 h-4 w-4" />{{ t('quote_mark_sent') }}</Button>
            <Button v-if="s === 'draft' || s === 'sent'" variant="outline" size="sm" :disabled="busy" @click="setStatus('accepted')"><Check class="mr-1 h-4 w-4" />{{ t('quote_mark_accepted') }}</Button>
            <Button v-if="s === 'draft' || s === 'sent'" variant="outline" size="sm" :disabled="busy" @click="setStatus('declined')"><X class="mr-1 h-4 w-4" />{{ t('quote_mark_declined') }}</Button>
            <Button v-if="s === 'sent' || s === 'declined'" variant="ghost" size="sm" :disabled="busy" @click="setStatus('draft')"><RotateCcw class="mr-1 h-4 w-4" />{{ t('quote_back_to_draft') }}</Button>
            <Button v-if="s === 'accepted'" variant="ghost" size="sm" :disabled="busy" @click="setStatus('sent')"><RotateCcw class="mr-1 h-4 w-4" />{{ t('quote_undo_decision') }}</Button>
          </template>
          <Button v-if="can.convert && (s === 'sent' || s === 'accepted')" size="sm" :disabled="busy" @click="confirming = 'convert'"><Receipt class="mr-1 h-4 w-4" />{{ t('quote_convert') }}</Button>
          <Button v-if="can.delete" variant="ghost" size="sm" :disabled="busy" :aria-label="t('delete')" :title="t('delete')" @click="confirming = 'delete'"><Trash2 class="h-4 w-4 text-[hsl(var(--destructive))]" /></Button>
        </div>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>{{ quote.title || `${t('quote')} ${quote.number}` }}</CardTitle>
          <p v-if="quote.title" class="text-sm text-[hsl(var(--muted-foreground))]">{{ quote.number }}</p>
        </CardHeader>
        <CardContent>
          <dl class="mb-6 grid grid-cols-1 gap-4 text-sm sm:grid-cols-4">
            <div><dt class="text-[hsl(var(--muted-foreground))]">{{ t('client') }}</dt><dd class="font-medium">{{ customerName }}</dd></div>
            <div><dt class="text-[hsl(var(--muted-foreground))]">{{ t('currency') }}</dt><dd class="font-medium">{{ quote.currency }}</dd></div>
            <div><dt class="text-[hsl(var(--muted-foreground))]">{{ t('payment_terms_days') }}</dt><dd class="font-medium">{{ quote.payment_terms || '—' }}</dd></div>
            <div v-if="quote.project"><dt class="text-[hsl(var(--muted-foreground))]">{{ t('project') }}</dt><dd class="font-medium">{{ quote.project.code }} {{ quote.project.name }}</dd></div>
          </dl>

          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead>
                <tr class="border-b text-left text-[hsl(var(--muted-foreground))]">
                  <th class="pb-2 font-medium">{{ t('description') }}</th>
                  <th class="pb-2 text-right font-medium">{{ t('qty') }}</th>
                  <th class="pb-2 text-right font-medium">{{ t('unit_price') }}</th>
                  <th class="pb-2 text-right font-medium">{{ t('vat') }}</th>
                  <th class="pb-2 text-right font-medium">{{ t('amount') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="line in quote.lines" :key="line.id" class="border-b border-[hsl(var(--border))] align-top">
                  <td class="py-2 whitespace-pre-line" :class="line.type === 'text' ? 'italic text-[hsl(var(--muted-foreground))]' : ''">{{ line.description }}</td>
                  <template v-if="line.type !== 'text'">
                    <td class="py-2 text-right tabular-nums">{{ line.type === 'discount' && line.discount_type === 'percentage' ? '—' : Number(line.quantity) }}</td>
                    <td class="py-2 text-right tabular-nums">{{ line.type === 'discount' && line.discount_type === 'percentage' ? `${Number(line.unit_price)}%` : formatCurrency(line.unit_price) }}</td>
                    <td class="py-2 text-right">{{ vatLabel(line) }}</td>
                    <td class="py-2 text-right tabular-nums">{{ formatCurrency(lineTotal(line)) }}</td>
                  </template>
                  <td v-else colspan="4" />
                </tr>
              </tbody>
              <tfoot class="tabular-nums">
                <tr><td colspan="4" class="pt-3 text-right">{{ t('subtotal') }}</td><td class="pt-3 text-right">{{ formatCurrency(quote.subtotal) }}</td></tr>
                <tr v-if="Number(quote.vat_amount) !== 0"><td colspan="4" class="text-right">{{ t('vat') }}</td><td class="text-right">{{ formatCurrency(quote.vat_amount) }}</td></tr>
                <tr class="font-semibold"><td colspan="4" class="pt-1 text-right">{{ t('total') }} {{ quote.currency }}</td><td class="pt-1 text-right">{{ formatCurrency(quote.total) }}</td></tr>
              </tfoot>
            </table>
          </div>

          <p v-if="quote.notes" class="mt-6 whitespace-pre-line text-sm text-[hsl(var(--muted-foreground))]">{{ quote.notes }}</p>
        </CardContent>
      </Card>
    </div>

    <ConfirmDialog
      :open="confirming === 'convert'"
      :title="t('quote_convert')"
      :message="t('quote_convert_confirm', { number: quote.number })"
      :confirm-label="t('quote_convert')"
      :processing="busy"
      @confirm="convert"
      @cancel="confirming = null"
    />
    <ConfirmDialog
      :open="confirming === 'delete'"
      :title="t('delete')"
      :message="t('quote_delete_confirm', { number: quote.number })"
      :confirm-label="t('delete')"
      :processing="busy"
      @confirm="destroy"
      @cancel="confirming = null"
    />
  </AppLayout>
</template>
