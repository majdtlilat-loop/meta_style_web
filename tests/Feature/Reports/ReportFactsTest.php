<?php

declare(strict_types=1);

use App\Kernel\Authorization\SystemRole;
use App\Kernel\Reporting\ReadTarget;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Payments\Application\Actions\CollectDeskPayment;
use App\Modules\Payments\Domain\Enums\PaymentMethod;
use App\Modules\Reports\Application\ReportRequestFactory;
use App\Modules\Reports\Application\StandardReports;

function reportKpiValue($result, string $key): mixed
{
    foreach ($result->kpis as $kpi) {
        if ($kpi->key === $key) {
            return $kpi->value;
        }
    }

    return null;
}

it('enforces branch scope in report facts rather than trusting requested filters', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantEntitlement('reports_standard', null);
        $seed = $this->seedBookableCenter();
        $second = $this->seedBranch();
        $this->openEveryDay($second);
        $owner = $this->ownerWithCatalogAccess();

        $this->walkInVisit($seed, $owner);
        $this->walkInVisit(['branch' => $second, 'service' => $seed['service']], $owner);

        $manager = $this->seedStaffMember(SystemRole::Manager, [(int) $seed['branch']->getKey()]);
        $date = $this->branchToday($seed['branch']);
        $request = app(ReportRequestFactory::class)->make(
            $manager->branchScope(),
            $date,
            $date,
            // A caller cannot widen their access by explicitly requesting the
            // other branch; the reader intersects this with BranchScope.
            [$seed['branch']->uuid, $second->uuid],
            ReadTarget::Primary,
        );
        $result = app(StandardReports::class)->run('visit_service_delivery', $manager, $request);

        expect($request->windows)->toHaveCount(1)
            ->and($request->windows[0]->branchId)->toBe((int) $seed['branch']->getKey())
            ->and(reportKpiValue($result, 'arrivals'))->toBe(1);
    });
});

it('uses half-open UTC windows derived from the branch local calendar', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantEntitlement('reports_standard', null);
        $seed = $this->seedBookableCenter('Asia/Baghdad');
        $owner = $this->ownerWithCatalogAccess();
        $inside = $this->walkInVisit($seed, $owner);
        $outside = $this->walkInVisit($seed, $owner);
        $date = '2026-09-20';
        $start = $this->localTime($seed['branch'], $date, '00:00');

        $inside->forceFill(['arrived_at' => $start])->save();
        $outside->forceFill(['arrived_at' => $start->subSecond()])->save();

        $request = app(ReportRequestFactory::class)->make($owner->branchScope(), $date, $date, [], ReadTarget::Primary);
        $result = app(StandardReports::class)->run('visit_service_delivery', $owner, $request);

        expect(reportKpiValue($result, 'arrivals'))->toBe(1)
            ->and($result->series)->toBe([['date' => $date, 'value' => 1]]);
    });
});

it('computes invoice outstanding from lifetime settlement while period collections remain period based', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantEntitlement('reports_standard', null);
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $invoice = $this->issuedInvoice($seed, $owner);
        $payment = app(CollectDeskPayment::class)($invoice, $owner, PaymentMethod::Cash, 5000);
        $date = $this->branchToday($seed['branch']);

        // Settlement happened outside the selected report day. It still
        // reduces this invoice's current balance, but is not a period receipt.
        $payment->forceFill([
            'succeeded_at' => $this->localTime($seed['branch'], $date, '12:00')->addDay(),
        ])->save();

        $request = app(ReportRequestFactory::class)->make($owner->branchScope(), $date, $date, [], ReadTarget::Primary);
        $result = app(StandardReports::class)->run('sales_payments', $owner, $request);

        expect(reportKpiValue($result, 'billed'))->toBe(20000)
            ->and(reportKpiValue($result, 'collected'))->toBe(0)
            ->and(reportKpiValue($result, 'outstanding'))->toBe(15000);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
