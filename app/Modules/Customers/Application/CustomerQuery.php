<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application;

use App\Kernel\Authorization\Permission;
use App\Kernel\Contact\PhoneNumber;
use App\Kernel\Identity\Models\User;
use App\Modules\Customers\Domain\Models\Customer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The CRM customer list.
 *
 * ALWAYS PAGINATED. A center with fifteen thousand customers must not be able
 * to load them into memory by opening a screen, and `->get()` on this table is
 * how that happens (docs/13-ROADMAP.md Phase 5 §16).
 *
 * Relations are eager-loaded because the list renders tags and a registered
 * badge for every row — without it, one page is 1 + 3n queries.
 *
 * SEARCHING BY PHONE REQUIRES `customer.contact.view`. Someone who only sees a
 * masked number must not be able to test full numbers against the search box:
 * that would turn the list into an oracle that answers "is this person a
 * customer here" one query at a time, which is exactly what the masking
 * prevents (docs/06-AUTH-ROLES-PERMISSIONS.md §6, rule 4).
 */
final class CustomerQuery
{
    /**
     * @param  array{search?: string|null, archived?: bool|null, registered?: bool|null, tag?: string|null}  $filters
     * @return LengthAwarePaginator<int, Customer>
     */
    public function paginate(array $filters, User $viewer, int $perPage = 25): LengthAwarePaginator
    {
        $query = Customer::query()->with(['tags', 'account'])->orderBy('name')->orderBy('id');

        $this->applySearch($query, $filters['search'] ?? null, $viewer);

        // Active by default: an archived customer is history, and a CRM list
        // that shows them by default makes the live list wrong.
        if (($filters['archived'] ?? false) === true) {
            $query->whereNotNull('archived_at');
        } else {
            $query->whereNull('archived_at');
        }

        if (isset($filters['registered'])) {
            $query->registered($filters['registered']);
        }

        $tag = $filters['tag'] ?? null;

        if (is_string($tag) && $tag !== '') {
            $query->whereHas('tags', fn (Builder $q) => $q->where('customer_tags.uuid', $tag));
        }

        return $query->paginate(min($perPage, 100))->withQueryString();
    }

    /**
     * @param  Builder<Customer>  $query
     */
    private function applySearch(Builder $query, ?string $search, User $viewer): void
    {
        $search = is_string($search) ? trim($search) : '';

        if ($search === '') {
            return;
        }

        $mayMatchContact = $viewer->hasPermission(Permission::CustomerContactView);

        $query->where(function (Builder $q) use ($search, $mayMatchContact): void {
            $q->where('name', 'like', '%'.$search.'%');

            if (! $mayMatchContact) {
                return;
            }

            $q->orWhere('email', 'like', '%'.mb_strtolower($search).'%');

            // Normalised first, so searching `0750…` finds a customer stored as
            // `+964750…` — the same collapse that prevents duplicates.
            $phone = PhoneNumber::parse($search);

            if ($phone !== null) {
                $q->orWhere('phone', $phone->e164);
            }

            // A partial number is still useful at the desk ("the one ending
            // 4567"), and this caller is allowed to see full numbers anyway.
            $digits = preg_replace('/\D+/', '', $search) ?? '';

            if (mb_strlen($digits) >= 4) {
                $q->orWhere('phone', 'like', '%'.$digits);
            }
        });
    }
}
