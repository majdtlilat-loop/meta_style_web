<?php

declare(strict_types=1);

namespace App\Modules\Queue\Application;

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\LanguageRegistry;
use App\Kernel\Time\BranchClock;
use App\Modules\Queue\Domain\Models\QueueTicket;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The paper a customer walks away with.
 *
 * ## A render seam, not a print platform
 *
 * This builds a PAYLOAD. A Blade view turns it into an 80mm page and the
 * browser prints it. There is no ESC/POS, no printer worker, no device
 * credential, no spool and no driver — none of which this product needs yet,
 * and all of which would be infrastructure to operate from the first day it
 * existed (docs/17-QUEUE.md §17).
 *
 * When a center eventually needs a thermal printer driven directly, the seam is
 * here: another renderer consuming the same payload. That is the whole reason
 * the payload is separate from the view.
 *
 * ## No customer PII by default
 *
 * A queue ticket is left on tables, dropped on floors and handed to whoever is
 * next. It carries a number, a place and a time. Not a name, not a phone
 * number, not what the customer is here for unless the center asks for it
 * (§17).
 *
 * ## Direction comes from the language registry
 *
 * Arabic and Kurdish print right-to-left, English left-to-right, and the
 * template is the same one — nothing hardcodes a list of RTL locales
 * (docs/07-LOCALIZATION.md §10).
 */
final class TicketPrinter
{
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly LanguageRegistry $languages,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws AuthorizationException
     */
    public function payload(QueueTicket $ticket, User $actingUser, ?string $locale = null): array
    {
        $this->entitlements->ensure('queue_management');

        if (! $actingUser->hasPermission(Permission::QueueTicketPrint)) {
            throw new AuthorizationException('You may not print queue tickets.');
        }

        if (! $actingUser->canAccessBranch((int) $ticket->branch_id)) {
            throw new AuthorizationException('You may not work in that branch.');
        }

        $ticket->loadMissing(['branch', 'department', 'servicePoint', 'stage']);

        $locale ??= app()->getLocale();
        $branch = $ticket->branch;
        $timezone = $branch->timezone ?? 'UTC';

        return [
            'number' => $ticket->display_number,
            'branch_name' => $branch?->name?->get($locale),
            'department_name' => $ticket->department?->name->get($locale),
            'destination_code' => $ticket->servicePoint?->display_code,
            'destination_name' => $ticket->servicePoint?->name->get($locale),

            /*
             * The center's OWN wall clock. A ticket printed in Baghdad that
             * showed a UTC time would be an hour out of every conversation the
             * customer has about it (CLAUDE.md).
             */
            'issued_at' => BranchClock::toLocal($ticket->issued_at->toImmutable()->utc(), $timezone)->format('H:i'),
            'issued_date' => BranchClock::localDate($ticket->issued_at->toImmutable()->utc(), $timezone),

            /*
             * Optional and unnamed: the SERVICE, not the customer. A center that
             * wants "Haircut" on the paper gets it; nobody gets a name or a
             * phone number.
             */
            'service_name' => $ticket->stage?->serviceName()?->get($locale),

            'locale' => $locale,
            'direction' => $this->languages->direction($locale),
        ];
    }
}
