<script setup>
import { computed, ref } from 'vue';
import { Head } from '@statamic/cms/inertia';
import {
    Header, Badge, Listing, EmptyStateMenu, EmptyStateItem, DocsCallout,
    CommandPaletteItem, DropdownItem, Stack,
} from '@statamic/cms/ui';

/**
 * Cancellations declared without a login — § 312k BGB.
 *
 * Built as the twin of the withdrawals screen. The one column that matters
 * most is whether the agreement was actually stopped at the provider: a row
 * that says "matched, not cancelled" is the one somebody has to pick up today.
 */
const props = defineProps({
    listingUrl: { type: String, required: true },
    actionUrl: { type: String, required: true },
    paymentsUrl: { type: String, default: '' },
    subscriptionsUrl: { type: String, required: true },
    filters: { type: Array, default: () => [] },
    sortColumn: { type: String, default: 'confirmed_at' },
    sortDirection: { type: String, default: 'desc' },
    hasAny: { type: Boolean, default: false },
    t: { type: Object, required: true },
});

const detail = ref(null);

const detailOpen = computed({
    get: () => detail.value !== null,
    set: (value) => { if (! value) detail.value = null; },
});

const subscriptionLink = (subscription) => `${props.subscriptionsUrl}?search=${encodeURIComponent(subscription.provider_id)}`;

const detailFields = computed(() => {
    const row = detail.value;
    const t = props.t;

    if (! row) return [];

    return [
        { label: t.field_name, value: row.name },
        { label: t.field_email, value: row.email },
        { label: t.field_identification, value: row.identification },
        { label: t.field_kind, value: row.kind_label },
        { label: t.field_reason, value: row.reason },
        { label: t.field_effective_at, value: row.effective_at || t.effective_earliest },
        { label: t.field_declared_at, value: row.declared_at, date: true },
        { label: t.field_confirmed_at, value: row.confirmed_at, date: true },
        { label: t.field_receipt_sent_at, value: row.receipt_sent_at, date: true },
        { label: t.field_merchant_notified_at, value: row.merchant_notified_at, date: true },
        { label: t.field_provider_cancelled_at, value: row.provider_cancelled_at, date: true },
        { label: t.field_handled_at, value: row.handled_at, date: true },
        { label: t.note, value: row.handled_note },
    ];
});
</script>

<template>
    <div class="max-w-page mx-auto" data-max-width-wrapper>
        <Head :title="[t.title]" />

        <Header :title="t.title" icon="x-square" />

        <CommandPaletteItem
            :text="[t.utilities, t.title]"
            :url="listingUrl"
            icon="x-square"
            prioritize
        />

        <EmptyStateMenu v-if="!hasAny" :heading="t.empty_heading">
            <EmptyStateItem
                :heading="t.empty_title"
                :description="t.empty_description"
                icon="x-square"
            />
        </EmptyStateMenu>

        <Listing
            v-else
            :url="listingUrl"
            :action-url="actionUrl"
            :filters="filters"
            :sort-column="sortColumn"
            :sort-direction="sortDirection"
            preferences-prefix="statamic-payments.cancellations"
            push-query
        >
            <template #cell-public_id="{ row }">
                <button type="button" class="text-start font-mono text-sm font-medium hover:text-primary" @click="detail = row">
                    {{ row.public_id }}
                </button>
            </template>

            <template #cell-confirmed_at="{ row }">
                <date-time :of="row.confirmed_at" />
                <span v-if="!row.receipt_sent_at" class="block text-2xs text-red-600 dark:text-red-400">{{ t.receipt_missing }}</span>
            </template>

            <template #cell-email="{ row }">
                <span>{{ row.email }}</span>
                <span v-if="row.name" class="block text-2xs text-gray-500 dark:text-gray-400">{{ row.name }}</span>
            </template>

            <template #cell-identification="{ row }">
                <span class="font-mono text-xs">{{ row.identification }}</span>
            </template>

            <template #cell-kind="{ row }">
                <span class="text-gray-600 dark:text-gray-400">{{ row.kind_label }}</span>
            </template>

            <template #cell-effective_at="{ row }">
                <span v-if="row.effective_at" class="tabular-nums">{{ row.effective_at }}</span>
                <span v-else class="text-gray-500 dark:text-gray-400">{{ t.effective_earliest }}</span>
            </template>

            <template #cell-subscription="{ row }">
                <template v-if="row.subscription">
                    <a :href="subscriptionLink(row.subscription)" class="text-primary hover:underline">
                        #{{ row.subscription.id }} · {{ row.subscription.product }}
                    </a>
                    <span class="block mt-1">
                        <Badge v-if="row.provider_cancelled_at" color="green" :text="t.provider_cancelled" />
                        <Badge v-else color="amber" :text="t.provider_not_cancelled" />
                    </span>
                </template>
                <Badge v-else color="amber" :text="t.unmatched" />
            </template>

            <template #cell-handled_at="{ row }">
                <Badge v-if="row.handled_at" color="green" :text="t.handled" />
                <Badge v-else color="default" :text="t.open" />
            </template>

            <template #prepended-row-actions="{ row }">
                <DropdownItem icon="eye" :text="t.detail_action" @click="detail = row" />
            </template>
        </Listing>

        <Stack v-model:open="detailOpen" :title="detail ? detail.public_id : ''" icon="x-square" size="narrow">
            <div v-if="detail" class="space-y-6">
                <div class="flex flex-wrap items-center gap-2">
                    <Badge :color="detail.handled_at ? 'green' : 'default'" :text="detail.handled_at ? t.handled : t.open" />
                    <Badge v-if="detail.subscription && detail.provider_cancelled_at" color="green" :text="t.provider_cancelled" />
                    <Badge v-else-if="detail.subscription" color="amber" :text="t.provider_not_cancelled" />
                    <Badge v-else color="amber" :text="t.unmatched" />
                </div>

                <dl class="divide-y divide-content-border border-y border-content-border">
                    <div
                        v-for="field in detailFields"
                        :key="field.label"
                        class="flex items-baseline justify-between gap-4 py-2"
                    >
                        <dt class="text-sm text-gray-600 dark:text-gray-400">{{ field.label }}</dt>
                        <dd class="text-end text-sm">
                            <date-time v-if="field.date && field.value" :of="field.value" />
                            <span v-else-if="field.value" class="whitespace-pre-line">{{ field.value }}</span>
                            <span v-else class="text-gray-500 dark:text-gray-400">{{ t.none }}</span>
                        </dd>
                    </div>
                </dl>

                <div v-if="detail.subscription" class="text-sm">
                    <span class="text-gray-600 dark:text-gray-400">{{ t.field_subscription }}:</span>
                    <a :href="subscriptionLink(detail.subscription)" class="text-primary hover:underline">#{{ detail.subscription.id }} · {{ detail.subscription.product }} · {{ detail.subscription.status }}</a>
                </div>
            </div>
        </Stack>

        <DocsCallout
            :topic="t.title"
            url="https://github.com/goldnead/statamic-payments#readme"
        />
    </div>
</template>
