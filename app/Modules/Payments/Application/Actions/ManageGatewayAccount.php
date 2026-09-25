<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Audit\Enums\AuditSeverity;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Payments\Application\PaymentProviderRegistry;
use App\Modules\Payments\Application\PaymentsAccess;
use App\Modules\Payments\Application\PaymentsAudit;
use App\Modules\Payments\Domain\Enums\GatewayEnvironment;
use App\Modules\Payments\Domain\Enums\PaymentStatus;
use App\Modules\Payments\Domain\Exceptions\PaymentFailed;
use App\Modules\Payments\Domain\Models\GatewayAccount;
use App\Modules\Payments\Domain\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Configures and switches a branch's merchant account at a provider.
 *
 * ## Credentials are write-only
 *
 * They arrive here, are validated against the fields the adapter declares, and
 * are stored encrypted. Nothing returns them. Updating them means supplying new
 * values — there is no "keep the old secret" field to echo back, and no "show
 * secret" anywhere (docs/19-PAYMENTS.md §§19, 61).
 *
 * The audit entry records that an account was configured, for which provider
 * and environment, and by whom. Never a value.
 *
 * ## Who
 *
 * `payment.gateway.manage` and `payments`. A cashier holds neither. Disabling is
 * allowed without `payments` — turning a merchant account OFF is a safety
 * action a downgraded center must still be able to take.
 *
 * ## Environment and money in flight
 *
 * Switching sandbox ↔ live while a payment through this account is pending is
 * refused: its callback must still be verified against the environment it was
 * created in. Rotating credentials within an environment is allowed.
 */
final class ManageGatewayAccount
{
    private const MAX_CREDENTIAL = 500;

