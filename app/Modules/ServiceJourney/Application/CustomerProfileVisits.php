<?php

declare(strict_types=1);

namespace App\Modules\ServiceJourney\Application;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\ServiceJourney\Domain\Models\ServiceJourney;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * One customer's visits — what actually HAPPENED — for the staff customer
 * page (Manager CRM).
 *
 * Both kinds of visit, without branching on which one a row is: a walk-in
 * carries the customer and branch itself, a booked visit reaches both through
 * its appointment (ADR-051). The viewer needs the broad `journey.view`, and
 * their branch scope narrows each kind on its own branch column.
 *
 * Read-only and bounded, newest first, with the stages, their service and the
 * employee who actually performed each eager-loaded — a fixed number of
 * queries whatever the history's length.
 */
final class CustomerProfileVisits
{
    public const LIMIT = 50;

    /**
     * @return list<ServiceJourney>
     *
     * @throws AuthorizationException
     */
    public function forCustomer(User $viewer, int $customerId, int $limit = self::LIMIT): array
    {
        if (! $viewer->hasPermission(Permission::JourneyView)) {
            throw new AuthorizationException(__('manager_customers.errors.may_not_view_visits'));
        }

        $scope = $viewer->branchScope();

        /** @var list<ServiceJourney> $visits */
        $visits = ServiceJourney::query()
            ->with(['stages.item', 'stages.employee', 'appointment.branch', 'branch'])
            ->where(function (Builder $query) use ($customerId, $scope): void {
                $query->where(function (Builder $walkIn) use ($customerId, $scope): void {
                    $walkIn->where('customer_id', $customerId);
                    $scope->applyTo($walkIn, 'branch_id');
                })->orWhereIn('appointment_id', function (QueryBuilder $booked) use ($customerId, $scope): void {
                    $booked->select('id')
                        ->from(Appointment::query()->getModel()->getTable())
                        ->where('customer_id', $customerId);
                    $scope->applyTo($booked, 'branch_id');
                });
            })
            ->orderByDesc('arrived_at')
            ->orderByDesc('id')
            ->limit(max(1, min($limit, 100)))
            ->get()
            ->all();

        return $visits;
    }
}
