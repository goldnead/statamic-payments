<?php

return [

    // Labels for the settings screen. The screen itself belongs to
    // statamic-brand-context; this addon supplies only the field list
    // (Support\Settings) and the words for it.

    'permission_manage_settings' => 'Manage payment settings',

    'groups' => [

        'checkout' => [
            'title' => 'Checkout',
            'description' => 'What applies to every purchase. The catalogue itself (`products`) is a nested map and stays in config/statamic-payments.php; so does `methods`, because what the config holds there is what comes out of the .env — a comma-separated string, not a list. The Mollie key does not belong here and never will: it stays in the .env so it is not in every database backup.',
        ],

        'withdrawal' => [
            'title' => 'Withdrawal button, § 356a BGB',
            'description' => 'Since 19 June 2026 a shop concluding distance contracts with consumers has to offer an electronic withdrawal function. The addon ships the shape; the two details only you have — where a withdrawal is reported to and where your withdrawal instruction lives — are here. The route prefix and the throttle stay in config/statamic-payments.php: they are read while the routes are being registered, so a change made here would only take effect after the next deploy.',
        ],

        'cancellation' => [
            'title' => 'Cancellation button, § 312k BGB',
            'description' => 'The second, login-free way to cancel a running contract. Same split as for withdrawal: reporting address and policy here, route prefix and throttle in the config file.',
        ],

        'portal' => [
            'title' => 'Customer portal',
            'description' => 'The buyer\'s own screens, reached through a signed link to the address on the order — no account, no password. Prefix, middleware and the three throttles stay in config/statamic-payments.php: the first two are read while routes are registered, and the throttles are partly nested and partly the same thing. `min_response_ms` stays there too — it holds the response to a minimum duration so it cannot reveal whether an address has ever bought here, and a control whose only effect would be to lower that is not an operator value.',
        ],

        'legal' => [
            'title' => 'Receipts and consent',
            'description' => 'What an acknowledgement states, and which consent sentences count as genuine.',
        ],

        'abandoned' => [
            'title' => 'Abandoned checkouts',
            'description' => 'Somebody started a checkout and did not finish it. Whether a reminder may go out is a question of consent — the address was given to complete a purchase, not to receive advertising. Settle that before switching anything on here.',
        ],

        'bridges' => [
            'title' => 'Connected addons',
            'description' => 'What this addon hands to its siblings. All off until somebody turns them on: two addons installed for unrelated reasons must not start exchanging customer data because they happen to sit in the same vendor directory.',
        ],

    ],

    'fields' => [

        'currency' => [
            'label' => 'Currency',
            'description' => 'Three-letter code, e.g. EUR.',
        ],
        'return_url' => [
            'label' => 'Return after payment',
            'description' => 'Where the provider sends the buyer after paying. It is not where fulfilment happens: a buyer who closes the tab still paid, and a buyer who reaches this page has not necessarily paid. Only the webhook decides.',
        ],
        'max_quantity' => [
            'label' => 'Largest quantity per purchase',
            'description' => 'A safety net, not a business rule. The quantity is the one number a checkout accepts from a request — the unit price never is — so a mistyped or hostile figure must not become a five-figure charge. Products with their own quantity range win over this.',
        ],
        'prune_unpaid_after_days' => [
            'label' => 'Delete unpaid checkouts after (days)',
            'description' => 'Not tidiness: what sits in the row is the name and address of somebody with whom no contract was ever concluded. 0 switches it off. Pick a number that fits how long your reminder sequences run.',
        ],

        'withdrawal_enabled' => [
            'label' => 'Offer the withdrawal function',
            'description' => 'On, because an addon that ships a statutory requirement switched off ships it to nobody. B2B-only shops may turn it off.',
        ],
        'withdrawal_notify' => [
            'label' => 'Reporting address',
            'description' => 'Where your copy of a withdrawal goes, with the matched payment and the hints. Empty uses the portal sender address and after that the application\'s own. The consumer\'s acknowledgement goes out either way.',
        ],
        'withdrawal_policy_url' => [
            'label' => 'Withdrawal instruction (URL)',
            'description' => 'Where your instruction lives. The form page links to it; the instruction itself is your document, not the addon\'s.',
        ],
        'withdrawal_days' => [
            'label' => 'Withdrawal period (days)',
            'description' => 'Used only to tell you whether a declaration arrived inside the period. The consumer is never refused on the strength of this number — whether the period has run is a legal question the row cannot settle by itself.',
        ],

        'cancellation_enabled' => [
            'label' => 'Offer the cancellation function',
            'description' => 'The login-free route under § 312k BGB. The portal\'s cancellation stays as the convenient way for somebody already looking at their contract.',
        ],
        'cancellation_notify' => [
            'label' => 'Reporting address',
            'description' => 'Where your copy of a cancellation goes. Empty behaves as it does for withdrawals.',
        ],
        'cancellation_policy_url' => [
            'label' => 'Cancellation notes (URL)',
            'description' => 'A page of yours explaining notice periods and the like. Linked from the form when set.',
        ],

        'portal_enabled' => [
            'label' => 'Offer the customer portal',
            'description' => 'Orders, invoice, cancellation and card change for the buyer.',
        ],
        'portal_link_ttl_minutes' => [
            'label' => 'Link validity (minutes)',
            'description' => 'This is the whole of the revocation story — there is no token table to revoke against — so shorten it rather than lengthen it. Thirty minutes is long enough for a mail to be delivered and read.',
        ],
        'portal_session_minutes' => [
            'label' => 'Visit duration (minutes)',
            'description' => 'Its own clock, independent of the session lifetime set for staff who log in: a buyer on a shared machine is not a member of staff at a desk.',
        ],
        'portal_max_rows' => [
            'label' => 'Maximum rows',
            'description' => 'A ceiling on the page, not a business rule: somebody with four hundred orders should not be sent a four-hundred-row page on a phone.',
        ],
        'portal_mandate_verification_cent' => [
            'label' => 'Card verification amount (cents)',
            'description' => 'This charges real money and there is no way around it: Mollie has no zero-amount authorisation, and a mandate comes only from a payment. One cent is the usual answer for cards; some methods have a higher floor and will refuse. The buyer is shown the amount before the button.',
        ],
        'portal_from_address' => [
            'label' => 'Sender address',
            'description' => 'Who the two portal mails come from. Empty uses the application\'s own, which is right on a single-brand install.',
        ],
        'portal_from_name' => [
            'label' => 'Sender name',
            'description' => '',
        ],
        'portal_ignored_query_parameters' => [
            'label' => 'Query parameters to ignore',
            'description' => 'One per line. Parameters a mail service appends to the link in transit, which the signature check would otherwise trip over — `_se` comes from Brevo\'s click counter, for instance. `expires` and `signature` can never be listed here, whatever is entered.',
        ],

        'legal_timezone' => [
            'label' => 'Timezone on receipts',
            'description' => 'The zone the date and time on an acknowledgement are stated in. Empty uses the application\'s. Set it where the application runs in UTC and the shop does not: the time on a receipt should be the merchant\'s.',
        ],
        'consent_accepted_texts' => [
            'label' => 'Accepted consent sentences',
            'description' => 'One per line, in the exact wording of your own checkout. A submitted consent text is written onto the row only if it is one of these — a hidden field is a field anybody can edit, and a record whose text the buyer chose proves nothing.',
        ],

        'abandoned_enabled' => [
            'label' => 'Announce abandoned checkouts',
            'description' => 'Announces them as an event, once each. A sequence in statamic-automations can pick it up.',
        ],
        'abandoned_after_minutes' => [
            'label' => 'Counts as abandoned after (minutes)',
            'description' => 'In minutes rather than hours, because the line between "still typing" and "gone" is not the same on a nine-euro download as on a course that costs two thousand.',
        ],
        'abandoned_mail_enabled' => [
            'label' => 'Send a reminder',
            'description' => 'Announcing an abandoned checkout and mailing the person are two decisions. The consent question above applies here twice over.',
        ],
        'abandoned_mail_template' => [
            'label' => 'Template',
            'description' => 'A slug from statamic-email-templates. Without the sibling installed, a built-in mail goes out.',
        ],
        'abandoned_mail_subject' => [
            'label' => 'Subject',
            'description' => '',
        ],
        'abandoned_mail_resume_url' => [
            'label' => 'Where the button points',
            'description' => 'Empty builds a signed link that starts the checkout again with the same lines. A URL of your own may carry `{payment}` for the id.',
        ],
        'abandoned_mail_resume_days' => [
            'label' => 'Link validity (days)',
            'description' => '',
        ],

        'follow_up_enabled' => [
            'label' => 'Follow-up offers',
            'description' => 'An offer shown after a payment, charged without asking for card details again. There is more to switching it on than this flag: the mandate below has to be on as well, and the offer page needs its own order button under § 312j BGB. See docs/follow-up-offers.md.',
        ],
        'follow_up_collect_mandate' => [
            'label' => 'Collect a mandate on the first payment',
            'description' => 'Makes the first payment ask the provider to remember the buyer, which is what makes a later charge possible at all. The buyer has to be told so on the checkout page.',
        ],
        'leadhub_enabled' => [
            'label' => 'Report to statamic-leadhub',
            'description' => 'Writes a paid purchase onto the contact\'s timeline and into their lifetime total. What travels: address, name, what they bought, what they paid, and the frozen campaign — a decision about personal data, which is why it is a switch and not a default.',
        ],
        'entitlements_enabled' => [
            'label' => 'Report to statamic-entitlements',
            'description' => 'Gives the buyer the entitlement named under `grants` on the product. Off again for any product without `grants`.',
        ],

    ],

];
