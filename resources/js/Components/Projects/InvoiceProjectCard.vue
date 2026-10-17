<script setup>
import { computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Card from '@/Components/UI/Card.vue'
import CardHeader from '@/Components/UI/CardHeader.vue'
import CardTitle from '@/Components/UI/CardTitle.vue'
import CardDescription from '@/Components/UI/CardDescription.vue'
import CardContent from '@/Components/UI/CardContent.vue'
import Button from '@/Components/UI/Button.vue'
import InvoiceProjectField from '@/Components/Projects/InvoiceProjectField.vue'
import { useTranslations } from '@/lib/useTranslations'

// Project of a sent or archived invoice (spec 009). Changing it moves the
// allocation of the invoice's revenue lines; the invoice stays as it is.
const props = defineProps({
  invoiceId: { type: String, required: true },
  options: { type: Array, required: true },
  currentId: { type: Number, default: null },
  canChange: { type: Boolean, default: false },
})

const { t } = useTranslations()

const form = useForm({ project_id: props.currentId ? String(props.currentId) : '' })
const current = computed(() => props.options.find(p => p.id === props.currentId))

function save() {
  form.put(`/invoices/${props.invoiceId}/project`, { preserveScroll: true })
}
</script>

<template>
  <Card>
    <CardHeader>
      <CardTitle>{{ t('project') }}</CardTitle>
      <CardDescription>{{ t('invoice_project_hint') }}</CardDescription>
    </CardHeader>
    <CardContent>
      <form v-if="canChange" class="flex flex-wrap items-end gap-2" @submit.prevent="save">
        <InvoiceProjectField v-model="form.project_id" :options="options" :error="form.errors.project_id" class="min-w-[16rem] flex-1" />
        <Button type="submit" size="sm" :disabled="form.processing || !form.isDirty" :loading="form.processing">{{ t('save') }}</Button>
      </form>
      <p v-else class="text-sm">{{ current ? `${current.code} ${current.name}` : t('none') }}</p>
    </CardContent>
  </Card>
</template>
