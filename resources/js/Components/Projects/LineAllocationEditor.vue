<script setup>
import { computed, ref } from 'vue'
import Button from '@/Components/UI/Button.vue'
import FormInput from '@/Components/UI/FormInput.vue'
import FormSelect from '@/Components/UI/FormSelect.vue'
import { useTranslations } from '@/lib/useTranslations'
import { Plus, Split, X } from 'lucide-vue-next'

// Project and person allocation of one journal line (spec 009). A plain
// line goes to one project as a whole; "split" divides it in percentages
// across several projects and people. Undefined means the line was never
// touched, so the server keeps whatever it had.
const props = defineProps({
  modelValue: { type: Array, default: undefined },
  options: { type: Object, required: true },
  idPrefix: { type: String, default: 'alloc' },
  errors: { type: Object, default: () => ({}) },
  errorPrefix: { type: String, default: '' },
})

const emit = defineEmits(['update:modelValue'])

const { t } = useTranslations()

const allocations = computed(() => props.modelValue ?? [])
const isPlain = (list) => list.length === 0
  || (list.length === 1 && !list[0].employee_id && Number(list[0].share) === 100)
const splitMode = ref(!isPlain(allocations.value))

const usedProjectIds = computed(() => new Set(allocations.value.map(a => String(a.project_id))))

// Closed projects stay listed only where a line already uses them.
const projectOptions = computed(() => [
  { value: '', label: t('none') },
  ...props.options.projects
    .filter(p => p.is_active || usedProjectIds.value.has(String(p.id)))
    .map(p => ({
      value: String(p.id),
      label: `${'· '.repeat(p.depth)}${p.code} ${p.name}${p.is_active ? '' : ` (${t('inactive')})`}`,
    })),
])

const employeeOptions = computed(() => [
  { value: '', label: t('none') },
  ...props.options.employees.map(e => ({ value: String(e.id), label: e.name })),
])

const total = computed(() => allocations.value.reduce((sum, a) => sum + (parseFloat(a.share) || 0), 0))

const plainProject = computed(() => (allocations.value[0]?.project_id ? String(allocations.value[0].project_id) : ''))

function setPlainProject(value) {
  emit('update:modelValue', value ? [{ project_id: value, employee_id: '', share: '100' }] : [])
}

function update(index, field, value) {
  const next = allocations.value.map(a => ({ ...a }))
  next[index][field] = value
  emit('update:modelValue', next)
}

function addShare() {
  const rest = Math.max(0, +(100 - total.value).toFixed(4))
  emit('update:modelValue', [...allocations.value.map(a => ({ ...a })), { project_id: '', employee_id: '', share: rest > 0 ? String(rest) : '' }])
}

function removeShare(index) {
  emit('update:modelValue', allocations.value.filter((_, i) => i !== index))
}

function toggleSplit() {
  splitMode.value = !splitMode.value
  if (!splitMode.value) {
    const first = allocations.value.find(a => a.project_id)
    setPlainProject(first ? String(first.project_id) : '')
  } else if (allocations.value.length === 0) {
    addShare()
  }
}

const error = (index, field) => props.errors[`${props.errorPrefix}.${index}.${field}`]
</script>

<template>
  <div class="rounded-md bg-[hsl(var(--muted)/0.3)] p-2">
    <div v-if="!splitMode" class="flex items-end gap-2">
      <FormSelect
        :id="`${idPrefix}_project`"
        :model-value="plainProject"
        :label="t('project')"
        :options="projectOptions"
        :error="error(0, 'project_id') || errors[errorPrefix]"
        class="flex-1"
        @update:model-value="setPlainProject"
      />
      <Button type="button" size="sm" variant="ghost" class="mb-0.5" @click="toggleSplit">
        <Split class="mr-1 h-3 w-3" /> {{ t('allocation_split') }}
      </Button>
    </div>

    <div v-else class="space-y-2">
      <div v-for="(allocation, index) in allocations" :key="index" class="grid grid-cols-12 items-end gap-2">
        <FormSelect
          :id="`${idPrefix}_project_${index}`"
          :model-value="allocation.project_id ? String(allocation.project_id) : ''"
          :label="index === 0 ? t('project') : ''"
          :options="projectOptions"
          :error="error(index, 'project_id')"
          class="col-span-12 sm:col-span-5"
          @update:model-value="value => update(index, 'project_id', value)"
        />
        <FormSelect
          :id="`${idPrefix}_employee_${index}`"
          :model-value="allocation.employee_id ? String(allocation.employee_id) : ''"
          :label="index === 0 ? t('allocation_person') : ''"
          :options="employeeOptions"
          :error="error(index, 'employee_id')"
          class="col-span-7 sm:col-span-4"
          @update:model-value="value => update(index, 'employee_id', value)"
        />
        <FormInput
          :id="`${idPrefix}_share_${index}`"
          :model-value="allocation.share"
          type="number"
          step="0.01"
          min="0"
          max="100"
          :label="index === 0 ? t('allocation_share') : ''"
          :error="error(index, 'share')"
          class="col-span-4 sm:col-span-2"
          @update:model-value="value => update(index, 'share', value)"
        />
        <Button type="button" variant="ghost" size="icon" class="col-span-1 mb-0.5" :aria-label="t('delete')" @click="removeShare(index)">
          <X class="h-4 w-4" />
        </Button>
      </div>
      <div class="flex flex-wrap items-center justify-between gap-2 text-xs">
        <div class="flex gap-2">
          <Button type="button" size="sm" variant="outline" @click="addShare">
            <Plus class="mr-1 h-3 w-3" /> {{ t('allocation_add_share') }}
          </Button>
          <Button type="button" size="sm" variant="ghost" @click="toggleSplit">{{ t('allocation_unsplit') }}</Button>
        </div>
        <span :class="total > 100.0001 ? 'text-[hsl(var(--destructive))]' : 'text-[hsl(var(--muted-foreground))]'">
          {{ t('allocation_total', { total: total.toFixed(2) }) }}
        </span>
      </div>
      <div v-if="errors[errorPrefix]" class="text-xs text-[hsl(var(--destructive))]">{{ errors[errorPrefix] }}</div>
    </div>
  </div>
</template>

<script>
// Allocations as the server sends them (line.allocations) in form shape.
export function allocationsFromLine(line) {
  if (!Array.isArray(line?.allocations)) return undefined
  return line.allocations.map(a => ({
    project_id: a.cost_center_id ?? a.project_id ?? '',
    employee_id: a.employee_id ?? '',
    share: String(Number(a.share)),
  }))
}

// Allocations in the shape the server reads; undefined stays undefined.
export function serializeAllocations(allocations) {
  if (allocations === undefined) return undefined
  return allocations
    .filter(a => a.project_id || a.employee_id)
    .map(a => ({
      project_id: a.project_id ? Number(a.project_id) : null,
      employee_id: a.employee_id || null,
      share: a.share === '' || a.share === null ? '100' : String(a.share),
    }))
}
</script>
