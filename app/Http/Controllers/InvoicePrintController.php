<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Kernel\Identity\Models\User;
use App\Modules\Sales\Application\InvoiceRenderer;
use App\Modules\Sales\Application\SalesAccess;
use App\Modules\Sales\Application\SalesQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A printable invoice page — 80mm or A4 — for the browser's print dialog.
 *
 * Its own page rather than a modal, because the print dialog needs a document.
 * `printing` + `invoice.print` are checked by `SalesAccess`; the layout reads
 * only the renderer's allow-listed view model (docs/18-SALES.md §24).
 */
final class InvoicePrintController extends Controller
{
    public function __invoke(Request $request, string $uuid, string $format, SalesQuery $query, SalesAccess $access, InvoiceRenderer $renderer): View
    {
        if (! in_array($format, ['80mm', 'a4'], true)) {
            throw new NotFoundHttpException;
        }

        /** @var User $user */
        $user = $request->user();

        try {
            [$invoice, $sale] = $query->invoice($uuid, $user);

            $access->ensurePrinting($user, $invoice->branch_id);
        } catch (EntitlementRequired) {
            // A staff page, so a plain 403 rather than a server error: the
            // center does not own the paper surfaces, and the digital invoice
            // is still one click away (§23).
            throw new AccessDeniedHttpException('Printing is not included for this center.');
        }

        return view($format === 'a4' ? 'sales.invoice-a4' : 'sales.invoice-80mm', [
            'invoice' => $renderer->document($invoice, null, $sale->status, $sale->voided_at?->toImmutable()),
        ]);
    }
}