    public function __construct(
        private readonly PaymentsAccess $access,
        private readonly PaymentProviderRegistry $providers,
        private readonly PaymentsAudit $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $credentials
     *
     * @throws PaymentFailed
     * @throws AuthorizationException
     */
    public function configure(
        string $branchUuid,
        string $providerCode,
        User $actingUser,
        #[\SensitiveParameter] array $credentials,
        GatewayEnvironment $environment,
        string $displayName,
        ?CarbonImmutable $now = null,
    ): GatewayAccount {
        $branch = $this->branch($branchUuid);

        $this->access->ensureGateway($actingUser, Permission::PaymentGatewayManage, $branch->id, 'You may not configure payment gateways.');

        $provider = $this->providers->get($providerCode);
        $capabilities = $provider->capabilities();

        if (! $capabilities->available) {
            throw PaymentFailed::unsupported($provider->displayName().' is not available yet.');
        }

        if (! $capabilities->supportsEnvironment($environment)) {
            throw PaymentFailed::unsupported($provider->displayName().' is not available in that environment on this installation.');
        }

        $values = [];

        foreach ($provider->credentialFields() as $field) {
            $value = $credentials[$field] ?? null;

            if (! is_string($value) || trim($value) === '' || mb_strlen($value) > self::MAX_CREDENTIAL) {
                throw PaymentFailed::policy('Every credential field is required: '.implode(', ', $provider->credentialFields()).'.');
            }

            $values[$field] = trim($value);
        }

        $displayName = trim($displayName);

        if (mb_strlen($displayName) < 2 || mb_strlen($displayName) > 120) {
            throw PaymentFailed::policy('Give the account a name between 2 and 120 characters.');
        }

        $at = ($now ?? CarbonImmutable::now())->utc();

        /** @var GatewayAccount $account */
        $account = DB::connection('tenant')->transaction(function () use ($branch, $provider, $values, $environment, $displayName, $actingUser, $at): GatewayAccount {
            /** @var GatewayAccount|null $existing */
            $existing = GatewayAccount::query()
                ->where('branch_id', $branch->id)
                ->where('provider', $provider->code())
                ->lockForUpdate()
                ->first();

            if ($existing instanceof GatewayAccount && $existing->environment !== $environment && $this->hasPending($existing)) {
                throw PaymentFailed::policy('Online payments through this account are still pending. Resolve them before switching environment.');
            }

            $account = $existing ?? new GatewayAccount([
                'branch_id' => $branch->id,
                'provider' => $provider->code(),
                'enabled' => false,
            ]);

            // Re-entering credentials is how an account written under a rotated
            // key recovers; the unreadable ones must not block their replacement.
            $account->forgetUnreadableCredentials();

            $account->forceFill([
                'environment' => $environment,
                'display_name' => $displayName,
                'credentials' => $values,
                // Only from a field that is an identifier by name — never a
                // secret's tail, whatever order an adapter lists its fields in.
                'safe_identifier' => self::mask($values['client_id'] ?? $values['merchant_id'] ?? ''),
                'configured_at' => $at,
                'configured_by_id' => $actingUser->uuid,
                'configured_by_label' => $actingUser->name,
            ])->save();

            $this->audit->record('payment_gateway.configured', Actor::staff($actingUser), $account, $account->uuid,
                after: ['provider' => $provider->code(), 'environment' => $environment->value, 'enabled' => $account->enabled],
                meta: ['branch' => $branch->uuid, 'rotated' => $existing instanceof GatewayAccount],
                severity: AuditSeverity::Notice,
                category: AuditCategory::Config,
            );

            return $account;
        });

        return $account;
    }

    /**
     * @throws PaymentFailed
     * @throws AuthorizationException
     */
    public function setEnabled(GatewayAccount $account, User $actingUser, bool $enabled): GatewayAccount
    {
        if ($enabled) {
            $this->access->ensureGateway($actingUser, Permission::PaymentGatewayManage, $account->branch_id, 'You may not configure payment gateways.');

            $capabilities = $this->providers->get($account->provider)->capabilities();

            if (! $account->isConfigured() || ! $capabilities->available || ! $capabilities->supportsEnvironment($account->environment)) {
                throw PaymentFailed::policy('Configure the account before enabling it.');
            }
        } else {
            $this->access->authorize($actingUser, Permission::PaymentGatewayManage, $account->branch_id, 'You may not configure payment gateways.');
        }

        /** @var GatewayAccount $updated */
        $updated = DB::connection('tenant')->transaction(function () use ($account, $actingUser, $enabled): GatewayAccount {
            /** @var GatewayAccount $locked */
            $locked = GatewayAccount::query()->whereKey($account->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->enabled === $enabled) {
                return $locked;
            }

            // Credentials are kept either way: a payment already in flight still
            // needs its callback verified after the account is switched off.
            $locked->forceFill(['enabled' => $enabled])->save();

            $this->audit->record($enabled ? 'payment_gateway.enabled' : 'payment_gateway.disabled', Actor::staff($actingUser), $locked, $locked->uuid,
                after: ['enabled' => $enabled],
                meta: ['provider' => $locked->provider],
                severity: AuditSeverity::Notice,
                category: AuditCategory::Config,
            );

            return $locked;
        });

        return $updated;
    }

    /**
     * The last four characters of a NON-secret identifier (a client id),
     * everything else hidden. Never applied to a secret.
     */
    public static function mask(string $identifier): ?string
    {
        $identifier = trim($identifier);

        if (mb_strlen($identifier) < 8) {
            return null;
        }

        return '••••'.mb_substr($identifier, -4);
    }

    private function hasPending(GatewayAccount $account): bool
    {
        return Payment::query()
            ->where('gateway_account_id', $account->getKey())
            ->where('status', PaymentStatus::Pending->value)
            ->exists();
    }

    private function branch(string $uuid): Branch
    {
        /** @var Branch|null $branch */
        $branch = Branch::query()->where('uuid', $uuid)->first();

        if (! $branch instanceof Branch) {
            throw new NotFoundHttpException;
        }

        return $branch;
    }
}
