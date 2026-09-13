<script setup>
import Badge from '@/Components/UI/Badge.vue'
import Tooltip from '@/Components/UI/Tooltip.vue'
import { useTranslations } from '@/lib/useTranslations'

const { t } = useTranslations()

defineProps({
  account: { type: Object, default: null },
  isSplit: { type: Boolean, default: false },
  // Spelled out, the account name stands next to its number instead of hiding
  // in a tooltip. Only the views that trade columns for width can afford it.
  showName: { type: Boolean, default: false },
})
</script>

<template>
  <span v-if="account && showName" class="inline-flex items-baseline gap-2">
    <span class="font-mono">{{ account.code }}</span>
    <span class="break-words">{{ account.name }}</span>
  </span>
  <span v-else-if="account" class="whitespace-nowrap">
    <Tooltip :content="account.name" side="top">
      <span class="font-mono">{{ account.code }}</span>
    </Tooltip>
  </span>
  <Badge v-else-if="isSplit" variant="secondary">{{ t('journal_multiple_accounts') }}</Badge>
  <span v-else class="text-[hsl(var(--muted-foreground))]">—</span>
</template>
