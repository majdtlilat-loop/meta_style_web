<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\TenantLocales;
use App\Kernel\Notes\Models\InternalNote;
use App\Kernel\Notes\NoteOwner;
use App\Kernel\Privacy\ContactMasker;
use App\Modules\Customers\Domain\Models\Customer;
use App\Modules\Customers\Domain\Models\CustomerTag;

/**
 * The one shape a customer is presented in, to any surface.
 *
 * THE API, THE LIVEWIRE SCREENS AND ANY FUTURE MOBILE CLIENT ALL CALL THIS.
 * That is the entire point: masking a phone number in a Blade template still
 * sends the real value to the browser, still puts it in the JSON a mobile app
 * receives, and still leaves it in whatever export reuses the query. One
 * presenter, taking the viewing user, is the only version of this control that
 * actually holds (docs/06-AUTH-ROLES-PERMISSIONS.md §6).
 *
 * Fields are named explicitly rather than dumped and filtered. A deny-list is
 * defeated by the next column somebody adds; an allow-list is not.
 */
final class CustomerPresenter
{
    public function __construct(
        private readonly ContactMasker $masker,
        private readonly TenantLocales $locales,
    ) {}

    /**
     * The list shape: enough to find someone, no more.
     *
     * @return array<string, mixed>
     */
    public function summary(Customer $customer, ?User $viewer): array
    {
        $full = $this->masker->allowsFull($viewer);

        return [
            'uuid' => $customer->uuid,
            'name' => $customer->name,
            'phone' => $this->masker->phone($customer->phone, $full),
            'email' => $this->masker->email($customer->email, $full),
            // Tells a client the value exists but is hidden, which is honest;
            // a masked value that looked real would not be.
            'contact_masked' => ! $full,
            'is_registered' => $customer->isRegistered(),
            'is_archived' => $customer->isArchived(),
            'source' => $customer->source->value,
            'tags' => $customer->relationLoaded('tags')
                ? $customer->tags->map(fn (CustomerTag $t): array => [
                    'uuid' => $t->uuid,
                    'name' => $t->name->get(),
                ])->values()->all()
                : [],
            'created_at' => $customer->created_at?->toIso8601String(),
        ];
    }

    /**
     * The profile shape.
     *
     * @return array<string, mixed>
     */
    public function detail(Customer $customer, ?User $viewer): array
    {
        $full = $this->masker->allowsFull($viewer);

        return array_merge($this->summary($customer, $viewer), [
            'phone_display' => $full ? $customer->phone_display : null,
            'preferred_locale' => $this->resolvedLocale($customer),
            'date_of_birth' => $customer->date_of_birth?->toDateString(),

            'preferences' => [
                'allow_operational_messages' => $customer->allow_operational_messages,
                'marketing_opt_in' => $customer->marketing_opt_in,
                'marketing_opt_in_at' => $customer->marketing_opt_in_at?->toIso8601String(),
            ],

            'account' => $this->account($customer),

            'notes' => $this->notes($customer, $viewer),

            /*
             * Deliberately absent: bookings, visits, sales, loyalty, packages,
             * reviews. Those modules do not exist, and a widget rendering an
             * empty box promising one is worse than its absence — it makes the
             * product look broken rather than unbuilt
             * (docs/13-ROADMAP.md Phase 5 §17).
             */
        ]);
    }

    /**
     * Account status only. Never a credential, never a token.
     *
     * @return array<string, mixed>|null
     */
    private function account(Customer $customer): ?array
    {
        $account = $customer->relationLoaded('account') ? $customer->account : $customer->account()->first();

        if ($account === null) {
            return null;
        }

        return [
            'uuid' => $account->uuid,
            'is_active' => $account->is_active,
            // Always false in Phase 5, and honestly so — there is no
            // verification provider yet (ADR-040).
            'phone_verified' => $account->hasVerifiedPhone(),
            'last_login_at' => $account->last_login_at?->toIso8601String(),
            'created_at' => $account->created_at?->toIso8601String(),
        ];
    }

    /**
     * Notes the viewer is allowed to read, filtered by visibility.
     *
     * @return list<array<string, mixed>>
     */
    private function notes(Customer $customer, ?User $viewer): array
    {
        if ($viewer === null || ! $viewer->hasPermission(Permission::CustomerNoteView)) {
            // Omitted entirely, not returned empty: an empty list would imply
            // there are none.
            return [];
        }

        $notes = $customer->relationLoaded('internalNotes')
            ? $customer->internalNotes
            : $customer->internalNotes()->get();

        return $notes
            ->filter(fn (InternalNote $note): bool => $this->canRead($note, $viewer))
            ->map(fn (InternalNote $note): array => [
                'uuid' => $note->uuid,
                'body' => $note->body,
                'visibility' => $note->visibility->value,
                'created_at' => $note->created_at?->toIso8601String(),
            ])
            ->values()->all();
    }

    private function canRead(InternalNote $note, User $viewer): bool
    {
        return $viewer->hasPermission($note->visibility->requiredPermission(NoteOwner::Customer));
    }

    /**
     * A customer's language, if the center still offers it.
     *
     * A center that disables Kurdish must not strand a customer who chose it;
     * the normal tenant fallback applies (docs/07-LOCALIZATION.md §5).
     */
    private function resolvedLocale(Customer $customer): string
    {
        return $this->locales->resolve($customer->preferred_locale);
    }
}
