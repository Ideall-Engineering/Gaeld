<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import AppLayout from '@/Components/AppLayout.vue'
import Card from '@/Components/UI/Card.vue'
import CardHeader from '@/Components/UI/CardHeader.vue'
import CardTitle from '@/Components/UI/CardTitle.vue'
import CardContent from '@/Components/UI/CardContent.vue'
import Button from '@/Components/UI/Button.vue'
import FormInput from '@/Components/UI/FormInput.vue'
import FormTextarea from '@/Components/UI/FormTextarea.vue'
import FormSelect from '@/Components/UI/FormSelect.vue'
import SearchableSelect from '@/Components/UI/SearchableSelect.vue'
import Breadcrumb from '@/Components/UI/Breadcrumb.vue'
import QuickCreateContactModal from '@/Components/QuickCreateContactModal.vue'
import InvoiceLineItems from '@/Components/Invoices/InvoiceLineItems.vue'
import InvoiceProjectField from '@/Components/Projects/InvoiceProjectField.vue'
import ContactPersonField from '@/Components/Invoices/ContactPersonField.vue'
import { useUnsavedChanges } from '@/lib/useUnsavedChanges'
import UnsavedChangesDialog from '@/Components/UI/UnsavedChangesDialog.vue'
import { currencyOptions } from '@/lib/contactOptions'
import { useTranslations } from '@/lib/useTranslations'
import { Plus } from 'lucide-vue-next'

// Create or change a quote. The lines are the invoice lines, so an accepted
// quote becomes an invoice with the same positions and totals.
const props = defineProps({
  quote: { type: Object, default: null },
  customers: { type: Array, default: () => [] },
  vatRates: { type: Array, default: () => [] },
  defaultVatRateId: { type: [String, Number], default: null },
  taxTreatments: { type: Array, default: () => [] },
  projectOptions: { type: Array, default: null },
  contactPersons: { type: Array, default: () => [] },
  canCreateProject: { type: Boolean, default: false },
  suggestedNumber: { type: String, default: '' },
  defaultNotes: { type: String, default: '' },
  defaultValidDays: { type: Number, default: 30 },
})

const { t } = useTranslations()

const today = new Date().toISOString().slice(0, 10)
function plusDays(date, days) {
  if (!date) return ''
  const d = new Date(date)
  d.setDate(d.getDate() + Number(days))
  return d.toISOString().slice(0, 10)
}
const emptyLine = () => ({ type: 'item', discount_type: 'flat', description: '', quantity: 1, unit: '', unit_price: 0, vat_rate_id: props.defaultVatRateId ? String(props.defaultVatRateId) : '' })

const q = props.quote
const form = useForm({
  customer_id: q?.customer_id != null ? String(q.customer_id) : '',
  contact_person_id: q?.contact_person_id ?? '',
  delivery_note: q?.delivery_note ?? '',
  intro_text: q?.intro_text ?? '',
  title: q?.title ?? '',
  issue_date: q?.issue_date?.slice(0, 10) ?? today,
  valid_until: q ? (q.valid_until?.slice(0, 10) ?? '') : plusDays(today, props.defaultValidDays),
  currency: q?.currency ?? 'CHF',
  tax_treatment: q?.tax_treatment ?? 'standard',
  notes: q?.notes ?? props.defaultNotes,
  payment_terms: q?.payment_terms ?? '',
  project_id: q?.cost_center_id ? String(q.cost_center_id) : '',
  lines: q?.lines?.length
    ? q.lines.map(l => ({ type: l.type ?? 'item', discount_type: l.discount_type ?? 'flat', description: l.description, quantity: l.quantity, unit: l.unit ?? '', unit_price: l.unit_price, vat_rate_id: l.vat_rate_id ? String(l.vat_rate_id) : '' }))
    : [emptyLine()],
})

// The validity follows the date until it is set by hand.
const validTouched = ref(!!q)
watch(() => form.issue_date, (date) => {
  if (!validTouched.value) form.valid_until = plusDays(date, props.defaultValidDays)
})

const customerList = reactive([...props.customers])
const clientOptions = computed(() => customerList.map(c => ({ value: String(c.id), label: c.name })))
const showCreateCustomer = ref(false)
function onCustomerCreated(customer) {
  customerList.push(customer)
  form.customer_id = String(customer.id)
}

function save() {
  return new Promise((resolve) => {
    const options = { preserveScroll: true, onFinish: () => resolve() }
    if (props.quote) form.put(`/quotes/${props.quote.id}`, options)
    else form.post('/quotes', options)
  })
}
const { showDialog, handleSave, handleDiscard, handleStay, forceClear } = useUnsavedChanges(
  computed(() => form.isDirty),
  { onSave: save, fallbackUrl: props.quote ? `/quotes/${props.quote.id}` : '/quotes' },
)
function submit() {
  forceClear.value = true
  save().then(() => { if (Object.keys(form.errors).length) forceClear.value = false })
}

const title = computed(() => (props.quote ? t('quote_edit') : t('quote_new')))
</script>

