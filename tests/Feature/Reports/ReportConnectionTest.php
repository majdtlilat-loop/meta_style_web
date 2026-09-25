<?php

declare(strict_types=1);

use App\Kernel\Reporting\Exceptions\ReportingConnectionUnavailable;
use App\Kernel\Reporting\ReadTarget;
use App\Kernel\Reporting\ReportConnection;
use Illuminate\Support\Facades\DB;

it('keeps Primary available when optional reporting credentials are absent', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        config()->set('database.connections.reporting_template.host', null);
        config()->set('database.connections.reporting_template.username', null);

        $connection = app(ReportConnection::class);

        expect($connection->for(ReadTarget::Primary)->getName())->toBe('tenant')
            ->and(fn () => $connection->for(ReadTarget::Reporting))
            ->toThrow(ReportingConnectionUnavailable::class);
    });
});

it('binds Reporting to the current tenant database without falling back to Primary', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $primary = DB::connection('tenant');
        $template = $primary->getConfig();
        $template['database'] = null;

        config()->set('database.connections.reporting_template', $template);

        $reporting = app(ReportConnection::class)->for(ReadTarget::Reporting);

        expect($reporting->getName())->toBe(ReportConnection::REPORTING_CONNECTION)
            ->and($reporting->getDatabaseName())->toBe($primary->getDatabaseName())
            ->and($reporting->table('branches')->count())->toBe(1);
    });

    expect(config('database.connections.'.ReportConnection::REPORTING_CONNECTION))->toBeNull();
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
