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
     * The assistant, booking for a customer it reached over a verified channel.
     *
     * A FOURTH kind of actor, and it needed to be one rather than being dressed
     * up as an existing one. It is not `staff` — nobody at the center pressed
     * anything, and recording it as staff would tell an investigation a person
     * made this booking. It is not `customer` either: that factory needs a
     * `CustomerAccount`, and a WhatsApp customer may well have no login at all
     * (docs/27-RAYAN.md §12).
     *
     * `$label` is the channel, never a customer's name or number: it lands in
     * `appointments.created_by_label`, which is displayed on the calendar.
     */
    public static function assistant(string $label = 'rayan'): self
    {
        return new self(ActorType::Ai, BookingSource::Rayan, null, $label);
    }

    public function isAssistant(): bool
    {
        return $this->type === ActorType::Ai;
    }

    /**
     * Should this actor see only what a CUSTOMER is allowed to see — public
     * branches, online-bookable services?
     *
     * True for a guest and for the assistant. The assistant talks to customers,
     * so it must never be able to offer an internal-only branch or a service
     * the center deliberately keeps off its public menu — and a model asked
     * "what else do you do?" would happily read out whatever it was given
     * (docs/13-ROADMAP.md Phase 13 §29).
     */
    public function usesPublicCatalog(): bool
    {
        return $this->isGuest() || $this->isAssistant();
    }

    /**
     * The audit trail's view of the same actor.
     */
    public function toAuditActor(): Actor
    {
        return new Actor($this->type, $this->source->auditSource(), $this->id, $this->label);
    }
}
