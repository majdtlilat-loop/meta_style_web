<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Kernel\Identity\Models\User;
use App\Modules\Printing\Application\PrintAppearance;
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
 *
 * The center's print appearance (logo, header lines, footer, which details to
 * show, the document language) is applied HERE, at render time, as a separate
 * `$print` array and a filtered copy of the view model. The invoice itself is
 * immutable and is never written.
 */
final class InvoicePrintController extends Controller
{
    /**
     * `$center` is the host's `{center}` segment. Route parameters reach a
     * controller by POSITION, so it has to be taken first — without it the
     * slug landed in `$uuid` and every print answered 404. Tenancy already
     * resolved the center from that host; the value itself is not used.
     */
    public function __invoke(Request $request, string $center, string $uuid, string $format, SalesQuery $query, SalesAccess $access, InvoiceRenderer $renderer, PrintAppearance $appearance): View
    {
        unset($center);

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

        // The paper's language: the person printing's, or the center's primary
        // content language if the center chose that. The labels follow it too.
        $locale = $appearance->documentLocale(app()->getLocale());
        app()->setLocale($locale);

        $print = $appearance->view($locale);

        return view($format === 'a4' ? 'sales.invoice-a4' : 'sales.invoice-80mm', [
            'invoice' => $appearance->invoice($renderer->document($invoice, $locale, $sale->status, $sale->voided_at?->toImmutable()), $print),
            'print' => $print,
        ]);
    }
}
