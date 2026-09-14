<?php

declare(strict_types=1);

namespace App\Kernel\Diagnostics\Console;

use App\Kernel\Diagnostics\ProductionReadiness;
use App\Kernel\Diagnostics\ReadinessCheck;
use Illuminate\Console\Command;

/**
 * Deploy gate for configuration that fails silently.
 *
 * Run this on the target host after `config:cache` and before the app takes
 * traffic (docs/12-DEPLOYMENT-MIGRATION-STRATEGY.md §3). It exits non-zero on
 * any failure, so a pipeline stops rather than shipping a deployment whose rate
 * limits do not limit.
 */
final class DoctorCommand extends Command
{
    protected $signature = 'metastyle:doctor
        {--production : Apply production rules regardless of APP_ENV}';

    protected $description = 'Check configuration for problems that would not surface as errors';

    public function handle(ProductionReadiness $readiness): int
    {
        $production = $this->option('production') ? true : null;

        $checks = $readiness->checks($production);

        $this->newLine();

        foreach ($checks as $check) {
            $this->components->twoColumnDetail($check->name, $this->label($check));
            $this->line('    <fg=gray>'.$check->detail.'</>');

            if ($check->remedy !== null) {
                $this->line('    <fg=gray>-> '.$check->remedy.'</>');
            }
        }

        $failures = count(array_filter($checks, fn (ReadinessCheck $c): bool => $c->isFailure()));
        $warnings = count(array_filter($checks, fn (ReadinessCheck $c): bool => $c->isWarning()));

        $this->newLine();

        if ($failures > 0) {
            $this->components->error(sprintf('%d failure(s), %d warning(s).', $failures, $warnings));

            return self::FAILURE;
        }

        if ($warnings > 0) {
            $this->components->warn(sprintf('No failures, %d warning(s).', $warnings));

            return self::SUCCESS;
        }

        $this->components->info('All checks passed.');

        return self::SUCCESS;
    }

    private function label(ReadinessCheck $check): string
    {
        return match (true) {
            $check->isFailure() => '<fg=red>FAIL</>',
            $check->isWarning() => '<fg=yellow>warn</>',
            default => '<fg=green>ok</>',
        };
    }
}
