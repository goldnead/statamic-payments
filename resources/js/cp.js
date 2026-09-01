/**
 * Control Panel entry.
 *
 * The registered name must match what the controller passes to
 * `Inertia::render()`, exactly.
 */

import PaymentsIndex from './pages/Payments/Index.vue';
import PaymentsShow from './pages/Payments/Show.vue';
import SubscriptionsIndex from './pages/Subscriptions/Index.vue';
import WithdrawalsIndex from './pages/Withdrawals/Index.vue';
import CancellationsIndex from './pages/Cancellations/Index.vue';

Statamic.booting(() => {
    Statamic.$inertia.register('statamic-payments::Payments/Index', PaymentsIndex);
    Statamic.$inertia.register('statamic-payments::Payments/Show', PaymentsShow);
    Statamic.$inertia.register('statamic-payments::Subscriptions/Index', SubscriptionsIndex);
    Statamic.$inertia.register('statamic-payments::Withdrawals/Index', WithdrawalsIndex);
    Statamic.$inertia.register('statamic-payments::Cancellations/Index', CancellationsIndex);
});
