<script setup>
import { computed } from 'vue';
import { Head } from '@statamic/cms/inertia';
// The static tables below use core's globally registered `ui-table-*` tags —
// the same `Table` component, addressed the way core exposes it to templates.
import { Header, Badge, Button, Panel, Card, DocsCallout } from '@statamic/cms/ui';

/**
 * One payment, whole.
 *
 * Read-only, like the listing. Everything here was worked out on the server
 * (`PaymentDetail`): amounts are formatted strings, dates are ISO-8601, the
 * page shows and links. Sections are panels, the way a publish form groups its
 * fields, with the money and the movement in the main column and the facts
 * about the person in the sidebar.
 *
 * A section whose neighbour is not installed (invoice, webhook deliveries) is
 * simply not rendered — `null` from the server means "no neighbour", an empty
 * list means "neighbour, nothing to show", and the two look different on
 * purpose.
 */
const props = defineProps({
    payment: { type: Object, required: true },
    listingUrl: { type: String, required: true },
});

const p = computed(() => props.payment);

const statusColor = (status) => ({
    paid: 'green',
    open: 'blue',
    initiated: 'default',
    failed: 'red',
    expired: 'amber',
    canceled: 'red',
}[status] ?? 'default');

const communicationColor = (status) => ({
    sent: 'green',
    queued: 'blue',
    failed: 'red',
}[status] ?? 'default');

const webhookColor = (status) => ({
    success: 'green',
    pending: 'blue',
    processing: 'blue',
    failed: 'red',
    cancelled: 'default',
}[status] ?? 'default');

const t = (key, replacements) => __(`statamic-payments::messages.${key}`, replacements);

/* Rows for the description lists. `value: null` renders the dash. */
const buyerRows = computed(() => [
    { label: t('detail_field_email'), value: p.value.buyer.email },
    { label: t('detail_field_name'), value: p.value.buyer.name },
    { label: t('detail_field_country'), value: p.value.buyer.country ? `${p.value.buyer.country}${p.value.buyer.country_source ? ` (${p.value.buyer.country_source})` : ''}` : null },
    { label: t('detail_field_address'), value: p.value.buyer.address, multiline: true },
    { label: t('detail_field_vat_id'), value: p.value.buyer.vat_id, mono: true },
    { label: t('detail_field_customer_reference'), value: p.value.buyer.customer_reference, mono: true },
]);

const consentRows = computed(() => [
    { label: t('detail_field_consent_at'), value: p.value.consent.at, date: true },
    { label: t('detail_field_withdrawal_version'), value: p.value.consent.withdrawal_version, mono: true },
]);

const accessRows = computed(() => (p.value.access ? [
    { label: t('detail_field_access_starts'), value: p.value.access.starts_at },
    { label: t('detail_field_access_days'), value: p.value.access.days },
    { label: t('detail_field_access_expires'), value: p.value.access.expires_at, date: true },
] : []));

const originRows = computed(() => [
    { label: 'utm_source', value: p.value.origin.utm_source },
    { label: 'utm_medium', value: p.value.origin.utm_medium },
    { label: 'utm_campaign', value: p.value.origin.utm_campaign },
    { label: 'utm_term', value: p.value.origin.utm_term },
    { label: 'utm_content', value: p.value.origin.utm_content },
    { label: t('detail_field_referrer'), value: p.value.origin.referrer, mono: true },
    { label: t('detail_field_landing_page'), value: p.value.origin.landing_page, mono: true },
]);

const hasOrigin = computed(() => originRows.value.some((r) => r.value));

const cardRows = computed(() => [
    { label: t('detail_field_card_label'), value: p.value.card.label },
    { label: t('detail_field_card_last4'), value: p.value.card.last4 ? `•••• ${p.value.card.last4}` : null, mono: true },
]);

const refundRows = computed(() => [
    { label: t('detail_field_refunded'), value: p.value.refunds.amount ? `${p.value.refunds.amount} ${p.value.currency}` : null },
    { label: t('detail_field_refunded_at'), value: p.value.refunds.at, date: true },
    { label: t('detail_field_refund_references'), value: p.value.refunds.references.length ? p.value.refunds.references.join(', ') : null, mono: true },
]);

