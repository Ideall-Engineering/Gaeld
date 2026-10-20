<script setup>
import { computed, ref, watch } from 'vue'
import Modal from '@/Components/UI/Modal.vue'
import Button from '@/Components/UI/Button.vue'
import FormInput from '@/Components/UI/FormInput.vue'
import FormSelect from '@/Components/UI/FormSelect.vue'
import { useTranslations } from '@/lib/useTranslations'

// Start a job from a quote or invoice form (spec 011). Gäld picks the next
// free code; the job comes back as an option of the project field.
const props = defineProps({
  open: Boolean,
  options: { type: Array, required: true },
  status: { type: String, default: 'in_progress' },
  customerId: { type: [String, Number], default: '' },
  suggestedName: { type: String, default: '' },
})

const emit = defineEmits(['close', 'created'])
const { t } = useTranslations()

const name = ref('')
const parentId = ref('')
const errors = ref({})
const formError = ref('')
const saving = ref(false)

watch(() => props.open, (open) => {
  if (!open) return
  name.value = props.suggestedName
  parentId.value = ''
  errors.value = {}
  formError.value = ''
})

const parentOptions = computed(() => [
  { value: '', label: t('none') },
  ...props.options
    .filter(p => p.is_active)
    .map(p => ({ value: String(p.id), label: `${'· '.repeat(p.depth)}${p.code} ${p.name}` })),
])

async function submit() {
  saving.value = true
  errors.value = {}
  formError.value = ''
  const headers = { 'Content-Type': 'application/json', Accept: 'application/json' }
  const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
  const xsrf = document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1]
  if (csrf) headers['X-CSRF-TOKEN'] = csrf
  if (xsrf) headers['X-XSRF-TOKEN'] = decodeURIComponent(xsrf)

  try {
    const res = await fetch('/projects', {
      method: 'POST',
      headers,
      credentials: 'same-origin',
      body: JSON.stringify({
        code: '',
        name: name.value,
        parent_id: parentId.value || null,
        kind: 'project',
        status: props.status,
        contact_id: props.customerId || null,
      }),
    })
    if (res.status === 422) {
      errors.value = (await res.json()).errors || {}
      return
    }
    const data = await res.json().catch(() => ({}))
    if (!res.ok || !data.project?.id) {
      formError.value = t('save_error')
      return
    }
    emit('created', data.project)
    emit('close')
  } catch {
    formError.value = t('save_error')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <Modal :open="open" :title="t('project_new_job')" @close="$emit('close')">
    <form class="space-y-4" @submit.prevent="submit">
      <FormInput id="qp-name" v-model="name" :label="t('name')" :error="errors.name?.[0]" required />
      <FormSelect id="qp-parent" v-model="parentId" :label="t('project_parent')" :options="parentOptions" :error="errors.parent_id?.[0]" />
      <p class="text-xs text-[hsl(var(--muted-foreground))]">{{ t('project_quick_create_hint') }}</p>
      <p v-if="formError || errors.code" class="text-xs text-[hsl(var(--destructive))]">{{ formError || errors.code?.[0] }}</p>
      <div class="flex justify-end gap-3 pt-2">
        <Button type="button" variant="outline" @click="$emit('close')">{{ t('cancel') }}</Button>
        <Button type="submit" :disabled="saving || !name" :loading="saving">{{ t('create') }}</Button>
      </div>
    </form>
  </Modal>
</template>
