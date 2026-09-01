<script setup>
import { computed, ref } from 'vue';
import { Head } from '@statamic/cms/inertia';
import {
    Header, Badge, Listing, EmptyStateMenu, EmptyStateItem, DocsCallout,
    CommandPaletteItem, DropdownItem, Stack,
} from '@statamic/cms/ui';

/**
 * Withdrawals — § 356a BGB.
 *
 * The twin of the subscriptions screen: the core Listing fed the way core feeds
 * its own, an action URL so the row menu and the checkboxes appear, a
 * slide-over for the whole record. Marking a case as handled is a registered
 * action, not a button drawn here, so it sits in the same menu as everything
 * else and carries its own confirmation.
 *
 * Every label arrives finished in `t`. Nothing here composes a sentence, and
 * nothing here decides anything: whether a right has expired or a period has
 * run is shown as the hint the server computed and read by a person.
 */
const props = defineProps({
    listingUrl: { type: String, required: true },
    actionUrl: { type: String, required: true },
    paymentsUrl: { type: String, required: true },
    subscriptionsUrl: { type: String, default: '' },
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

/** The payments listing already searches by provider id; a link there is the "detail page" this addon has today. */
const paymentLink = (payment) => `${props.paymentsUrl}?search=${encodeURIComponent(payment.provider_id)}`;

const detailFields = computed(() => {
    const row = detail.value;
    const t = props.t;

    if (! row) return [];

    return [
        { label: t.field_name, value: row.name },
        { label: t.field_email, value: row.email },
        { label: t.field_reference, value: row.order_reference },
        { label: t.field_contact, value: row.contact },
        { label: t.field_message, value: row.message },
        { label: t.field_declared_at, value: row.declared_at, date: true },
        { label: t.field_confirmed_at, value: row.confirmed_at, date: true },
        { label: t.field_receipt_sent_at, value: row.receipt_sent_at, date: true },
        { label: t.field_merchant_notified_at, value: row.merchant_notified_at, date: true },
        { label: t.field_handled_at, value: row.handled_at, date: true },
        { label: t.note, value: row.handled_note },
    ];
});
</script>

<template>
    <div class="max-w-page mx-auto" data-max-width-wrapper>
        <Head :title="[t.title]" />

        <Header :title="t.title" icon="return-square" />

        <CommandPaletteItem
            :text="[t.utilities, t.title]"
            :url="listingUrl"
            icon="return-square"
            prioritize
        />

        <EmptyStateMenu v-if="!hasAny" :heading="t.empty_heading">
            <EmptyStateItem
                :heading="t.empty_title"
                :description="t.empty_description"
                icon="return-square"
            />
        </EmptyStateMenu>

        <Listing
            v-else
            :url="listingUrl"
            :action-url="actionUrl"
            :filters="filters"
            :sort-column="sortColumn"
            :sort-direction="sortDirection"
            preferences-prefix="statamic-payments.withdrawals"
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

            <template #cell-order_reference="{ row }">
                <span class="font-mono text-xs">{{ row.order_reference }}</span>
            </template>

            <template #cell-payment="{ row }">
                <a v-if="row.payment" :href="paymentLink(row.payment)" class="text-primary hover:underline">
                    #{{ row.payment.id }} · {{ row.payment.product }}
                </a>
                <span v-if="row.payment" class="block text-2xs text-gray-500 dark:text-gray-400 tabular-nums">{{ row.payment.amount }} {{ row.payment.currency }} · {{ row.payment.status }}</span>
                <Badge v-else color="amber" :text="t.unmatched" />
            </template>

            <template #cell-hints="{ row }">
                <div class="flex flex-wrap gap-1">
                    <Badge v-if="row.right_expired_hint" color="amber" :text="t.expired_hint" />
                    <Badge v-if="row.within_period === false" color="amber" :text="t.outside_period" />
                    <span v-if="!row.right_expired_hint && row.within_period !== false" class="text-gray-500 dark:text-gray-400">{{ t.none }}</span>
                </div>
            </template>

            <template #cell-handled_at="{ row }">
                <Badge v-if="row.handled_at" color="green" :text="t.handled" />
                <Badge v-else color="default" :text="t.open" />
            </template>

            <template #prepended-row-actions="{ row }">
                <DropdownItem icon="eye" :text="t.detail_action" @click="detail = row" />
            </template>
        </Listing>

        <Stack v-model:open="detailOpen" :title="detail ? detail.public_id : ''" icon="return-square" size="narrow">
            <div v-if="detail" class="space-y-6">
                <div class="flex flex-wrap items-center gap-2">
                    <Badge :color="detail.handled_at ? 'green' : 'default'" :text="detail.handled_at ? t.handled : t.open" />
                    <Badge v-if="detail.right_expired_hint" color="amber" :text="t.expired_hint" />
                    <Badge v-if="detail.within_period === false" color="amber" :text="t.outside_period" />
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

                <div v-if="detail.payment" class="text-sm">
                    <span class="text-gray-600 dark:text-gray-400">{{ t.field_payment }}:</span>
                    <a :href="paymentLink(detail.payment)" class="text-primary hover:underline">#{{ detail.payment.id }} · {{ detail.payment.product }} · {{ detail.payment.amount }} {{ detail.payment.currency }}</a>
                </div>
                <p v-else class="text-sm text-gray-500 dark:text-gray-400">{{ t.unmatched }}</p>
            </div>
        </Stack>

        <DocsCallout
            :topic="t.title"
            url="https://github.com/goldnead/statamic-payments#readme"
        />
    </div>
</template>