const headRows = computed(() => [
    { label: t('detail_field_created_at'), value: p.value.created_at, date: true },
    { label: t('detail_field_paid_at'), value: p.value.paid_at, date: true },
    { label: t('detail_field_fulfilled_at'), value: p.value.fulfilled_at, date: true },
    { label: t('detail_field_provider'), value: p.value.provider },
    { label: t('detail_field_provider_id'), value: p.value.provider_id, mono: true },
    { label: t('detail_field_discount'), value: p.value.discount ? `${p.value.discount} ${p.value.currency}${p.value.discount_code ? ` (${p.value.discount_code})` : ''}` : null },
]);

const hasLinks = computed(() => {
    const l = p.value.links;
    return l.parent || l.children.length || l.subscription || l.invoice || l.withdrawals.length || l.cancellations.length;
});
</script>

<template>
    <div class="max-w-page mx-auto" data-max-width-wrapper>
        <Head :title="[p.title, __('statamic-payments::messages.utility_title')]" />

        <Header :title="p.title" icon="money-cash-bill">
            <Button :href="listingUrl" :text="__('statamic-payments::messages.detail_back')" />
        </Header>

        <!-- The head: what was paid, whether it was, and when. -->
        <Card class="mb-8">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="space-y-1">
                    <div class="flex items-center gap-3">
                        <span class="text-2xl font-semibold tabular-nums">{{ p.amount }} {{ p.currency }}</span>
                        <Badge :color="statusColor(p.status)" :text="p.status_label" size="lg" />
                    </div>
                    <div class="text-sm text-gray-900 dark:text-gray-300">
                        <span class="font-medium">{{ p.product_name || p.product }}</span>
                        <span v-if="p.product_name" class="ms-2 font-mono text-xs text-gray-500 dark:text-gray-400">{{ p.product }}</span>
                    </div>
                </div>
                <dl class="grid *:min-w-0 gap-x-8 gap-y-1 text-sm sm:grid-cols-2">
                    <template v-for="row in headRows" :key="row.label">
                        <div class="flex items-baseline justify-between gap-4 sm:justify-start">
                            <dt class="text-gray-600 dark:text-gray-400">{{ row.label }}</dt>
                            <dd :class="row.mono ? 'font-mono text-xs' : 'tabular-nums'">
                                <date-time v-if="row.date && row.value" :of="row.value" />
                                <span v-else-if="row.value">{{ row.value }}</span>
                                <span v-else class="text-gray-500 dark:text-gray-400">{{ t('none') }}</span>
                            </dd>
                        </div>
                    </template>
                </dl>
            </div>
        </Card>

        <div class="grid *:min-w-0 gap-x-8 lg:grid-cols-3">
            <div class="lg:col-span-2">

                <!-- Positionen -->
                <Panel :heading="t('detail_section_items')">
                    <!-- Wide content scrolls inside its card; the page never does. -->
                    <Card inset class="overflow-x-auto">
                        <ui-table v-if="p.items.length">
                            <ui-table-columns>
                                <ui-table-column>{{ t('detail_item_name') }}</ui-table-column>
                                <ui-table-column>{{ t('detail_item_kind') }}</ui-table-column>
                                <ui-table-column class="text-end">{{ t('detail_item_quantity') }}</ui-table-column>
                                <ui-table-column class="text-end">{{ t('detail_item_unit') }}</ui-table-column>
                                <ui-table-column class="text-end">{{ t('detail_item_total') }}</ui-table-column>
                            </ui-table-columns>
                            <ui-table-rows>
                                <ui-table-row v-for="item in p.items" :key="item.id">
                                    <ui-table-cell>
                                        <span class="font-medium">{{ item.name }}</span>
                                        <span class="block font-mono text-2xs text-gray-500 dark:text-gray-400">
                                            {{ item.product }}<template v-if="item.offer"> · {{ t('detail_item_offer') }} {{ item.offer }}</template>
                                        </span>
                                    </ui-table-cell>
                                    <ui-table-cell><Badge :text="item.kind_label" /></ui-table-cell>
                                    <ui-table-cell class="text-end tabular-nums">{{ item.quantity }}</ui-table-cell>
                                    <ui-table-cell class="text-end tabular-nums">
                                        {{ item.unit }}
                                        <span v-if="item.discount" class="block text-2xs text-gray-500 dark:text-gray-400">− {{ item.discount }}</span>
                                    </ui-table-cell>
                                    <ui-table-cell class="text-end tabular-nums">{{ item.total }}</ui-table-cell>
                                </ui-table-row>
                            </ui-table-rows>
                        </ui-table>
                        <p v-else class="p-4 text-sm text-gray-500 dark:text-gray-400">{{ t('detail_items_empty') }}</p>
                    </Card>
                </Panel>

                <!-- Kommunikation -->
                <Panel :heading="t('detail_section_communications')" :subheading="t('detail_communications_hint')">
                    <Card inset class="overflow-x-auto">
                        <ui-table v-if="p.communications.length">
                            <ui-table-columns>
                                <ui-table-column>{{ t('detail_comm_when') }}</ui-table-column>
                                <ui-table-column>{{ t('detail_comm_what') }}</ui-table-column>
                                <ui-table-column>{{ t('detail_comm_recipient') }}</ui-table-column>
                                <ui-table-column>{{ t('detail_comm_status') }}</ui-table-column>
                            </ui-table-columns>
                            <ui-table-rows>
                                <ui-table-row v-for="c in p.communications" :key="c.id">
                                    <ui-table-cell class="whitespace-nowrap"><date-time :of="c.happened_at" /></ui-table-cell>
                                    <ui-table-cell>
                                        <span class="font-medium">{{ c.kind_label }}</span>
                                        <span class="ms-2 text-2xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ c.channel_label }}</span>
                                        <span v-if="c.subject" class="block text-xs text-gray-600 dark:text-gray-400">{{ c.subject }}</span>
                                        <span v-if="c.reference" class="block font-mono text-2xs text-gray-500 dark:text-gray-400">{{ c.reference }}</span>
                                    </ui-table-cell>
                                    <ui-table-cell class="text-sm">{{ c.recipient || t('none') }}</ui-table-cell>
                                    <ui-table-cell><Badge :color="communicationColor(c.status)" :text="c.status_label" /></ui-table-cell>
                                </ui-table-row>
                            </ui-table-rows>
                        </ui-table>
                        <p v-else class="p-4 text-sm text-gray-500 dark:text-gray-400">{{ t('detail_communications_empty') }}</p>
                    </Card>
                </Panel>

                <!-- Webhook-Zustellungen: only when the neighbour answers. -->
                <Panel v-if="p.webhooks !== null" :heading="t('detail_section_webhooks')">
                    <Card inset class="overflow-x-auto">
                        <ui-table v-if="p.webhooks.length">
                            <ui-table-columns>
                                <ui-table-column>{{ t('detail_comm_when') }}</ui-table-column>
                                <ui-table-column>{{ t('detail_webhook_target') }}</ui-table-column>
                                <ui-table-column>{{ t('detail_comm_status') }}</ui-table-column>
                            </ui-table-columns>
                            <ui-table-rows>
                                <ui-table-row v-for="w in p.webhooks" :key="w.uuid">
                                    <ui-table-cell class="whitespace-nowrap"><date-time v-if="w.at" :of="w.at" /><span v-else>{{ t('none') }}</span></ui-table-cell>
                                    <ui-table-cell>
                                        <span class="font-medium">{{ w.trigger || t('none') }}</span>
                                        <span class="block truncate font-mono text-2xs text-gray-500 dark:text-gray-400">{{ w.url }}</span>
                                    </ui-table-cell>
                                    <ui-table-cell>
                                        <Badge :color="webhookColor(w.status)" :text="w.status || '—'" />
                                        <span v-if="w.response_status" class="ms-2 font-mono text-xs text-gray-500 dark:text-gray-400">{{ w.response_status }}</span>
                                        <span v-if="w.attempts > 1" class="ms-1 text-2xs text-gray-500 dark:text-gray-400">×{{ w.attempts }}</span>
                                    </ui-table-cell>
                                </ui-table-row>
                            </ui-table-rows>
                        </ui-table>
                        <p v-else class="p-4 text-sm text-gray-500 dark:text-gray-400">{{ t('detail_webhooks_empty') }}</p>
                    </Card>
                </Panel>

                <!-- Verknüpfungen -->
                <Panel :heading="t('detail_section_links')">
                    <Card>
                        <dl v-if="hasLinks" class="divide-y divide-content-border">
                            <div v-if="p.links.parent" class="flex items-baseline justify-between gap-4 py-2">
                                <dt class="text-sm text-gray-600 dark:text-gray-400">{{ t('detail_link_parent') }}</dt>
                                <dd class="text-end text-sm">
                                    <a :href="p.links.parent.url" class="text-primary hover:underline">#{{ p.links.parent.id }} · {{ p.links.parent.product }}</a>
                                    <span class="ms-2 tabular-nums text-gray-600 dark:text-gray-400">{{ p.links.parent.amount }} {{ p.links.parent.currency }}</span>
                                </dd>
                            </div>
                            <div v-for="child in p.links.children" :key="child.id" class="flex items-baseline justify-between gap-4 py-2">
                                <dt class="text-sm text-gray-600 dark:text-gray-400">{{ t('detail_link_child') }}</dt>
                                <dd class="text-end text-sm">
                                    <a :href="child.url" class="text-primary hover:underline">#{{ child.id }} · {{ child.product }}</a>
                                    <span class="ms-2 tabular-nums text-gray-600 dark:text-gray-400">{{ child.amount }} {{ child.currency }}</span>
                                    <Badge class="ms-2" :color="statusColor(child.status)" :text="child.status" />
                                </dd>
                            </div>
                            <div v-if="p.links.subscription" class="flex items-baseline justify-between gap-4 py-2">
                                <dt class="text-sm text-gray-600 dark:text-gray-400">{{ t('detail_link_subscription') }}</dt>
                                <dd class="text-end text-sm">
                                    <a :href="p.links.subscription.url" class="font-mono text-xs text-primary hover:underline">{{ p.links.subscription.provider_id }}</a>
                                    <Badge class="ms-2" :text="p.links.subscription.status_label" />
                                </dd>
                            </div>
                            <div v-if="p.links.invoice" class="flex items-baseline justify-between gap-4 py-2">
                                <dt class="text-sm text-gray-600 dark:text-gray-400">{{ t('detail_link_invoice') }}</dt>
                                <dd class="text-end text-sm">
                                    <span class="font-mono text-xs">{{ p.links.invoice.number }}</span>
                                    <span v-if="p.links.invoice.issued_at" class="ms-2 text-gray-600 dark:text-gray-400"><date-time :of="p.links.invoice.issued_at" /></span>
                                </dd>
                            </div>
                            <div v-for="w in p.links.withdrawals" :key="'w' + w.id" class="flex items-baseline justify-between gap-4 py-2">
                                <dt class="text-sm text-gray-600 dark:text-gray-400">{{ t('detail_link_withdrawal') }}</dt>
                                <dd class="text-end text-sm">
                                    <a :href="w.url" class="font-mono text-xs text-primary hover:underline">{{ w.public_id }}</a>
                                    <span v-if="w.confirmed_at" class="ms-2 text-gray-600 dark:text-gray-400"><date-time :of="w.confirmed_at" /></span>
                                    <Badge class="ms-2" :color="w.handled_at ? 'default' : 'amber'" :text="w.handled_at ? t('legal_handled') : t('legal_open')" />
                                </dd>
                            </div>
                            <div v-for="c in p.links.cancellations" :key="'c' + c.id" class="flex items-baseline justify-between gap-4 py-2">
                                <dt class="text-sm text-gray-600 dark:text-gray-400">{{ t('detail_link_cancellation') }}</dt>
                                <dd class="text-end text-sm">
                                    <a :href="c.url" class="font-mono text-xs text-primary hover:underline">{{ c.public_id }}</a>
                                    <span v-if="c.confirmed_at" class="ms-2 text-gray-600 dark:text-gray-400"><date-time :of="c.confirmed_at" /></span>
                                    <Badge class="ms-2" :color="c.handled_at ? 'default' : 'amber'" :text="c.handled_at ? t('legal_handled') : t('legal_open')" />
                                </dd>
                            </div>
                        </dl>
                        <p v-else class="text-sm text-gray-500 dark:text-gray-400">{{ t('detail_links_empty') }}</p>
                    </Card>
                </Panel>
            </div>

            <div>
                <!-- Käufer -->
                <Panel :heading="t('detail_section_buyer')">
                    <Card>
                        <dl class="divide-y divide-content-border">
                            <div v-for="row in buyerRows" :key="row.label" class="py-2">
                                <dt class="text-xs text-gray-600 dark:text-gray-400">{{ row.label }}</dt>
                                <dd class="text-sm" :class="[row.mono ? 'font-mono text-xs' : '', row.multiline ? 'whitespace-pre-line' : '']">
                                    <span v-if="row.value">{{ row.value }}</span>
                                    <span v-else class="text-gray-500 dark:text-gray-400">{{ t('none') }}</span>
                                </dd>
                            </div>
                        </dl>
                    </Card>
                </Panel>

                <!-- Karte -->
                <Panel :heading="t('detail_section_card')">
                    <Card>
                        <dl class="divide-y divide-content-border">
                            <div v-for="row in cardRows" :key="row.label" class="flex items-baseline justify-between gap-4 py-2">
                                <dt class="text-sm text-gray-600 dark:text-gray-400">{{ row.label }}</dt>
                                <dd class="text-end text-sm" :class="row.mono ? 'font-mono text-xs' : ''">
                                    <span v-if="row.value">{{ row.value }}</span>
                                    <span v-else class="text-gray-500 dark:text-gray-400">{{ t('none') }}</span>
                                </dd>
                            </div>
                        </dl>
                    </Card>
                </Panel>

                <!-- Einwilligung (§ 356 Abs. 5 BGB) -->
                <Panel :heading="t('detail_section_consent')">
                    <Card>
                        <dl class="divide-y divide-content-border">
                            <div v-for="row in consentRows" :key="row.label" class="flex items-baseline justify-between gap-4 py-2">
                                <dt class="text-sm text-gray-600 dark:text-gray-400">{{ row.label }}</dt>
                                <dd class="text-end text-sm" :class="row.mono ? 'font-mono text-xs' : 'tabular-nums'">
                                    <date-time v-if="row.date && row.value" :of="row.value" />
                                    <span v-else-if="row.value">{{ row.value }}</span>
                                    <span v-else class="text-gray-500 dark:text-gray-400">{{ t('none') }}</span>
                                </dd>
                            </div>
                        </dl>
                        <blockquote v-if="p.consent.text" class="mt-3 border-s-2 border-content-border ps-3 text-xs text-gray-600 dark:text-gray-400">{{ p.consent.text }}</blockquote>
                        <p v-else class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ t('detail_consent_none') }}</p>
                    </Card>
                </Panel>

                <!-- Zugangsfenster -->
                <Panel :heading="t('detail_section_access')">
                    <Card>
                        <dl v-if="accessRows.length" class="divide-y divide-content-border">
                            <div v-for="row in accessRows" :key="row.label" class="flex items-baseline justify-between gap-4 py-2">
                                <dt class="text-sm text-gray-600 dark:text-gray-400">{{ row.label }}</dt>
                                <dd class="text-end text-sm tabular-nums">
                                    <date-time v-if="row.date && row.value" :of="row.value" />
                                    <span v-else-if="row.value !== null && row.value !== undefined">{{ row.value }}</span>
                                    <span v-else class="text-gray-500 dark:text-gray-400">{{ t('none') }}</span>
                                </dd>
                            </div>
                        </dl>
                        <p v-else class="text-sm text-gray-500 dark:text-gray-400">{{ t('detail_access_none') }}</p>
                    </Card>
                </Panel>

                <!-- Herkunft -->
                <Panel :heading="t('detail_section_origin')">
                    <Card>
                        <dl v-if="hasOrigin" class="divide-y divide-content-border">
                            <template v-for="row in originRows" :key="row.label">
                                <div v-if="row.value" class="py-2">
                                    <dt class="text-xs text-gray-600 dark:text-gray-400">{{ row.label }}</dt>
                                    <dd class="break-all text-sm" :class="row.mono ? 'font-mono text-xs' : ''">{{ row.value }}</dd>
                                </div>
                            </template>
                        </dl>
                        <p v-else class="text-sm text-gray-500 dark:text-gray-400">{{ t('detail_origin_none') }}</p>
                    </Card>
                </Panel>

                <!-- Erstattungen -->
                <Panel :heading="t('detail_section_refunds')">
                    <Card>
                        <dl v-if="p.refunds.amount" class="divide-y divide-content-border">
                            <div v-for="row in refundRows" :key="row.label" class="flex items-baseline justify-between gap-4 py-2">
                                <dt class="text-sm text-gray-600 dark:text-gray-400">{{ row.label }}</dt>
                                <dd class="text-end text-sm" :class="row.mono ? 'font-mono text-xs' : 'tabular-nums'">
                                    <date-time v-if="row.date && row.value" :of="row.value" />
                                    <span v-else-if="row.value">{{ row.value }}</span>
                                    <span v-else class="text-gray-500 dark:text-gray-400">{{ t('none') }}</span>
                                </dd>
                            </div>
                        </dl>
                        <p v-else class="text-sm text-gray-500 dark:text-gray-400">{{ t('detail_refunds_none') }}</p>
                    </Card>
                </Panel>
            </div>
        </div>

        <DocsCallout
            :topic="__('statamic-payments::messages.utility_title')"
            url="https://github.com/goldnead/statamic-payments#readme"
        />
    </div>
</template>
