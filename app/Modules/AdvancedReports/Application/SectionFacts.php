<?php

declare(strict_types=1);

namespace App\Modules\AdvancedReports\Application;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Reporting\ReadTarget;
use App\Kernel\Reporting\ReportReadRequest;
use App\Modules\Booking\Contracts\BookingReportReader;
use App\Modules\Customers\Contracts\CustomerReportReader;
use App\Modules\Loyalty\Contracts\BenefitReportReader;
use App\Modules\Payments\Contracts\PaymentReportReader;
use App\Modules\Queue\Contracts\QueueReportReader;
use App\Modules\Reviews\Contracts\ReviewReportReader;
use App\Modules\Sales\Contracts\SalesReportReader;
use App\Modules\ServiceJourney\Contracts\JourneyReportReader;
use InvalidArgumentException;

/**
 * One family of facts, read through its owner's public reporting contract.
 *
 * The Advanced workspace and the comparisons are made of these sections.
 * Each exists only for a viewer holding every source-domain permission it
 * needs (the same codes the Advanced catalog asks for), and each is read
 * ONLY from the reporting target — never the primary database.
 */
final readonly class SectionFacts
{
    /** Section => the permissions its source facts require. */
    public const SECTIONS = [
        'bookings' => ['appointment.view'],
        'visits' => ['journey.view'],
        'sales' => ['sale.view'],
        'payments' => ['payment.view'],
        'customers' => ['customer.view', 'journey.view'],
        'queue' => ['queue.view'],
        'reviews' => ['review.view'],
        'benefits' => ['loyalty.view', 'package.view', 'membership.view'],
    ];

    public function __construct(
        private BookingReportReader $bookings,
        private JourneyReportReader $journeys,
        private SalesReportReader $sales,
        private PaymentReportReader $payments,
        private CustomerReportReader $customers,
        private QueueReportReader $queue,
        private ReviewReportReader $reviews,
        private BenefitReportReader $benefits,
    ) {}

    /**
     * The sections this viewer may read, in presentation order.
     *
     * @param  list<string>|null  $only  a subset to consider
     * @return list<string>
     */
    public function permitted(User $user, ?array $only = null): array
    {
        $sections = [];

        foreach (self::SECTIONS as $section => $permissions) {
            if ($only !== null && ! in_array($section, $only, true)) {
                continue;
            }

            if ($this->holdsAll($user, $permissions)) {
                $sections[] = $section;
            }
        }

        return $sections;
    }

    /**
     * @param  bool  $detailed  payments: also the period's outstanding balance
     *                          and the anonymized customer values (the current
     *                          period only — a comparison needs the cash flow)
     * @return array<string, mixed>
     */
    public function read(string $section, ReportReadRequest $request, bool $detailed = false): array
    {
        if ($request->target !== ReadTarget::Reporting) {
            throw new InvalidArgumentException('Advanced Reports read the reporting target only.');
        }

        return match ($section) {
            'bookings' => $this->bookings->summary($request),
            'visits' => $this->journeys->summary($request),
            'sales' => $this->sales->summary($request),
            'payments' => $detailed ? $this->payments->summary($request) : $this->payments->totals($request),
            'customers' => $this->customers->summary($request),
            'queue' => $this->queue->summary($request),
            'reviews' => $this->reviews->summary($request),
            'benefits' => $this->benefits->summary($request),
            default => throw new InvalidArgumentException('Unknown Advanced Report section.'),
        };
    }

    /** @param list<string> $permissions */
    private function holdsAll(User $user, array $permissions): bool
    {
        foreach ($permissions as $code) {
            $permission = Permission::tryFrom($code);

            if (! $permission instanceof Permission || ! $user->hasPermission($permission)) {
                return false;
            }
        }

        return true;
    }
}
