<?php

declare(strict_types=1);

namespace App\Modules\Onboarding\Console;

use App\Kernel\SaaS\Models\Registration;
use App\Modules\Onboarding\Application\RegistrationService;
use Illuminate\Console\Command;

/**
 * Destroys registration secrets whose usefulness has expired.
 *
 * Two independent sweeps, deliberately in one command because both are the same
 * hourly housekeeping:
 *
 *  - **Bootstrap credentials** whose retry window has closed. Without this a
 *    failed registration nobody returned to keeps an encrypted password hash on
 *    file indefinitely — exactly the liability the window exists to bound
 *    (ADR-031).
 *  - **Access capabilities** past their read grace on a registration that
 *    already succeeded. Nothing else about those rows changes (ADR-035).
 */
final class SweepRegistrationsCommand extends Command
{
    protected $signature = 'metastyle:registration:sweep
        {--dry-run : Report what would be swept without changing anything}';

    protected $description = 'Abandon expired registrations and destroy secrets that are no longer useful';

    public function handle(RegistrationService $registrations): int
    {
        if ($this->option('dry-run')) {
            $abandonable = Registration::query()->sweepable()->count();
            $expired = Registration::query()->accessExpired()->count();

            $this->components->info("{$abandonable} registration(s) would be abandoned.");
            $this->components->info("{$expired} access token(s) would be destroyed.");

            return self::SUCCESS;
        }

        $swept = $registrations->sweepAbandoned();
        $tokens = $registrations->sweepExpiredAccessTokens();

        $this->components->info("{$swept} registration(s) abandoned; bootstrap credentials destroyed.");
        $this->components->info("{$tokens} expired access token(s) destroyed.");

        return self::SUCCESS;
    }
}
