<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Kernel\Identity\Actions\IssueApiToken;
use App\Kernel\Identity\Models\User;
use App\Kernel\Reporting\ReportConnection;
use App\Kernel\SaaS\Enums\RegistrationStatus;
use App\Kernel\SaaS\Models\Registration;
use App\Kernel\SaaS\RegistrationAccessToken;
use App\Kernel\Tenancy\Infrastructure\StanclTenantContext;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use App\Kernel\Tenancy\Tenant;
use App\Modules\Onboarding\Application\RegistrationService;
use App\Modules\Onboarding\Infrastructure\Jobs\ProvisionRegisteredTenant;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Test harness for Phase 3: registered centers with a real owner account.
 *
 * Goes through the real registration service and the real provisioning job, so
 * a regression in either fails the tests that depend on them rather than
 * hiding behind a fixture.
 */
trait RegistersCenters
{
    /** @var list<string> */
    private array $registeredDatabases = [];

    /** Points the optional reporting seam at this test tenant's real database. */
    protected function configureReportingConnection(): void
    {
        $template = DB::connection('tenant')->getConfig();
        $template['database'] = null;
        $template['name'] = ReportConnection::REPORTING_CONNECTION;

        config()->set('database.connections.reporting_template', $template);
        app(ReportConnection::class)->forget();
    }

    /**
     * Registers a center and runs provisioning to completion.
     *
     * @return array{tenant: Tenant, registration: Registration, password: string, access_token: string}
     */
    protected function registerCenter(
        string $centerName = 'Barbershop Alpha',
        string $email = 'owner@alpha.test',
        string $password = 'correct-horse-battery-staple',
        ?string $idempotencyKey = null,
        ?string $slug = null,
    ): array {
        $result = app(RegistrationService::class)->register([
            'center_name' => $centerName,
            // Absent, registration derives the slug from the name, as it does
            // for a real signup.
            'center_slug' => $slug,
            'owner_name' => 'Owner of '.$centerName,
            'owner_email' => $email,
            'owner_phone' => '+9647701234567',
            'password' => $password,
            'locale' => 'en',
            'country' => 'IQ',
        ], $idempotencyKey ?? 'test:'.$centerName.':'.$email);

        $registration = $result['registration'];

        $this->runProvisioning($registration);

        $registration->refresh();

        $model = TenantModel::query()->findOrFail($registration->tenant_id);

        $this->registeredDatabases[] = (string) $model->tenancy_db_name;

        return [
            'tenant' => $model->toValueObject(),
            'registration' => $registration,
            'password' => $password,
            'access_token' => (string) $result['access_token'],
        ];
    }

    /**
     * Runs the queued provisioning job synchronously.
     *
     * Registration genuinely enqueues the job, so running it inline here would
     * otherwise leave a duplicate sitting in `jobs`. Any later test that starts
     * a worker would then pick it up — which is exactly how the queue isolation
     * tests started failing only when the whole suite ran. The row is removed
     * so the harness leaves the queue as it found it.
     */
    protected function runProvisioning(Registration $registration): void
    {
        if ($registration->email_verified_at === null) {
            app(RegistrationService::class)->verifyEmail($registration);
            $registration->refresh();
        }

        $job = new ProvisionRegisteredTenant($registration->uuid);

        app()->call([$job, 'handle']);

        DB::connection('control')
            ->table('jobs')
            ->where('payload', 'like', '%'.$registration->uuid.'%')
            ->delete();
    }

    protected function assertRegistrationReady(Registration $registration): void
    {
        expect($registration->refresh()->status)->toBe(RegistrationStatus::Ready);
    }

    /**
     * @template TReturn
     *
     * @param  Closure(Tenant): TReturn  $callback
     * @return TReturn
     */
    protected function asCenter(Tenant $tenant, Closure $callback): mixed
    {
        $model = TenantModel::query()->findOrFail($tenant->id);

        // What ResolveTenant does for a real request on the center's host, so
        // a component rendered in-process can link to its sibling pages.
        if (is_string($model->slug) && $model->slug !== '') {
            app('url')->defaults(['center' => $model->slug]);
        }

        return app(StanclTenantContext::class)->runForModel($model, $callback);
    }

    protected function ownerOf(Tenant $tenant): User
    {
        return $this->asCenter($tenant, fn (): User => User::query()->where('is_owner', true)->firstOrFail());
    }

    /**
     * Issues a real tenant-bound API token, prefix and all.
     */
    protected function apiTokenFor(Tenant $tenant, ?User $user = null): string
    {
        return $this->asCenter($tenant, function () use ($tenant, $user): string {
            $user ??= User::query()->where('is_owner', true)->firstOrFail();

            /** @var array{token: string, expires_at: string|null} $issued */
            $issued = app(IssueApiToken::class)($user, 'test');

            unset($tenant);

            return $issued['token'];
        });
    }

    /**
     * @return array<string, string>
     */
    protected function tokenHeaders(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    /**
     * Headers presenting a registration's access capability (ADR-035).
     *
     * @return array<string, string>
     */
    protected function registrationHeaders(string $accessToken): array
    {
        return [RegistrationAccessToken::HEADER => $accessToken, 'Accept' => 'application/json'];
    }

    protected function publicKeyOf(Tenant $tenant): string
    {
        return (string) TenantModel::query()->whereKey($tenant->id)->value('public_key');
    }

    /**
     * Registers a database created outside registerCenter() for cleanup.
     */
    protected function trackRegistrationDatabase(Registration $registration): void
    {
        $registration->refresh();

        if ($registration->tenant_id === null) {
            return;
        }

        $name = TenantModel::query()->whereKey($registration->tenant_id)->value('tenancy_db_name');

        if (is_string($name) && $name !== '') {
            $this->registeredDatabases[] = $name;
        }
    }

    protected function tearDownRegisteredCenters(): void
    {
        foreach (array_unique($this->registeredDatabases) as $database) {
            TestDatabaseManager::drop($database);
        }

        $this->registeredDatabases = [];
    }
}
