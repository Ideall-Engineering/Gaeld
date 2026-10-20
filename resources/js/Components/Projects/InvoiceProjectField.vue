<script setup>
import { computed, ref, watch } from 'vue'
import FormSelect from '@/Components/UI/FormSelect.vue'
import Button from '@/Components/UI/Button.vue'
import QuickCreateProjectModal from '@/Components/Projects/QuickCreateProjectModal.vue'
import { useTranslations } from '@/lib/useTranslations'
import { Plus } from 'lucide-vue-next'

// Project of an invoice (spec 009). With `followCustomer` the field takes
// the customer's one open project whenever the customer changes, until the
// user picks a project by hand. With `canCreate` a new job can be started
// right here (spec 011).
const props = defineProps({
  modelValue: { type: [String, Number], default: '' },
  options: { type: Array, required: true },
  customerId: { type: [String, Number], default: '' },
  followCustomer: { type: Boolean, default: false },
  error: { type: String, default: '' },
  id: { type: String, default: 'project_id' },
  canCreate: { type: Boolean, default: false },
  // Status of a job started here: a quote starts an open one.
  createStatus: { type: String, default: 'in_progress' },
  suggestedName: { type: String, default: '' },
})

const emit = defineEmits(['update:modelValue'])
const { t } = useTranslations()

const chosenByHand = ref(false)
const created = ref([])
const showCreate = ref(false)
const current = computed(() => (props.modelValue ? String(props.modelValue) : ''))
const allOptions = computed(() => [...props.options, ...created.value])

const selectOptions = computed(() => [
  { value: '', label: t('none') },
  ...allOptions.value
    .filter(p => p.is_active || String(p.id) === current.value)
    .map(p => ({ value: String(p.id), label: `${'· '.repeat(p.depth)}${p.code} ${p.name}${p.is_active ? '' : ` (${t('inactive')})`}` })),
])

function choose(value) {
  chosenByHand.value = true
  emit('update:modelValue', value)
}

function onCreated(project) {
  created.value.push(project)
  choose(String(project.id))
}

watch(() => props.customerId, (customerId) => {
  if (!props.followCustomer || chosenByHand.value || !customerId) return
  const open = allOptions.value.filter(p => p.is_open && String(p.contact_id) === String(customerId))
  emit('update:modelValue', open.length === 1 ? String(open[0].id) : '')
})
</script>

<template>
  <div class="flex items-end gap-2">
    <FormSelect
      :id="id"
      :model-value="current"
      :label="t('project')"
      :options="selectOptions"
      :error="error"
      class="flex-1"
      @update:model-value="choose"
    />
    <Button v-if="canCreate" type="button" variant="outline" size="icon" class="mb-[2px] shrink-0" :title="t('project_new_job')" @click="showCreate = true">
      <Plus class="h-4 w-4" />
    </Button>
    <QuickCreateProjectModal
      v-if="canCreate"
      :open="showCreate"
      :options="allOptions"
      :status="createStatus"
      :customer-id="customerId"
      :suggested-name="suggestedName"
      @close="showCreate = false"
      @created="onCreated"
    />
  </div>
</template>
