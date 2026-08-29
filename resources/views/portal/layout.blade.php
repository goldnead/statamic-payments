<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="referrer" content="no-referrer">
    <title>@yield('title')</title>
    <style>
        /* No build step and no asset pipeline. This page is opened from a mail
           client by somebody who wants to see what they paid for or stop paying
           for it; it has to render on the first byte, everywhere, forever.

           The palette is `statamic-preference-center`'s, to the value. The two
           packages send mail to the same people and are reached the same way,
           and a buyer who follows one link and then the other should not be able
           to tell that two different addons drew the pages. */
        body { margin: 0; padding: 0; background: #f4f4f5; font-family: -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #18181b; }
        .card { max-width: 560px; margin: 8vh auto; background: #fff; border-radius: 12px; padding: 40px 32px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
        h1 { font-size: 22px; margin: 0 0 8px; }
        .lede { color: #52525b; line-height: 1.6; margin: 0 0 4px; font-size: 15px; }
        .muted { font-size: 14px; color: #71717a; margin: 0 0 4px; }

        .block { margin-top: 32px; }
        .block h2 { font-size: 13px; text-transform: uppercase; letter-spacing: .04em; color: #71717a; margin: 0 0 4px; }
        .block .hint { font-size: 13px; color: #a1a1aa; line-height: 1.5; margin: 0 0 10px; }

        .list { list-style: none; margin: 0; padding: 0; border-top: 1px solid #e4e4e7; }
        /* The entry is the record; the row inside it is the one line that has an
           amount on the right. Actions live in the entry and outside the row, or
           they get squeezed into a flex column with the price. */
        .entry { border-bottom: 1px solid #e4e4e7; padding: 12px 2px; }
        .row { display: flex; gap: 12px; align-items: baseline; justify-content: space-between; }
        .row .what { flex: 1; min-width: 0; }
        .amount .desc { text-align: right; }
        .actions { margin-top: 4px; }
        .actions .btn { margin-top: 10px; }
        .actions .hint { margin: 6px 0 0; text-align: center; }
        .name { font-size: 15px; color: #18181b; }
        .desc { display: block; font-size: 13px; line-height: 1.5; color: #71717a; margin: 4px 0 0; }
        .amount { font-size: 15px; color: #18181b; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .quiet { color: #a1a1aa; }

        /* The order's own lines. A table, because it is one. */
        table.lines { width: 100%; border-collapse: collapse; font-size: 14px; }
        table.lines th { text-align: left; font-weight: 500; color: #71717a; font-size: 12px; text-transform: uppercase; letter-spacing: .04em; padding: 0 0 8px; border-bottom: 1px solid #e4e4e7; }
        table.lines th.num, table.lines td.num { text-align: right; font-variant-numeric: tabular-nums; }
        table.lines td { padding: 11px 0; border-bottom: 1px solid #e4e4e7; vertical-align: top; }
        table.lines tr.total td { font-weight: 600; border-bottom: 0; }

        .notice { background: #f4f4f5; border-radius: 8px; padding: 10px 12px; font-size: 14px; color: #3f3f46; margin: 0 0 16px; line-height: 1.5; }
        .warn { background: #fffbeb; border-radius: 8px; padding: 10px 12px; font-size: 14px; color: #854d0e; line-height: 1.5; margin: 16px 0 0; }
        .errors { margin: 0 0 16px; padding: 10px 12px; background: #fef2f2; border-radius: 8px; color: #b91c1c; font-size: 14px; line-height: 1.5; }

        .btn { margin-top: 22px; width: 100%; padding: 11px 16px; border: 0; border-radius: 8px; background: #18181b; color: #fff; font-size: 15px; cursor: pointer; font-family: inherit; text-align: center; text-decoration: none; display: block; box-sizing: border-box; }
        .btn-quiet { background: transparent; color: #b91c1c; border: 1px solid #e4e4e7; }
        .btn-plain { background: transparent; color: #18181b; border: 1px solid #e4e4e7; }
        input[type=email] { width: 100%; box-sizing: border-box; padding: 11px 12px; border: 1px solid #d4d4d8; border-radius: 8px; font-size: 15px; font-family: inherit; }
        a { color: #18181b; }
        .foot { margin-top: 28px; font-size: 12px; color: #a1a1aa; line-height: 1.6; }
        .foot form { display: inline; }
        .foot button { background: none; border: 0; padding: 0; font: inherit; color: #a1a1aa; text-decoration: underline; cursor: pointer; }
    </style>
</head>
<body>
    <div class="card">
        @if (session('statamic-payments.portal.error'))
            <p class="errors" role="alert">{{ session('statamic-payments.portal.error') }}</p>
        @endif

        @if (session('statamic-payments.portal.status'))
            {{--
                The same sentence for every outcome of a link request. Sent, no
                such buyer, throttled — this page cannot be used to find out
                which, and neither can a stopwatch: the controller holds the
                response to a floor.
            --}}
            <p class="notice" role="status">{{ session('statamic-payments.portal.status') }}</p>
        @endif

        @yield('content')
    </div>
</body>
</html>
