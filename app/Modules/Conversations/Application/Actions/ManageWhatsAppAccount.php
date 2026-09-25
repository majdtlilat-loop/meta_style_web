<?php

declare(strict_types=1);

namespace App\Modules\Conversations\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\ActorType;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Audit\Enums\AuditSeverity;
use App\Kernel\Audit\Enums\AuditSource;
use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Kernel\Identity\Models\User;
use App\Modules\Conversations\Application\MessagingProviderRegistry;
use App\Modules\Conversations\Domain\Data\WhatsAppCredentials;
use App\Modules\Conversations\Domain\Exceptions\ConversationFailed;
use App\Modules\Conversations\Domain\Models\WhatsAppAccount;
use App\Modules\Conversations\Infrastructure\Providers\MetaWhatsAppCloudProvider;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use SensitiveParameter;

/**
 * Configuring a center's WhatsApp Business connection. The ONE place
 * credentials are written.
 *
 * ## Credentials are write-only
 *
 * They go in here and come out only inside a
 * {@see WhatsAppCredentials} on its way
 * to one adapter call. They are never returned by this Action, never put in a
 * presenter, never logged, and never audited — the audit entry records THAT
 * they were replaced and by whom, never what they are (docs/25-WHATSAPP.md §4).
 *
 * ## Replacing, not merging
 *
 * Supplying credentials replaces the stored set wholesale. Merging would let a
 * half-filled form leave a center with one new token and one old secret — a
 * state that looks configured and cannot verify a signature, which is the
 * hardest kind of failure to diagnose from the outside.
 *
 * Leaving the credential fields EMPTY keeps what is stored, so a manager can
 * rename an account or toggle it without re-entering three secrets.
 *
 * ## No URL, ever
 *
 * There is no field for a base URL, a host or a webhook target, and there is no
 * column for one. Where Meta lives is platform configuration. A tenant-supplied
 * destination would be server-side request forgery by configuration — with the
 * center's own access token attached to the request (ADR-071).
 */
