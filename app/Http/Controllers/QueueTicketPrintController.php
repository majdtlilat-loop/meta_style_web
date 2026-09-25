<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Kernel\Identity\Models\User;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Modules\Printing\Application\PrintAppearance;
use App\Modules\Queue\Application\TicketPrinter;
use App\Modules\Queue\Domain\Models\QueueTicket;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The printable ticket: one small page, sized for 80mm paper.
 *
 * ## CSS, not a printer driver
 *
 * `@page { size: 80mm auto }` and a browser print dialog. No ESC/POS, no device
 * credentials, no print agent, no spooler — none of which this product needs
 * yet, and all of which would be infrastructure to run from the day it shipped
 * (docs/17-QUEUE.md §17).
 *
 * The seam is `TicketPrinter`, which builds the payload. A thermal-printer
 * renderer, when a center actually needs one, consumes the same payload.
 *
 * ## Auto-print is a request, not a guarantee
 *
 * Browsers refuse to print without a user gesture unless they are running in
 * kiosk mode. The page therefore CALLS `window.print()` and the desk presses
 * the button when the browser declines. Documented rather than worked around:
 * the workarounds are all either an extension or a local agent (§17).
 */
final class QueueTicketPrintController extends Controller
{
    /**
     * `$center` is the host's `{center}` segment: route parameters reach a
     * controller by POSITION, so it is taken first (otherwise the slug lands
     * in `$uuid` and the ticket is never found). Tenancy already resolved the
     * center from that host; the value itself is not used.
     */
    public function __invoke(Request $request, string $center, string $uuid, TicketPrinter $printer, PrintAppearance $appearance, TenantContext $tenants): View
    {
        unset($center);

        /** @var User $user */
        $user = $request->user();

        $ticket = QueueTicket::query()
            ->with(['branch', 'department', 'servicePoint', 'stage'])
            ->where('uuid', $uuid)
            ->first();

        // A ticket at another branch is NOT FOUND, like every other cross-scope
        // read in this product (docs/08-AUDIT-SECURITY.md).
        if (! $ticket instanceof QueueTicket || ! $user->canAccessBranch((int) $ticket->branch_id)) {
            throw new NotFoundHttpException;
        }

        // The center's print appearance, applied at render time: the paper's
        // language, its logo and closing line, and which optional lines show.
        $locale = $appearance->documentLocale(app()->getLocale());
        app()->setLocale($locale);
        $print = $appearance->view($locale);

        // The Action checks the entitlement and `queue.ticket.print`; this
        // controller does not repeat either.
        return view('queue.ticket', [
            'ticket' => $appearance->ticket($printer->payload($ticket, $user, $locale), $print),
            'print' => $print,
            'centerName' => $tenants->require()->name,
        ]);
    }
}
