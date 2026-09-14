<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Modules\Sales\Application\PublicInvoice;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The customer's digital invoice page. Read-only, responsive, printable, with no
 * login and no controls (docs/18-SALES.md §46).
 *
 * Resolved from the center's public key (`public.tenant`) and the invoice's
 * 256-bit share token. Nothing on the page links anywhere internal.
 */
final class PublicInvoicePageController extends Controller
{
    public function __invoke(string $center, string $token, PublicInvoice $invoices): View
    {
        unset($center);

        $document = $invoices->forToken($token);

        if ($document === null) {
            throw new NotFoundHttpException;
        }

        return view('sales.invoice-public', ['invoice' => $document]);
    }
}
