<?php

declare(strict_types=1);

namespace App\Livewire\Center\Integrations;

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Kernel\Identity\Models\User;
use App\Modules\Conversations\Application\Actions\ManageWhatsAppAccount;
use App\Modules\Conversations\Application\MessagingProviderRegistry;
use App\Modules\Conversations\Application\WhatsAppReadiness;
use App\Modules\Conversations\Domain\Exceptions\ConversationFailed;
use App\Modules\Conversations\Domain\Models\WhatsAppAccount;
use App\Modules\Conversations\Infrastructure\Providers\MetaWhatsAppCloudProvider;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The connection drawer: the center's WhatsApp number and its credentials.
 *
 * ## Credentials are write-only, and never leave the request they arrive in
 *
 * The inputs start empty, are never loaded from the account and are cleared
 * in {@see dehydrate()} — so whatever the outcome (saved, refused, invalid),
 * the typed values are not in the snapshot sent back to the browser, not in
 * the rendered HTML and not in any later request. The screen shows each field
 * only as "configured / not configured". Replacing is all or nothing, like the
 * Action: typing any one field means typing all of them; leaving them all
 * empty keeps what is stored (docs/25-WHATSAPP.md §4).
 *
 * Every rule that matters is re-checked by `ManageWhatsAppAccount`: the
 * entitlement, `whatsapp.manage`, the phone number id's shape. There is no
 * field for a URL or a host.
 */
final class ConnectionForm extends Component
{
    /**
     * Opened only by {@see show()}, which checks `whatsapp.manage` and the
     * entitlement. Locked so a client cannot open the drawer by setting it.
     */
    #[Locked]
    public bool $open = false;

    public string $displayName = '';

    public string $phoneNumberId = '';

    public string $businessAccountId = '';

    public string $displayPhoneNumber = '';

    public bool $enabled = true;

    /**
     * Field => typed value. Whatever the browser sent, so not trusted to be a
     * string; cleared at the end of every request.
     *
     * @var array<string, mixed>
     */
    public array $credentials = [];

    #[On('whatsapp-connection-open')]
    public function show(WhatsAppReadiness $readiness): void
    {
        if (! $this->mayManage()) {
            return;
        }

        $account = $readiness->account();

        $this->resetErrorBag();
        $this->credentials = [];
        $this->reset(['displayName', 'phoneNumberId', 'businessAccountId', 'displayPhoneNumber', 'enabled']);

        if ($account instanceof WhatsAppAccount) {
            // Only what is not secret is loaded. Credentials never are.
            $this->displayName = $account->display_name;
            $this->phoneNumberId = $account->phone_number_id ?? '';
            $this->businessAccountId = $account->business_account_id ?? '';
            $this->displayPhoneNumber = $account->display_phone_number ?? '';
            $this->enabled = (bool) $account->enabled;
        }

        $this->open = true;
    }

    public function close(): void
    {
        $this->reset();
        $this->resetErrorBag();
    }

