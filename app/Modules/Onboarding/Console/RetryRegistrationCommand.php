<?php

declare(strict_types=1);

namespace App\Modules\Onboarding\Console;

use App\Kernel\Audit\Actor;
use App\Kernel\SaaS\Models\Registration;
use App\Modules\Onboarding\Application\RegistrationService;
use Illuminate\Console\Command;

/**
 * Re-queues a failed self-registration.
 *
 * The operator-side counterpart to the public retry endpoint. There is no
 * SADMIN UI yet, so this is how support rescues a center whose provisioning
 * failed (docs/DECISIONS.md ADR-031).
 */
final class RetryRegistrationCommand extends Command
{
    protected $signature = 'metastyle:registration:retry
        {uuid : The registration uuid}';

    protected $description = 'Re-queue provisioning for a failed registration';

    public function handle(RegistrationService $registrations): int
    {
        $registration = Registration::query()->where('uuid', $this->argument('uuid'))->first();

        if (! $registration instanceof Registration) {
            $this->components->error('No registration with that uuid.');

            return self::FAILURE;
        }

        if (! $registration->isRetryable()) {
            $this->components->error(sprintf(
                'Not retryable: status is [%s]%s.',
                $registration->status->value,
                $registration->credentialWindowHasClosed() ? ' and the retry window has closed' : '',
            ));

            return self::FAILURE;
        }

        $registrations->retry($registration, Actor::console($this->getName() ?? 'retry'));

        $this->components->info("Re-queued provisioning for [{$registration->center_name}].");
        $this->line('  Run a worker to process it: <comment>php artisan queue:work --once</comment>');

        return self::SUCCESS;
    }
}