<template>
  <AppLayout :title="title">
    <Breadcrumb :items="[{ label: t('quotes'), href: '/quotes' }, ...(quote ? [{ label: quote.number, href: `/quotes/${quote.id}` }] : []), { label: title }]" class="mb-4" />

    <Card class="max-w-6xl overflow-hidden">
      <CardHeader class="border-b bg-[hsl(var(--muted)/0.18)]">
        <CardTitle>{{ title }}</CardTitle>
      </CardHeader>
      <CardContent class="space-y-8 p-4 sm:p-6 lg:p-8">
        <form class="space-y-8" @submit.prevent="submit">
          <section class="space-y-4">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
              <div class="lg:col-span-2">
                <div class="flex items-end gap-2">
                  <SearchableSelect
                    id="customer_id"
                    v-model="form.customer_id"
                    :label="t('client')"
                    :options="clientOptions"
                    :placeholder="t('select_client')"
                    :error="form.errors.customer_id"
                    class="flex-1"
                  />
                  <Button type="button" variant="outline" size="icon" class="mb-[2px] shrink-0" :title="t('new_customer')" @click="showCreateCustomer = true">
                    <Plus class="h-4 w-4" />
                  </Button>
                </div>
              </div>
              <div v-if="projectOptions" class="lg:col-span-2">
                <InvoiceProjectField
                  v-model="form.project_id"
                  :options="projectOptions"
                  :customer-id="form.customer_id"
                  :follow-customer="!quote"
                  :error="form.errors.project_id"
                  :can-create="canCreateProject"
                  create-status="pipeline"
                  :suggested-name="form.title"
                />
              </div>
              <ContactPersonField
                v-model="form.contact_person_id"
                :options="contactPersons"
                :customer-id="form.customer_id"
                :error="form.errors.contact_person_id"
                class="lg:col-span-2"
              />
              <div class="lg:col-span-2">
                <label class="text-sm font-medium leading-none">{{ t('quote_number') }}</label>
                <div class="mt-2 flex h-11 w-full items-center rounded-md border border-[hsl(var(--input))] bg-[hsl(var(--muted))] px-3 py-1 text-base text-[hsl(var(--muted-foreground))] select-none sm:h-9 sm:text-sm">
                  {{ suggestedNumber || '…' }}
                </div>
              </div>
              <FormInput id="title" v-model="form.title" :label="t('quote_title')" :placeholder="t('quote_title_placeholder')" :error="form.errors.title" class="lg:col-span-2" />
              <FormInput id="issue_date" v-model="form.issue_date" type="date" :label="t('issue_date')" :error="form.errors.issue_date" required />
              <FormInput id="valid_until" v-model="form.valid_until" type="date" :label="t('quote_valid_until')" :error="form.errors.valid_until" @change="validTouched = true" />
              <FormSelect id="currency" v-model="form.currency" :label="t('currency')" :options="currencyOptions(t)" :error="form.errors.currency" />
              <FormSelect id="tax_treatment" v-model="form.tax_treatment" :label="t('invoice_tax_treatment')" :options="taxTreatments" :error="form.errors.tax_treatment" />
              <FormInput id="delivery_note" v-model="form.delivery_note" :label="t('document_delivery_note')" :placeholder="t('document_delivery_note_placeholder')" :error="form.errors.delivery_note" maxlength="255" class="lg:col-span-2" />
              <FormTextarea id="intro_text" v-model="form.intro_text" :label="t('document_intro_text')" :placeholder="t('quote_intro_text_placeholder')" :error="form.errors.intro_text" :rows="3" class="sm:col-span-2 lg:col-span-4" />
            </div>
          </section>

          <InvoiceLineItems
            v-model="form.lines"
            :vat-rates="vatRates"
            :errors="form.errors"
            :currency="form.currency"
            :default-vat-rate-id="defaultVatRateId"
            :tax-treatment="form.tax_treatment"
          />

          <section class="space-y-4">
            <h3 class="text-base font-semibold">{{ t('notes_and_terms') }}</h3>
            <div class="grid grid-cols-1 gap-6 sm:grid-cols-[minmax(0,1.5fr)_minmax(16rem,1fr)] sm:items-start">
              <FormTextarea id="notes" v-model="form.notes" :label="t('notes')" :error="form.errors.notes" />
              <div>
                <FormInput id="payment_terms" v-model="form.payment_terms" type="number" min="0" :label="t('payment_terms_days')" placeholder="30" :error="form.errors.payment_terms" />
                <p class="mt-1 text-xs text-[hsl(var(--muted-foreground))]">{{ t('quote_payment_terms_hint') }}</p>
              </div>
            </div>
          </section>

          <div class="flex flex-col-reverse gap-3 border-t pt-6 sm:flex-row sm:items-center sm:justify-end">
            <Button as="a" :href="quote ? `/quotes/${quote.id}` : '/quotes'" variant="outline" class="w-full sm:w-auto">{{ t('cancel') }}</Button>
            <Button type="submit" class="w-full sm:w-auto" :disabled="form.processing" :loading="form.processing">{{ t('save') }}</Button>
          </div>
        </form>
      </CardContent>
    </Card>

    <QuickCreateContactModal :open="showCreateCustomer" contact-type="customer" @close="showCreateCustomer = false" @created="onCustomerCreated" />
    <UnsavedChangesDialog :open="showDialog" :saving="form.processing" @save="handleSave" @discard="handleDiscard" @stay="handleStay" />
  </AppLayout>
</template>
