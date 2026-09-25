<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application;

use App\Kernel\Authorization\BranchScope;
use App\Kernel\Authorization\Permission;
use App\Kernel\Contact\PhoneNumber;
use App\Kernel\Identity\Models\User;
use App\Modules\Customers\Domain\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
 *
 * VISITS ARE READ AS TABLES, NOT MODELS. "Has visited" and "last visit" come
 * from `service_journeys` (a walk-in carries the customer; a booked visit
 * reaches them through its appointment), read by table name exactly as
 * {@see SqlCustomerReportReader} does — Customers never imports the modules
 * above it (docs/04-MODULE-BOUNDARIES.md).
 */
final class CustomerQuery
{
    /**
     * @param  array{search?: string|null, archived?: bool|null, registered?: bool|null, tag?: string|null, visited?: bool|null}  $filters
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

        if (isset($filters['visited'])) {
            $this->applyVisited($query, $filters['visited'], $viewer->branchScope());
        }

        return $query->paginate(min($perPage, 100))->withQueryString();
    }

    /**
     * One customer for the profile page, with what the profile renders.
     *
     * @throws AuthorizationException
     */
    public function find(string $uuid, User $viewer): Customer
    {
        if (! $viewer->hasPermission(Permission::CustomerView)) {
            throw new AuthorizationException(__('manager_customers.errors.may_not_view'));
        }

        /** @var Customer|null $customer */
        $customer = Customer::query()
            ->with(['tags', 'account', 'internalNotes.author'])
            ->where('uuid', $uuid)
            ->first();

        if (! $customer instanceof Customer) {
            throw new NotFoundHttpException;
        }

        return $customer;
    }

    /**
     * The customer a full phone number already belongs to, for the "link, never
     * duplicate" prompt on the create form.
     *
     * Null for anyone without `customer.contact.view`: answering them would be
     * the same oracle the search refuses. Null too for the record being edited.
     */
    public function ownerOfPhone(User $viewer, string $country, string $number, ?string $exceptUuid = null): ?Customer
    {
        if (! $viewer->hasPermission(Permission::CustomerContactView)) {
            return null;
        }

        $phone = PhoneNumber::fromParts($country, $number);

        if ($phone === null) {
            return null;
        }

        /** @var Customer|null $owner */
        $owner = Customer::query()
            ->where('phone', $phone->e164)
            ->when($exceptUuid !== null, fn (Builder $q) => $q->where('uuid', '!=', $exceptUuid))
            ->first();

        return $owner;
    }

    /**
     * When each of these customers last arrived for a visit the viewer may see
     * — two grouped queries for the whole page, never one per row.
     *
     * @param  list<int>  $customerIds
     * @return array<int, CarbonImmutable> keyed by customer id
     */
    public function lastVisits(array $customerIds, User $viewer): array
    {
        if ($customerIds === []) {
            return [];
        }

        $scope = $viewer->branchScope();
        $connection = DB::connection('tenant');

        $walkIns = $scope->applyTo(
            $connection->table('service_journeys')
                ->whereIn('customer_id', $customerIds)
                ->whereNotNull('arrived_at')
                ->selectRaw('customer_id, MAX(arrived_at) as last_at')
                ->groupBy('customer_id'),
            'branch_id',
        );

        $booked = $scope->applyTo(
            $connection->table('service_journeys as journeys')
                ->join('appointments', 'appointments.id', '=', 'journeys.appointment_id')
                ->whereIn('appointments.customer_id', $customerIds)
                ->whereNotNull('journeys.arrived_at')
                ->selectRaw('appointments.customer_id as customer_id, MAX(journeys.arrived_at) as last_at')
                ->groupBy('appointments.customer_id'),
            'appointments.branch_id',
        );

        $last = [];

        foreach ([$walkIns, $booked] as $query) {
            foreach ($query->get() as $row) {
                /** @var object{customer_id: int|string, last_at: string|null} $row */
                if ($row->last_at === null) {
                    continue;
                }

                $at = CarbonImmutable::parse($row->last_at, 'UTC');
                $id = (int) $row->customer_id;

                if (! isset($last[$id]) || $at->greaterThan($last[$id])) {
                    $last[$id] = $at;
                }
            }
        }

        return $last;
    }

    /**
     * "Has visited" means at least one COMPLETED visit in a branch the viewer
     * may see — a booking that never happened is not a visit.
     *
     * @param  Builder<Customer>  $query
     */
    private function applyVisited(Builder $query, bool $visited, BranchScope $scope): void
    {
        $walkIn = function (QueryBuilder $q) use ($scope): void {
            $q->selectRaw('1')->from('service_journeys')
                ->whereColumn('service_journeys.customer_id', 'customers.id')
                ->where('service_journeys.status', 'completed');
            $scope->applyTo($q, 'service_journeys.branch_id');
        };

        $booked = function (QueryBuilder $q) use ($scope): void {
            $q->selectRaw('1')->from('service_journeys')
                ->join('appointments', 'appointments.id', '=', 'service_journeys.appointment_id')
                ->whereColumn('appointments.customer_id', 'customers.id')
                ->where('service_journeys.status', 'completed');
            $scope->applyTo($q, 'appointments.branch_id');
        };

        if ($visited) {
            $query->where(fn (Builder $q) => $q->whereExists($walkIn)->orWhereExists($booked));

            return;
        }

        $query->whereNotExists($walkIn)->whereNotExists($booked);
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
            // 4567", "0750 12…"), and this caller is allowed to see full numbers
            // anyway. A national trunk zero is dropped: E.164 never stores it.
            $digits = ltrim(preg_replace('/\D+/', '', $search) ?? '', '0');

            if (mb_strlen($digits) >= 4) {
                $q->orWhere('phone', 'like', '%'.$digits.'%');
            }
        });
    }
}
