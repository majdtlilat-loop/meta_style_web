<?php

declare(strict_types=1);

namespace App\Modules\Booking\Domain\Data;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Enums\ActorType;
use App\Kernel\Identity\Models\User;
use App\Modules\Booking\Domain\Enums\BookingSource;
use App\Modules\Customers\Domain\Models\CustomerAccount;

/**
 * Who is asking the Booking Engine to do something, and through which channel.
 *
 * THE THING THAT MAKES ONE ENGINE POSSIBLE. Reception, a guest on the public
 * menu, a signed-in customer, the WhatsApp bot and RAYAN all call the same
 * Actions; what differs between them is this object. The engine authorises
 * against it, records it, and stamps the appointment with it — so a future
 * channel is an adapter that constructs one of these, not a second code path
 * with its own idea of the rules (docs/04-MODULE-BOUNDARIES.md §4.1).
 *
 * THE SOURCE IS SET BY THE ADAPTER, NEVER BY THE CALLER'S PAYLOAD. `staff` is a
 * privileged claim — it is what tells an investigation a member of staff made
 * this booking — so a public endpoint constructs `guest()` and there is no path
 * by which a request body can change that (§15).
 */
final readonly class BookingActor
{
    private function __construct(
        public ActorType $type,
        public BookingSource $source,
        public ?string $id,
        public string $label,
        /** Present only for staff; carries the permissions and branch scope. */
        public ?User $user = null,
        /** Present only for a signed-in customer. */
        public ?CustomerAccount $account = null,
    ) {}

    public static function staff(User $user, BookingSource $source = BookingSource::Staff): self
    {
        return new self(ActorType::Staff, $source, $user->uuid, $user->name, $user);
    }

    /**
     * A signed-in customer acting for themselves.
     */
    public static function customer(CustomerAccount $account, string $label): self
    {
        return new self(
            ActorType::Customer,
            BookingSource::CustomerAccount,
            $account->uuid,
            $label,
            null,
            $account,
        );
    }

    /**
     * An unauthenticated visitor on the public menu.
     *
     * Carries no id, because there is nothing to identify — and deliberately no
     * customer reference either. The customer is resolved from the phone number
     * inside the engine, so a guest cannot claim to be an existing customer by
     * supplying one (§16).
     */
    public static function guest(): self
    {
        return new self(ActorType::Guest, BookingSource::PublicWeb, null, 'guest');
    }

    public function isStaff(): bool
    {
        return $this->type === ActorType::Staff;
    }

    public function isCustomer(): bool
    {
        return $this->type === ActorType::Customer;
    }

    public function isGuest(): bool
    {
        return $this->type === ActorType::Guest;
    }

    /**
     * The audit trail's view of the same actor.
     */
    public function toAuditActor(): Actor
    {
        return new Actor($this->type, $this->source->auditSource(), $this->id, $this->label);
    }
}
