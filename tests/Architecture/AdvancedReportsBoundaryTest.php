<?php

declare(strict_types=1);

it('does not let report analysis reach sql tools or domain actions', function (): void {
    $violations = [];

    foreach (appSourceFiles() as $path => $contents) {
        if (! str_starts_with($path, 'app/Modules/AdvancedReports/')
            && ! str_contains($path, 'RayanReportAnalyst.php')) {
            continue;
        }

        foreach (['ToolRegistry', 'BookingEngine', 'SaleMutation', 'Ledger::append', 'DB::statement(', 'DB::unprepared('] as $needle) {
            if (str_contains($contents, $needle)) {
                $violations[] = $path.'  '.$needle;
            }
        }
    }

    expect($violations)->toBe([]);
});

it('keeps the advanced report ai allowance separate from customer rayan runs', function (): void {
    $config = require dirname(__DIR__, 2).'/config/usage.php';

    expect($config['resources'])->toHaveKey('advanced_report_ai_runs')
        ->and($config['resources']['advanced_report_ai_runs']['enforced'])->toBeTrue()
        ->and($config['resources'])->toHaveKey('ai_runs');
});

it('reads other modules only through their public reporting contracts', function (): void {
    $violations = [];
    $backslash = chr(92);

    foreach (appSourceFiles() as $path => $contents) {
        if (! str_starts_with($path, 'app/Modules/AdvancedReports/')) {
            continue;
        }

        // No other module's models and no raw database access: every fact
        // comes through a `*ReportReader` contract on the reporting target.
        foreach ([$backslash.'Domain'.$backslash.'Models'.$backslash, 'Facades'.$backslash.'DB', 'DB::table(', 'DB::connection('] as $needle) {
            if (str_contains($contents, $needle)) {
                $violations[] = $path.'  '.$needle;
            }
        }

        // Another module (Standard Reports aside) only through Contracts / Data.
        if (preg_match_all('/^use App\\\\Modules\\\\([A-Za-z]+)\\\\([A-Za-z]+)\\\\/m', $contents, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                if (! in_array($match[1], ['AdvancedReports', 'Reports'], true) && ! in_array($match[2], ['Contracts', 'Data'], true)) {
                    $violations[] = $path.'  '.$match[0];
                }
            }
        }
    }

    expect($violations)->toBe([]);
});

it('keeps the Advanced page AI panel behind the analysis action, never the assistant itself', function (): void {
    $violations = [];
    $rayan = 'App'.chr(92).'Modules'.chr(92).'Rayan';

    foreach (appSourceFiles() as $path => $contents) {
        if (! str_starts_with($path, 'app/Livewire/Center/AdvancedReports') && ! str_starts_with($path, 'app/View/AdvancedReports/')) {
            continue;
        }

        foreach ([$rayan, 'AiProvider', 'ToolRegistry', 'DB::'] as $needle) {
            if (str_contains($contents, $needle)) {
                $violations[] = $path.'  '.$needle;
            }
        }
    }

    expect($violations)->toBe([]);
});
