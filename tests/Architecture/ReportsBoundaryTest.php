<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Reports boundaries
|--------------------------------------------------------------------------
|
| Phase 14 has two products, not one large analytics namespace. Standard
| Reports is the read-only operational foundation. AdvancedReports may consume
| its public contracts/data and the source modules' public reporting seams.
| RAYAN implements an AdvancedReports-owned port; neither reporting product
| imports the concrete assistant.
|
*/

it('keeps standard reports independent from advanced reports and rayan', function (): void {
    $violations = [];

    foreach (appSourceFiles() as $path => $contents) {
        if (! str_starts_with($path, 'app/Modules/Reports/')) {
            continue;
        }

        foreach (['App\\Modules\\AdvancedReports', 'App\\Modules\\Rayan', 'AiProvider', 'ToolRegistry'] as $needle) {
            if (str_contains($contents, $needle)) {
                $violations[] = $path.'  '.$needle;
            }
        }
    }

    expect($violations)->toBe([]);
});

it('keeps advanced reports behind its consumer owned rayan port', function (): void {
    $violations = [];

    foreach (appSourceFiles() as $path => $contents) {
        if (! str_starts_with($path, 'app/Modules/AdvancedReports/')) {
            continue;
        }

        if (str_contains($contents, 'App\\Modules\\Rayan')) {
            $violations[] = $path;
        }
    }

    expect($violations)->toBe([]);
});

it('allows rayan to see only the advanced report public surface', function (): void {
    $violations = [];

    foreach (appSourceFiles() as $path => $contents) {
        if (! str_starts_with($path, 'app/Modules/Rayan/')) {
            continue;
        }

        if (preg_match_all('/use\s+(App\\\\Modules\\\\AdvancedReports\\\\[A-Za-z0-9_\\\\]+)\s*;/', $contents, $matches) === 0) {
            continue;
        }

        foreach ($matches[1] as $imported) {
            if (! str_starts_with($imported, 'App\\Modules\\AdvancedReports\\Contracts\\')
                && ! str_starts_with($imported, 'App\\Modules\\AdvancedReports\\Data\\')) {
                $violations[] = $path.'  '.$imported;
            }
        }
    }

    expect($violations)->toBe([]);
});

it('keeps both report products read only over business domains', function (): void {
    $violations = [];
    $businessTables = [
        'appointments', 'appointment_items', 'service_journeys', 'journey_stages',
        'queue_tickets', 'sales', 'sale_items', 'invoices', 'payments', 'refunds',
        'finance_entries', 'customers', 'reviews', 'loyalty_transactions',
        'package_transactions', 'membership_benefit_usages',
    ];

    foreach (appSourceFiles() as $path => $contents) {
        if (! str_starts_with($path, 'app/Modules/Reports/')
            && ! str_starts_with($path, 'app/Modules/AdvancedReports/')) {
            continue;
        }

        foreach ($businessTables as $table) {
            foreach (["DB::table('{$table}')->insert", "DB::table('{$table}')->update", "DB::table('{$table}')->delete"] as $needle) {
                if (str_contains($contents, $needle)) {
                    $violations[] = $path.'  '.$needle;
                }
            }
        }
    }

    expect($violations)->toBe([]);
});

it('keeps advanced report execution off the primary read target', function (): void {
    $violations = [];

    foreach (appSourceFiles() as $path => $contents) {
        if (! str_starts_with($path, 'app/Modules/AdvancedReports/')) {
            continue;
        }

        if (str_contains($contents, 'ReadTarget::Primary')) {
            $violations[] = $path;
        }
    }

    expect($violations)->toBe([]);
});

it('puts no tenant column or analytical aggregate table in phase 14 migrations', function (): void {
    $violations = [];

    foreach (glob(dirname(__DIR__, 2).'/database/migrations/tenant/2026_09_21_1400*.php') ?: [] as $path) {
        $contents = (string) file_get_contents($path);

        foreach (["'tenant_id'", 'tenantId', 'materialized', 'daily_aggregate', 'monthly_aggregate'] as $needle) {
            if (str_contains($contents, $needle)) {
                $violations[] = basename($path).'  '.$needle;
            }
        }
    }

    expect($violations)->toBe([]);
});
