/**
 * Control Panel entry.
 *
 * The registered name must match what the controller passes to
 * `Inertia::render()`, exactly.
 */

import PaymentsIndex from './pages/Payments/Index.vue';
import SubscriptionsIndex from './pages/Subscriptions/Index.vue';

Statamic.booting(() => {
    Statamic.$inertia.register('statamic-payments::Payments/Index', PaymentsIndex);
    Statamic.$inertia.register('statamic-payments::Subscriptions/Index', SubscriptionsIndex);
});
