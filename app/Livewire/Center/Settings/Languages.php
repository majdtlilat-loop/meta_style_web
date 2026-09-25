<?php

declare(strict_types=1);

namespace App\Livewire\Center\Settings;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\Actions\UpdateContentLanguages;
use App\Kernel\Localization\LanguageRegistry;
use App\Kernel\Localization\TenantLocales;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Settings → Languages: the languages the center's CONTENT is written in, and
 * which one is primary. Not the interface language — each person picks that in
 * the top bar.
 *
 * The rules (at least one, primary enabled, the current primary cannot simply
 * be switched off, disabling never deletes a translation) live in
 * {@see UpdateContentLanguages}; this component only collects the choice.
 * Kurdish Sorani is `ckb` internally and always shown as "KU".
 */
final class Languages extends Component
{
    /** @var list<string> */
    public array $enabledLocales = [];

    public string $primaryLocale = 'en';

    public string $notice = '';

    public function mount(TenantLocales $locales): void
    {
        $this->viewer();
        $this->enabledLocales = $locales->enabled();
        $this->primaryLocale = $locales->default();
    }

    public function makePrimary(string $locale, LanguageRegistry $languages): void
    {
        if ($languages->supports($locale)) {
            $this->primaryLocale = $locale;

            if (! in_array($locale, $this->enabledLocales, true)) {
                $this->enabledLocales[] = $locale;
            }
        }
    }

    public function saveLanguages(UpdateContentLanguages $update, TenantLocales $locales): void
    {
        $this->resetErrorBag();
        $this->notice = '';

        try {
            $saved = $update($this->viewer(), $this->enabledLocales, $this->primaryLocale);
        } catch (AuthorizationException) {
            $this->addError('enabledLocales', __('manager_settings.errors.forbidden'));

            return;
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field === 'primary' ? 'primaryLocale' : 'enabledLocales', (string) ($messages[0] ?? ''));
            }

            return;
        }

        $locales->forget();
        $this->enabledLocales = $saved['enabled'];
        $this->primaryLocale = $saved['primary'];

        $this->notice = __('manager_settings.languages.saved');
    }

    public function render(LanguageRegistry $languages, TenantLocales $locales): View
    {
        $user = $this->viewer();
        $current = $locales->default();

        return view('livewire.center.settings.languages', [
            'canManage' => $user->hasPermission(Permission::SettingsManage),
            'options' => array_map(fn (string $locale): array => [
                'code' => $locale,
                'short' => $languages->shortLabel($locale),
                'native' => $languages->nativeName($locale),
                'icon' => $languages->icon($locale),
                'direction' => $languages->direction($locale),
                'enabled' => in_array($locale, $this->enabledLocales, true),
                'primary' => $locale === $this->primaryLocale,
                // The center's CURRENT primary cannot be switched off until
                // another language has been made primary.
                'locked' => $locale === $current && $locale === $this->primaryLocale,
            ], $languages->supported()),
            'primaryName' => $languages->nativeName($this->primaryLocale),
        ]);
    }

    private function viewer(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User && $user->hasPermission(Permission::SettingsView), 403);

        return $user;
    }
}
