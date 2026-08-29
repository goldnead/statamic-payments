<?php

namespace Goldnead\StatamicPayments\Http\Controllers\Portal;

use Goldnead\StatamicPayments\Support\Invoices;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The invoice, handed to the person it was made out to.
 *
 * The document is served by this package rather than linked to somewhere in the
 * invoicing addon, and that is the point of the whole seam. The buyer has no
 * account and no control panel session; the only thing that says they may have
 * this document is the portal note this route sits behind. A link out to another
 * addon's URL would have to reproduce that check, and would reproduce it wrongly.
 *
 * `Content-Disposition: attachment` rather than inline, whatever the content
 * type. An invoice is a thing to keep.
 */
class InvoiceController extends PortalController
{
    public function __invoke(Request $request, string $payOrder)
    {
        $access = $this->access($request);

        if ($access === null) {
            return $this->askForALink();
        }

        $payment = $this->orders->orderFor($access, (int) $payOrder);

        abort_if($payment === null, 404);

        $document = Invoices::forPayment($payment);

        // 404 and not a friendlier error. "There is no invoice for this order"
        // and "no invoicing addon is installed" are the same answer from here,
        // and the order page has already told the buyer which by not offering a
        // button. Somebody who reached this URL anyway typed it.
        abort_if($document === null, 404);

        return response($document->bytes(), Response::HTTP_OK, [
            'Content-Type' => $document->contentType,
            'Content-Disposition' => 'attachment; filename="'.$document->filename.'"',
            // The document is somebody's invoice. Nothing between here and their
            // browser has any business keeping a copy.
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
