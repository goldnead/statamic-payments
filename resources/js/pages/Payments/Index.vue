<script setup>
import { Head } from '@statamic/cms/inertia';
import { Header, Badge, Listing, EmptyStateMenu, EmptyStateItem, DocsCallout, CommandPaletteItem, DropdownItem } from '@statamic/cms/ui';

/**
 * The payments listing.
 *
 * No rows are passed in: the Listing fetches them from `listingUrl`, the way
 * core's own listings work. Handing them over as well would query the same list
 * twice on every page load.
 */
defineProps({
    listingUrl: { type: String, required: true },
    filters: { type: Array, default: () => [] },
    sortColumn: { type: String, default: 'created_at' },
    sortDirection: { type: String, default: 'desc' },
    // Whether any payment exists at all — deliberately not "did this search
    // find something". A fruitless search must not claim the webhook is broken.
    hasAny: { type: Boolean, default: false },
});

const statusColor = (status) => ({
    paid: 'green',
    open: 'blue',
    initiated: 'default',
    failed: 'red',
    expired: 'amber',
    canceled: 'red',
}[status] ?? 'default');
</script>

<template>
    <div class="max-w-page mx-auto" data-max-width-wrapper>
        <Head :title="[__('statamic-payments::messages.utility_title')]" />

        <Header :title="__('statamic-payments::messages.utility_title')" icon="money-cash-bill" />

        <!-- Reachable from ⌘K like every core screen. There is no primary
             action to register — the screen only reads — so what the palette
             gets is the screen itself. -->
        <CommandPaletteItem
            :text="[__('Utilities'), __('statamic-payments::messages.utility_title')]"
            :url="listingUrl"
            icon="money-cash-bill"
            prioritize
        />

        <EmptyStateMenu v-if="!hasAny" :heading="__('statamic-payments::messages.empty_heading')">
            <EmptyStateItem
                :heading="__('statamic-payments::messages.empty_title')"
                :description="__('statamic-payments::messages.empty_description')"
                icon="money-cash-bill"
            />
        </EmptyStateMenu>

        <Listing
            v-else
            :url="listingUrl"
            :filters="filters"
            :sort-column="sortColumn"
            :sort-direction="sortDirection"
            preferences-prefix="statamic-payments.payments"
            push-query
        >
            <template #cell-created_at="{ row }">
                <date-time :of="row.created_at" />
            </template>

            <!-- The title cell links to the detail page, the way an entry's
                 title links to its publish form. -->
            <template #cell-product="{ row }">
                <a :href="row.url" class="font-medium hover:text-primary">{{ row.product_name || row.product }}</a>
                <span v-if="row.product_name" class="block text-2xs text-gray-500 dark:text-gray-400 font-mono">{{ row.product }}</span>
            </template>

            <template #cell-amount="{ row }">
                <span class="tabular-nums">{{ row.amount }} {{ row.currency }}</span>
            </template>

            <template #cell-status="{ row }">
                <Badge :color="statusColor(row.status)" :text="row.status_label" />
            </template>

            <template #cell-fulfilled_at="{ row }">
                <span v-if="row.fulfilled_at" class="text-gray-600 dark:text-gray-400">
                    <date-time :of="row.fulfilled_at" />
                </span>
                <span v-else class="text-gray-500 dark:text-gray-400">&mdash;</span>
            </template>

            <template #cell-email="{ row }">
                <span>{{ row.email || '—' }}</span>
                <span v-if="row.name" class="block text-2xs text-gray-500 dark:text-gray-400">{{ row.name }}</span>
            </template>

            <template #cell-provider_id="{ row }">
                <span class="font-mono text-xs">{{ row.provider_id }}</span>
            </template>

            <template #prepended-row-actions="{ row }">
                <DropdownItem icon="eye" :text="__('statamic-payments::messages.detail_action')" :href="row.url" />
            </template>
        </Listing>

        <DocsCallout
            :topic="__('statamic-payments::messages.utility_title')"
            url="https://github.com/goldnead/statamic-payments#readme"
        />
    </div>
</template>
