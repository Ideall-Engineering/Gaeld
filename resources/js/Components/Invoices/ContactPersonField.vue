<script setup>
import { computed, watch } from 'vue'
import FormSelect from '@/Components/UI/FormSelect.vue'
import { useTranslations } from '@/lib/useTranslations'

// Contact person of a quote or invoice (spec 011): only the persons of the
// chosen customer. When the customer changes, the primary person is taken.
// A draft without a choice still shows the primary person on the PDF.
const props = defineProps({
  modelValue: { type: String, default: '' },
  options: { type: Array, default: () => [] },
  customerId: { type: [String, Number], default: '' },
  error: { type: String, default: '' },
  id: { type: String, default: 'contact_person_id' },
})

const emit = defineEmits(['update:modelValue'])
const { t } = useTranslations()

const persons = computed(() => props.options.filter(p => String(p.contact_id) === String(props.customerId)))
// Without a choice the document addresses the primary (or only) person.
const fallback = computed(() => persons.value.find(p => p.is_primary) ?? (persons.value.length === 1 ? persons.value[0] : null))
const selectOptions = computed(() => [
  { value: '', label: fallback.value ? t('contact_person_default', { name: fallback.value.name }) : t('none') },
  ...persons.value.map(p => ({ value: p.id, label: p.department ? `${p.name} (${p.department})` : p.name })),
])

watch(() => props.customerId, (customerId, previous) => {
  if (previous === undefined && props.modelValue) return
  if (persons.value.some(p => p.id === props.modelValue)) return
  emit('update:modelValue', customerId && fallback.value ? fallback.value.id : '')
}, { immediate: true })
</script>

<template>
  <div v-if="customerId">
    <FormSelect
      :id="id"
      :model-value="modelValue ?? ''"
      :label="t('contact_person')"
      :options="selectOptions"
      :error="error"
      @update:model-value="emit('update:modelValue', $event)"
    />
    <p v-if="persons.length === 0" class="mt-1 text-xs text-[hsl(var(--muted-foreground))]">{{ t('contact_person_none_for_customer') }}</p>
  </div>
</template>