final class ManageWhatsAppAccount
{
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly MessagingProviderRegistry $providers,
        private readonly Audit $audit,
    ) {}

    /**
     * Creates or updates the center's account.
     *
     * @param  array<string, string>  $credentials  `access_token`, `app_secret`,
     *                                              `verify_token`. Empty keeps
     *                                              what is stored.
     *
     * @throws AuthorizationException
     * @throws EntitlementRequired
     * @throws ConversationFailed
     */
    public function save(
        User $user,
        string $displayName,
        string $phoneNumberId,
        ?string $businessAccountId,
        ?string $displayPhoneNumber,
        #[SensitiveParameter]
        array $credentials,
        bool $enabled,
        ?WhatsAppAccount $account = null,
    ): WhatsAppAccount {
        $this->authorize($user);

        $provider = $this->providers->get(MetaWhatsAppCloudProvider::CODE);

        if (! $provider->capabilities()->available) {
            throw ConversationFailed::policy('That messaging provider is not available.');
        }

        $phoneNumberId = trim($phoneNumberId);

        /*
         * Validated to the shape Meta issues, because this value goes into an
         * outbound URL PATH. Without this a value containing `../` or a host
         * could redirect the request that carries the center's token (ADR-071).
         */
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $phoneNumberId) !== 1) {
            throw ConversationFailed::policy('That phone number id is not valid.');
        }

        $account ??= new WhatsAppAccount;

        // What the audit entry compares against. Identifiers only, never a
        // credential (§20).
        $before = $account->exists
            ? ['enabled' => (bool) $account->enabled, 'phone_number_id' => $account->phone_number_id]
            : null;

        // Lets newly entered credentials replace ones that no longer decrypt
        // after an application key change, instead of throwing while comparing.
        $account->forgetUnreadableCredentials();

        $account->forceFill([
            'provider' => MetaWhatsAppCloudProvider::CODE,
            'display_name' => trim($displayName),
            'phone_number_id' => $phoneNumberId,
            'business_account_id' => $this->clean($businessAccountId),
            'display_phone_number' => $this->clean($displayPhoneNumber),
            'enabled' => $enabled,
        ]);

        $supplied = $this->supplied($credentials, $provider->credentialFields());

        if ($supplied !== []) {
            // Wholesale replacement, never a merge.
            $account->credentials = $supplied;
            $account->configured_at = CarbonImmutable::now()->utc();
            $account->configured_by_id = $user->uuid;
            $account->configured_by_label = $user->name;

            /*
             * A fresh set of credentials clears the last recorded error. The
             * old one was about the old secrets, and leaving it would show a
             * manager a failure they have just fixed.
             */
            $account->last_error_at = null;
            $account->last_error_code = null;
        }

        $account->save();

        $this->record($user, $account, $supplied !== [], $before);

        return $account;
    }

    /**
     * Switches the channel on or off, and changes nothing else.
     *
     * ON needs a configured account: an enabled account with no usable
     * credentials is a switch that does nothing while looking like a working
     * channel to the manager who flipped it. OFF keeps the credentials — a
     * status callback for a message already in flight still has to be
     * verified (§8). Either way the entitlement and `whatsapp.manage` are
     * checked here, whatever the screen showed.
     *
     * @throws AuthorizationException
     * @throws EntitlementRequired
     * @throws ConversationFailed
     */
    public function setEnabled(User $user, WhatsAppAccount $account, bool $enabled): WhatsAppAccount
    {
        $this->authorize($user);

        if ($enabled && ! $account->isConfigured()) {
            throw ConversationFailed::notConfigured();
        }

        if ((bool) $account->enabled === $enabled) {
            return $account;
        }

        $account->forceFill(['enabled' => $enabled])->save();

        $this->audit->record(new AuditEvent(
            action: $enabled ? 'whatsapp.account.enabled' : 'whatsapp.account.disabled',
            category: AuditCategory::Config,
            actor: new Actor(ActorType::Staff, AuditSource::Web, $user->uuid, $user->name),
            severity: AuditSeverity::Info,
            targetType: WhatsAppAccount::class,
            targetId: $account->uuid,
            targetLabel: $account->display_name,
            before: ['enabled' => ! $enabled],
            after: ['enabled' => $enabled],
        ));

        return $account;
    }

    /**
     * @throws AuthorizationException
     * @throws EntitlementRequired
     */
    private function authorize(User $user): void
    {
        // Configuring the channel is NEW activity: a center that does not own
        // WhatsApp cannot connect a number to it.
        $this->entitlements->ensure('whatsapp_booking');

        if (! $user->hasPermission(Permission::WhatsAppManage)) {
            throw new AuthorizationException('You may not manage the WhatsApp connection.');
        }

        /*
         * No branch check. The account is the CENTER's, not a branch's — unlike
         * a payment gateway, which settles into a specific branch's merchant
         * account. A center has one WhatsApp number and one Meta app (§4).
         */
    }

    /**
     * Keeps only non-empty values for fields the adapter actually declared.
     *
     * A field the provider does not use is dropped rather than stored: an
     * unknown key in the credential blob would be a value nothing reads and
     * nobody can account for.
     *
     * @param  array<string, string>  $credentials
     * @param  list<string>  $fields
     * @return array<string, string>
     */
    private function supplied(#[SensitiveParameter] array $credentials, array $fields): array
    {
        $supplied = [];

        foreach ($fields as $field) {
            $value = $credentials[$field] ?? null;

            if (is_string($value) && trim($value) !== '') {
                $supplied[$field] = trim($value);
            }
        }

        /*
         * ALL OR NOTHING. A partial set would leave an account that looks
         * configured and cannot verify a signature — messages would arrive and
         * be rejected, with the center seeing only silence.
         */
        return count($supplied) === count($fields) ? $supplied : [];
    }

    private function clean(?string $value): ?string
    {
        $value = $value === null ? '' : trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * NO CREDENTIAL VALUES, and no digest of one either.
     *
     * What is recorded is that the connection was configured, by whom, and
     * whether the secrets were replaced — at `Warning` severity when they were,
     * because replacing them changes who can message that center's customers in
     * the center's name (§20). `before` holds the same two identifiers, never
     * a credential.
     *
     * @param  array{enabled: bool, phone_number_id: string|null}|null  $before
     */
    private function record(User $user, WhatsAppAccount $account, bool $credentialsReplaced, ?array $before = null): void
    {
        $this->audit->record(new AuditEvent(
            action: 'whatsapp.account.configured',
            category: AuditCategory::Security,
            actor: new Actor(ActorType::Staff, AuditSource::Web, $user->uuid, $user->name),
            severity: $credentialsReplaced ? AuditSeverity::Warning : AuditSeverity::Info,
            targetType: WhatsAppAccount::class,
            targetId: $account->uuid,
            targetLabel: $account->display_name,
            before: $before,
            after: [
                'enabled' => $account->enabled,
                'phone_number_id' => $account->phone_number_id,
                'credentials_replaced' => $credentialsReplaced,
            ],
        ));
    }
}
