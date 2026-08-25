<script setup>
import { computed, ref } from 'vue';
import { Head } from '@statamic/cms/inertia';
import {
    Header, Badge, Listing, EmptyStateMenu, EmptyStateItem, DocsCallout,
    CommandPaletteItem, DropdownItem, Stack, Heading,
    Table, TableColumns, TableColumn, TableRows, TableRow, TableCell,
} from '@statamic/cms/ui';

/**
 * Subscriptions.
 *
 * Built as the twin of the payments screen next door, on purpose: two utilities
 * from the same addon that answer the same gestures differently is a worse tell
 * than either of them looking slightly off on its own.
 *
 * There is no "New" button and no form. An agreement is not something somebody
 * types — it is what a confirmed first payment leaves behind — so a button
 * offering to create one would offer to create a row the provider has never
 * heard of and will never charge.
 *
 * Cancelling is not here either. It is a registered action, which core already
 * offers from a row's own menu and from the bulk toolbar, with its own
 * confirmation and its own refresh afterwards. A hand-rolled button beside it
 * put two entries reading "Cancel subscription" in the same menu — which is how
 * this file came to know that.
 *
 * Every label arrives finished in `t`. Nothing here composes a sentence, and
 * nothing here works anything out: the row shows what the server decided.
 */
const props = defineProps({
    listingUrl: { type: String, required: true },
    actionUrl: { type: String, required: true },
    filters: { type: Array, default: () => [] },
    sortColumn: { type: String, default: 'next_payment_at' },
    sortDirection: { type: String, default: 'asc' },
    // Whether any subscription exists at all — deliberately not "did this
    // search find something". A fruitless search must not claim the webhook is
    // broken.
    hasAny: { type: Boolean, default: false },
    t: { type: Object, required: true },
});

const statusColor = (status) => ({
    active: 'green',
    pending: 'blue',
    initiated: 'default',
    suspended: 'amber',
    cancelled: 'red',
    completed: 'default',
}[status] ?? 'default');

/* ------------------------------------------------------------------ detail */

const detail = ref(null);

/**
 * `:open` bound to a value, not `v-if` on the component. A Stack owns its own
 * visibility and its own focus trap; mounting it conditionally means it never
 * opens, which looks exactly like a row that does not react to being clicked.
 */
const detailOpen = computed({
    get: () => detail.value !== null,
    set: (value) => { if (! value) detail.value = null; },
});

const detailTitle = computed(() => (detail.value
    ? (detail.value.product_name || detail.value.product)
    : ''));

/**
 * The rows of the detail list, assembled from what the server already worked
 * out. Not the type: that stands in the line above the list, and saying it twice
 * in a slide-over this short reads as a template that lost count.
 */
const detailFields = computed(() => {
    const row = detail.value;
    const t = props.t;

    if (! row) return [];

    return [
        { label: t.field_amount, value: `${row.amount} ${row.currency}` },
        { label: t.field_rhythm, value: row.rhythm },
        { label: t.field_progress, value: row.progress },
        { label: t.field_total, value: row.total ? `${row.total} ${row.currency}` : null },
        { label: t.field_starts_at, value: row.starts_at, date: true },
        { label: t.field_next_payment, value: row.next_payment_at, date: true },
        { label: t.field_cancelled_at, value: row.cancelled_at, date: true },
        { label: t.field_ended_at, value: row.ended_at, date: true },
        { label: t.field_buyer, value: row.email },
        { label: t.field_name, value: row.name },
        { label: t.field_provider, value: row.provider },
        { label: t.field_provider_id, value: row.provider_id, mono: true },
        { label: t.field_customer_reference, value: row.customer_reference, mono: true },
    ];
});

</script>

