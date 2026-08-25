# Statamic Payments — Marketplace

## Price

**$49, one edition.** Decided by Adrian on 2026-08-24.

One product, no Core/Pro split, so `extra.statamic.editions` stays absent from `composer.json`.

### Why above the cookie addon at $39

**It handles money.** That is the whole argument. A cookie banner that fails shows the wrong text; a
payment integration that fails takes somebody's €19 and delivers nothing, and neither side notices
until a support mail arrives. The two properties this addon is built around — the caller is never
believed, and fulfilment runs exactly once — are the ones that cost a working weekend to get right
and are invisible when they work.

### What it does that the alternatives do not

| Way to do it | Amount can be tampered with | Fulfilment on redelivery | Cost |
|---|---|---|---|
| **Statamic Payments** | **no, the catalogue decides** | **once, claimed in the database** | **$49** |
| Mollie payment links | no | you do it by hand | free |
| A hand-built webhook | depends on who built it | usually a read-then-write, which loses the race | a day or two |
| A full shop package | no | yes | far more addon than a site selling four things needs |

The honest comparison is the last two rows. A developer can build this in a day; what is bought is
the day plus the failure modes that only show up in production — a duplicated delivery, a listener
that throws halfway, a payment whose id never reached the database because the process died between
two lines.

It is deliberately not a shop. No cart, no stock, no tax table. A site selling four things does not
need those, and a site that does need them is not the customer for this addon.

## Editions

One. See above.

## Support

Latest version only. <https://github.com/goldnead/statamic-payments/issues>
