<?php

declare(strict_types=1);

namespace App\Modules\Onboarding\Application;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\SaaS\Enums\RegistrationStatus;
use App\Kernel\SaaS\Models\Registration;
use App\Modules\Onboarding\Infrastructure\Jobs\ProvisionRegisteredTenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Accepts a center's self-registration and hands the slow part to a queue.
 *
 * Lives in a MODULE rather than the Kernel because onboarding composes across
 * layers: it needs SaaS plans and subscriptions (L0), Identity and
 * Authorization (Kernel), and Branches and Employees (L1). The Kernel may not
 * depend on modules, so the orchestration belongs on this side of the line
 * (docs/04-MODULE-BOUNDARIES.md §2).
 *
 * The web request does three things and stops: validate, record, enqueue.
 * Creating a database and running migrations takes seconds at best and can fail
 * in ways a HTTP request cannot usefully express, so provisioning happens in
 * {@see ProvisionRegisteredTenant} and the client polls for the outcome.
 *
 * SECRET HANDLING (ADR-028, revised by ADR-031). The password is hashed HERE,
 * in the request, and the plaintext is discarded immediately. Only the hash
 * continues, encrypted at rest on the registration row, and only for as long as
 * the registration could still succeed. The queue payload carries the
 * registration uuid and nothing else.
 */
final class RegistrationService
{
    public function __construct(private readonly Audit $audit) {}

    /**
     * Accepts a registration and returns it with its one-time access token.
     *
     * The token is returned OUT OF BAND rather than stored on the returned
     * model, because there is nowhere on the model it could live without
     * risking serialisation. Callers hand it to the registering client and
     * forget it; nobody can ever reissue it (ADR-035).
     *
     * @param  array{center_name: string, owner_name: string, owner_email?: string|null, owner_phone?: string|null, password: string, locale?: string|null, country?: string|null}  $input
     * @return array{registration: Registration, access_token: string|null}
     */
    public function register(array $input, string $idempotencyKey): array
    {
        // Idempotency first: a double-clicked submit or a client retry must
        // return the original registration, not create a second tenant, a
        // second database and a second subscription.
        $existing = Registration::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing instanceof Registration) {
            // No token: the capability was issued once, to whoever submitted
            // first. Minting a second one for a repeated request would let
            // anyone who can guess the idempotency inputs — a center name and
            // an email — take over a registration they do not own.
            return ['registration' => $existing, 'access_token' => null];
        }

        $passwordHash = Hash::make($input['password']);

        $registration = new Registration;

        $accessToken = $registration->issueAccessToken();

        $registration->forceFill([
            'idempotency_key' => $idempotencyKey,
            'status' => RegistrationStatus::Preparing,
            'center_name' => $input['center_name'],
            'owner_name' => $input['owner_name'],
            'owner_email' => $input['owner_email'] ?? null,
            'owner_phone' => $input['owner_phone'] ?? null,
            'locale' => $input['locale'] ?? config('localization.fallback', 'en'),
            'country' => $input['country'] ?? null,
            'owner_password_hash' => $passwordHash,
            'credentials_expire_at' => $this->retryWindowCloses(),
        ]);

        DB::connection('control')->transaction(static function () use ($registration): void {
            $registration->save();
        });

        $this->audit->record(new AuditEvent(
            action: 'saas.registration.requested',
            category: AuditCategory::Tenancy,
            actor: Actor::system('self-registration'),
            targetType: Registration::class,
            targetId: $registration->uuid,
            targetLabel: $registration->center_name,
            // Note what is absent: no password, no hash, not even a hint that
            // one exists. Only what a support agent needs to find this row.
            after: [
                'center_name' => $registration->center_name,
                'locale' => $registration->locale,
                'country' => $registration->country,
            ],
        ));

        ProvisionRegisteredTenant::dispatch($registration->uuid);

        return ['registration' => $registration, 'access_token' => $accessToken];
    }

    /**
     * Re-queues a failed registration.
     *
     * The pipeline is idempotent throughout, so a retry resumes from wherever
     * the previous attempt stopped rather than starting over: it will not
     * create a second tenant, database, branch, owner, role set or
     * subscription (ADR-031).
     */
    public function retry(Registration $registration, ?Actor $actor = null): bool
    {
        if (! $registration->isRetryable()) {
            return false;
        }

        $registration->forceFill([
            'status' => RegistrationStatus::Preparing,
            'error' => null,
        ])->save();

        $this->audit->record(new AuditEvent(
            action: 'saas.registration.retried',
            category: AuditCategory::Tenancy,
            actor: $actor ?? Actor::system('self-registration'),
            targetType: Registration::class,
            targetId: $registration->uuid,
            targetLabel: $registration->center_name,
            meta: ['window_closes_at' => $registration->credentials_expire_at?->toIso8601String()],
        ));

        ProvisionRegisteredTenant::dispatch($registration->uuid);

        return true;
    }

    /**
     * Gives up on a registration and destroys its bootstrap credential.
     */
    public function cancel(Registration $registration, string $reason, ?Actor $actor = null): bool
    {
        if ($registration->status->isTerminal()) {
            return false;
        }

        $registration->settle(RegistrationStatus::Cancelled);

        $this->audit->record(new AuditEvent(
            action: 'saas.registration.cancelled',
            category: AuditCategory::Tenancy,
            actor: $actor ?? Actor::system('self-registration'),
            targetType: Registration::class,
            targetId: $registration->uuid,
            targetLabel: $registration->center_name,
            reason: $reason,
        ));

        return true;
    }

    /**
     * Destroys the credentials of registrations whose retry window has closed.
     *
     * Run on a schedule. Without it, a failed registration nobody returned to
     * would keep an encrypted password hash on file forever — which is exactly
     * the liability the window exists to bound.
     *
     * @return int number of registrations swept
     */
    /**
     * Destroys access capabilities whose read grace has elapsed.
     *
     * Separate from {@see sweepAbandoned()} because these registrations are
     * successful, not abandoned: only the token goes (ADR-035).
     *
     * @return int number of capabilities destroyed
     */
    public function sweepExpiredAccessTokens(?Carbon $now = null): int
    {
        $destroyed = 0;

        Registration::query()->accessExpired($now)->each(function (Registration $registration) use (&$destroyed): void {
            $registration->forgetAccessToken();

            $destroyed++;
        });

        return $destroyed;
    }

    public function sweepAbandoned(?Carbon $now = null): int
    {
        $swept = 0;

        Registration::query()->sweepable($now)->each(function (Registration $registration) use (&$swept): void {
            $registration->settle(RegistrationStatus::Abandoned);

            $this->audit->record(new AuditEvent(
                action: 'saas.registration.abandoned',
                category: AuditCategory::Tenancy,
                actor: Actor::system('registration-sweep'),
                targetType: Registration::class,
                targetId: $registration->uuid,
                targetLabel: $registration->center_name,
                reason: 'retry window closed without a successful provisioning',
            ));

            $swept++;
        });

        return $swept;
    }

    private function retryWindowCloses(): Carbon
    {
        $hours = config('metastyle.registration.retry_window_hours');

        return Carbon::now()->addHours(is_numeric($hours) ? (int) $hours : 24);
    }
}
