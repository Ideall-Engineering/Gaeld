<script setup>
import { computed, ref, watch } from 'vue'
import FormSelect from '@/Components/UI/FormSelect.vue'
import { useTranslations } from '@/lib/useTranslations'

// Project of an invoice (spec 009). With `followCustomer` the field takes
// the customer's one open project whenever the customer changes, until the
// user picks a project by hand.
const props = defineProps({
  modelValue: { type: [String, Number], default: '' },
  options: { type: Array, required: true },
  customerId: { type: [String, Number], default: '' },
  followCustomer: { type: Boolean, default: false },
  error: { type: String, default: '' },
  id: { type: String, default: 'project_id' },
})

const emit = defineEmits(['update:modelValue'])
const { t } = useTranslations()

const chosenByHand = ref(false)
const current = computed(() => (props.modelValue ? String(props.modelValue) : ''))

const selectOptions = computed(() => [
  { value: '', label: t('none') },
  ...props.options
    .filter(p => p.is_active || String(p.id) === current.value)
    .map(p => ({ value: String(p.id), label: `${'· '.repeat(p.depth)}${p.code} ${p.name}${p.is_active ? '' : ` (${t('inactive')})`}` })),
])

function choose(value) {
  chosenByHand.value = true
  emit('update:modelValue', value)
}

watch(() => props.customerId, (customerId) => {
  if (!props.followCustomer || chosenByHand.value || !customerId) return
  const open = props.options.filter(p => p.is_open && String(p.contact_id) === String(customerId))
  emit('update:modelValue', open.length === 1 ? String(open[0].id) : '')
})
</script>

<template>
  <FormSelect
    :id="id"
    :model-value="current"
    :label="t('project')"
    :options="selectOptions"
    :error="error"
    @update:model-value="choose"
  />
</template>
