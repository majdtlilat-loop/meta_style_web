<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Kernel\Http\ApiResponse;
use App\Modules\Sales\Application\PublicInvoice;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A customer's digital invoice, as JSON — for a future customer app.
 *
 * Unauthenticated. The center comes from its public key via `public.tenant`;
 * the invoice from a 256-bit token, and nothing else. Unknown, malformed and
 * revoked tokens are all the same 404 (docs/18-SALES.md §28).
 */
final class PublicInvoiceController extends Controller
{
    public function __invoke(string $center, string $token, PublicInvoice $invoices): JsonResponse
    {
        unset($center);

        $document = $invoices->forToken($token);

        if ($document === null) {
            throw new NotFoundHttpException;
        }

        return ApiResponse::data(['invoice' => $document]);
    }
}
