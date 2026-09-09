<script setup>
import AppLayout from '@/Components/AppLayout.vue'
import Card from '@/Components/UI/Card.vue'
import CardHeader from '@/Components/UI/CardHeader.vue'
import CardTitle from '@/Components/UI/CardTitle.vue'
import CardContent from '@/Components/UI/CardContent.vue'
import Badge from '@/Components/UI/Badge.vue'
import Button from '@/Components/UI/Button.vue'
import DataTable from '@/Components/UI/DataTable.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'
import { useTranslations } from '@/lib/useTranslations'
import { computed, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { Play, Cog, AlertTriangle } from 'lucide-vue-next'

const props = defineProps({
  automations: Array,
  runs: Array,
  canConfigure: { type: Boolean, default: false },
})

const { t } = useTranslations()

const statusVariant = {
  running: 'info',
  succeeded: 'success',
  failed: 'destructive',
  skipped: 'secondary',
}

const runColumns = computed(() => [
  { key: 'automation', label: t('automation') },
  { key: 'status', label: t('status') },
  { key: 'message', label: t('bank_rule_reason'), sortable: false },
  { key: 'event_key', label: t('reference') },
  { key: 'finished_at', label: t('date') },
])

const busy = ref(null)

function toggle(automation) {
  busy.value = automation.key
  useForm({
    automation: automation.key,
    is_enabled: !automation.is_enabled,
  }).put('/automation/settings', {
    preserveScroll: true,
    onFinish: () => { busy.value = null },
  })
}

function runNow(automation) {
  busy.value = automation.key
  useForm({ automation: automation.key }).post('/automation/run', {
    preserveScroll: true,
    onFinish: () => { busy.value = null },
  })
}

function findingsOf(run) {
  const findings = run.summary?.findings
  return Array.isArray(findings) ? findings : []
}
</script>

<template>
  <AppLayout :title="t('automation')">
    <div class="space-y-6">
      <Card>
        <CardHeader>
          <CardTitle>{{ t('automation') }}</CardTitle>
        </CardHeader>
        <CardContent>
          <p class="mb-4 text-sm text-[hsl(var(--muted-foreground))]">{{ t('automation_desc') }}</p>

          <div class="space-y-3">
            <div
              v-for="automation in automations"
              :key="automation.key"
              class="flex flex-wrap items-start justify-between gap-3 rounded-md border border-[hsl(var(--border))] px-4 py-3"
            >
              <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                  <span class="font-medium">{{ automation.label }}</span>
                  <Badge :variant="automation.writes ? 'warning' : 'secondary'">
                    {{ automation.writes ? t('automation_writes_badge') : t('automation_reports_badge') }}
                  </Badge>
                </div>
                <p class="mt-1 text-sm text-[hsl(var(--muted-foreground))]">{{ automation.description }}</p>
              </div>

              <div class="flex shrink-0 items-center gap-2">
                <Button
                  variant="ghost"
                  size="icon"
                  :title="t('automation_run_now')"
                  :disabled="busy === automation.key"
                  @click="runNow(automation)"
                >
                  <Play class="h-4 w-4" />
                </Button>
                <label class="flex items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    class="rounded border-[hsl(var(--border))]"
                    :checked="automation.is_enabled"
                    :disabled="!canConfigure || busy === automation.key"
                    @change="toggle(automation)"
                  />
                  {{ t('active') }}
                </label>
              </div>
            </div>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>{{ t('automation_runs') }}</CardTitle>
        </CardHeader>
        <CardContent>
          <DataTable :columns="runColumns" :rows="runs">
            <template #empty>
              <EmptyState
                :icon="Cog"
                :title="t('automation_no_runs')"
                :description="t('automation_no_runs_desc')"
              />
            </template>

            <template #cell-automation="{ value }">
              <span class="text-sm">{{ t(`automation_${value}`) }}</span>
            </template>

            <template #cell-status="{ value }">
              <Badge :variant="statusVariant[value] || 'default'">
                {{ t(`automation_status_${value}`) }}
              </Badge>
            </template>

            <template #cell-message="{ row }">
              <div class="min-w-0">
                <p class="text-sm">{{ row.message || '—' }}</p>
                <details v-if="findingsOf(row).length" class="mt-1">
                  <summary class="cursor-pointer text-xs text-[hsl(var(--muted-foreground))]">
                    <AlertTriangle class="mr-1 inline h-3 w-3" />
                    {{ t('automation_findings') }} ({{ findingsOf(row).length }})
                  </summary>
                  <ul class="mt-1 space-y-0.5 text-xs text-[hsl(var(--muted-foreground))]">
                    <li v-for="(finding, i) in findingsOf(row)" :key="i">{{ finding }}</li>
                  </ul>
                </details>
              </div>
            </template>

            <template #cell-event_key="{ value }">
              <span class="font-mono text-xs text-[hsl(var(--muted-foreground))]">{{ value }}</span>
            </template>
          </DataTable>
        </CardContent>
      </Card>
    </div>
  </AppLayout>
</template>