    public function save(ManageWhatsAppAccount $manage, WhatsAppReadiness $readiness): void
    {
        $user = $this->user();
        $account = $readiness->account();
        $fields = $this->fields();

        $typed = array_filter(
            array_map(static fn (mixed $value): string => is_string($value) ? trim($value) : '', array_intersect_key($this->credentials, array_flip($fields))),
            static fn (string $value): bool => $value !== '',
        );
        $replacing = $typed !== [];
        $stored = $account instanceof WhatsAppAccount && $account->isConfigured();

        $rules = [
            'displayName' => ['required', 'string', 'max:120'],
            // The shape Meta issues; it becomes an outbound URL path segment (ADR-071).
            'phoneNumberId' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'businessAccountId' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'displayPhoneNumber' => ['nullable', 'string', 'max:32', 'regex:/^[0-9+()\-\s.]+$/'],
        ];
        $attributes = [
            'displayName' => __('manager_whatsapp.form.display_name'),
            'phoneNumberId' => __('manager_whatsapp.form.phone_number_id'),
            'businessAccountId' => __('manager_whatsapp.form.business_account_id'),
            'displayPhoneNumber' => __('manager_whatsapp.form.display_phone_number'),
        ];

        foreach ($fields as $field) {
            // All or nothing: one typed field means every field is required.
            $rules['credentials.'.$field] = [$replacing || ! $stored ? 'required' : 'nullable', 'string', 'max:512'];
            $attributes['credentials.'.$field] = app(WhatsAppStatusView::class)->fieldLabel($field);
        }

        try {
            $this->validate($rules, [], $attributes);
        } catch (ValidationException $invalid) {
            // The typed secrets are dropped with the response (dehydrate); say
            // so next to them rather than leaving empty boxes unexplained.
            if ($replacing) {
                $invalid->validator->errors()->add('credentials', (string) __('manager_whatsapp.form.retype'));
            }

            throw $invalid;
        }

        try {
            $manage->save(
                user: $user,
                displayName: $this->displayName,
                phoneNumberId: $this->phoneNumberId,
                businessAccountId: $this->businessAccountId,
                displayPhoneNumber: $this->displayPhoneNumber,
                credentials: $replacing ? $typed : [],
                enabled: $this->enabled,
                account: $account,
            );
        } catch (EntitlementRequired) {
            $this->addError('form', (string) __('manager_whatsapp.errors.entitlement'));

            return;
        } catch (AuthorizationException) {
            $this->addError('form', (string) __('manager_whatsapp.errors.forbidden'));

            return;
        } catch (ConversationFailed $refused) {
            $this->addError('form', self::refusal($refused));

            return;
        }

        $message = (string) __($replacing ? 'manager_whatsapp.form.saved_with_credentials' : 'manager_whatsapp.form.saved');

        $this->close();
        $this->dispatch('whatsapp-connection-saved', message: $message);
    }

    /**
     * Whatever happened in this request, the typed credentials do not go back
     * to the browser.
     */
    public function dehydrate(): void
    {
        $this->credentials = [];
    }

    public function render(WhatsAppReadiness $readiness): View
    {
        $account = $this->open ? $readiness->account() : null;
        $facts = $account instanceof WhatsAppAccount ? $readiness->facts($account) : null;
        /** @var array<string, bool> $present */
        $present = $facts['credential_fields'] ?? [];
        $labels = app(WhatsAppStatusView::class);

        $stored = $account instanceof WhatsAppAccount && $account->isConfigured();
        $credentialErrors = array_filter(
            $this->getErrorBag()->keys(),
            static fn (string $key): bool => str_starts_with($key, 'credentials'),
        );

        return view('livewire.center.integrations.connection-form', [
            'editing' => $account instanceof WhatsAppAccount,
            'stored' => $stored,
            // Nothing stored yet, or the last attempt at replacing failed:
            // show the inputs straight away.
            'startReplacing' => ! $stored || $credentialErrors !== [],
            'fields' => array_map(static fn (string $field): array => [
                'name' => $field,
                'label' => $labels->fieldLabel($field),
                'set' => $present[$field] ?? false,
                'tip' => __('manager_whatsapp.credentials.tips.'.$field) === 'manager_whatsapp.credentials.tips.'.$field
                    ? null
                    : (string) __('manager_whatsapp.credentials.tips.'.$field),
            ], $this->open ? $this->fields() : []),
        ]);
    }

    /**
     * A refusal from the Action, in the reader's language. The Action's own
     * sentences are English engineering text; they are mapped, never shown.
     */
    public static function refusal(ConversationFailed $refused): string
    {
        return (string) __(match ($refused->getMessage()) {
            'That phone number id is not valid.' => 'manager_whatsapp.errors.phone_number_id',
            'That messaging provider is not available.' => 'manager_whatsapp.errors.provider',
            'WhatsApp is not configured for this center.' => 'manager_whatsapp.errors.not_configured',
            default => 'manager_whatsapp.errors.generic',
        });
    }

    /**
     * The credential fields the adapter declares, in its order.
     *
     * @return list<string>
     */
    private function fields(): array
    {
        $providers = app(MessagingProviderRegistry::class);

        return $providers->has(MetaWhatsAppCloudProvider::CODE)
            ? $providers->get(MetaWhatsAppCloudProvider::CODE)->credentialFields()
            : [];
    }

    private function mayManage(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $user->hasPermission(Permission::WhatsAppManage)
            && app(Entitlements::class)->enabled('whatsapp_booking');
    }

    private function user(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User && $user->hasPermission(Permission::WhatsAppManage), 403);

        return $user;
    }
}