<template>
    <div class="max-w-page mx-auto" data-max-width-wrapper>
        <Head :title="[t.title]" />

        <Header :title="t.title" icon="sync" />

        <!-- Reachable from ⌘K like every core screen. There is no primary
             action to register — nothing here is created — so what the palette
             gets is the screen itself. -->
        <CommandPaletteItem
            :text="[t.utilities, t.title]"
            :url="listingUrl"
            icon="sync"
            prioritize
        />

        <EmptyStateMenu v-if="!hasAny" :heading="t.empty_heading">
            <EmptyStateItem
                :heading="t.empty_title"
                :description="t.empty_description"
                icon="sync"
            />
        </EmptyStateMenu>

        <!-- The core listing, fed the way core feeds its own: an `action-url`
             is what turns on the checkboxes, the bulk toolbar and the per-row
             action menu, and a `preferences-prefix` is what makes saved views
             and the column picker remember anything. -->
        <Listing
            v-else
            :url="listingUrl"
            :action-url="actionUrl"
            :filters="filters"
            :sort-column="sortColumn"
            :sort-direction="sortDirection"
            preferences-prefix="statamic-payments.subscriptions"
            push-query
        >
            <template #cell-product="{ row }">
                <button type="button" class="text-start font-medium hover:text-primary" @click="detail = row">
                    {{ row.product_name || row.product }}
                </button>
                <span v-if="row.product_name" class="block text-2xs text-gray-500 dark:text-gray-400 font-mono">{{ row.product }}</span>
            </template>

            <template #cell-kind="{ row }">
                <span class="text-gray-600 dark:text-gray-400">{{ row.kind }}</span>
            </template>

            <template #cell-amount="{ row }">
                <span class="tabular-nums">{{ row.amount }} {{ row.currency }}</span>
            </template>

            <template #cell-rhythm="{ row }">
                <span class="text-gray-600 dark:text-gray-400">{{ row.rhythm }}</span>
            </template>

            <template #cell-progress="{ row }">
                <span class="tabular-nums">{{ row.progress }}</span>
            </template>

            <template #cell-next_payment_at="{ row }">
                <span v-if="row.next_payment_at" class="text-gray-600 dark:text-gray-400">
                    <date-time :of="row.next_payment_at" />
                </span>
                <span v-else class="text-gray-500 dark:text-gray-400">{{ t.none }}</span>
            </template>

            <template #cell-status="{ row }">
                <Badge :color="statusColor(row.status)" :text="row.status_label" />
            </template>

            <template #cell-email="{ row }">
                <span>{{ row.email || t.none }}</span>
                <span v-if="row.name" class="block text-2xs text-gray-500 dark:text-gray-400">{{ row.name }}</span>
            </template>

            <template #cell-provider_id="{ row }">
                <span class="font-mono text-xs">{{ row.provider_id }}</span>
            </template>

            <!-- Prepended, so it sits above the registered actions core fetches
                 for the row rather than replacing them. -->
            <template #prepended-row-actions="{ row }">
                <DropdownItem icon="eye" :text="t.detail_action" @click="detail = row" />
            </template>
        </Listing>

        <Stack v-model:open="detailOpen" :title="detailTitle" icon="sync" size="narrow">
            <!-- Read-only. What can be changed about an agreement is whether it
                 goes on, and that is the row action, not a field in here. -->
            <div v-if="detail" class="space-y-6">
                <div class="flex items-center gap-2">
                    <Badge :color="statusColor(detail.status)" :text="detail.status_label" />
                    <span class="text-sm text-gray-600 dark:text-gray-400">{{ detail.kind }}</span>
                </div>

                <!-- Surfaces and rules use core's tokens, never a literal
                     colour: the palette is themeable at runtime, and a
                     hard-coded one drifts the moment somebody re-themes their
                     Control Panel. -->
                <dl class="divide-y divide-content-border border-y border-content-border">
                    <div
                        v-for="field in detailFields"
                        :key="field.label"
                        class="flex items-baseline justify-between gap-4 py-2"
                    >
                        <dt class="text-sm text-gray-600 dark:text-gray-400">{{ field.label }}</dt>
                        <dd class="text-end text-sm" :class="field.mono ? 'font-mono text-xs' : 'tabular-nums'">
                            <date-time v-if="field.date && field.value" :of="field.value" />
                            <span v-else-if="field.value">{{ field.value }}</span>
                            <span v-else class="text-gray-500 dark:text-gray-400">{{ t.none }}</span>
                        </dd>
                    </div>
                </dl>

                <div class="space-y-3">
                    <Heading :text="t.detail_payments" size="base" />

                    <!-- The cycles came along with the row. What this shows is
                         the money that actually moved; the list above is only
                         the agreement. -->
                    <Table v-if="detail.payments.length">
                        <TableColumns>
                            <TableColumn>{{ t.payment_when }}</TableColumn>
                            <TableColumn>{{ t.payment_amount }}</TableColumn>
                            <TableColumn>{{ t.payment_status }}</TableColumn>
                        </TableColumns>
                        <TableRows>
                            <TableRow v-for="payment in detail.payments" :key="payment.id">
                                <TableCell><date-time :of="payment.created_at" /></TableCell>
                                <TableCell class="tabular-nums">{{ payment.amount }} {{ payment.currency }}</TableCell>
                                <TableCell>
                                    <Badge
                                        :color="payment.status === 'paid' ? 'green' : 'default'"
                                        :text="payment.status_label"
                                    />
                                </TableCell>
                            </TableRow>
                        </TableRows>
                    </Table>

                    <p v-else class="text-sm text-gray-500 dark:text-gray-400">{{ t.detail_no_payments }}</p>
                </div>
            </div>
        </Stack>

        <DocsCallout
            :topic="t.title"
            url="https://github.com/goldnead/statamic-payments#readme"
        />
    </div>
</template>
