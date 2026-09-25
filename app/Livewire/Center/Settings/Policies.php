<?php

declare(strict_types=1);

namespace App\Livewire\Center\Settings;

use App\Kernel\Appearance\AppearanceRejected;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\LanguageRegistry;
use App\Kernel\Localization\TenantLocales;
use App\Livewire\Center\Appearance\Support\MenuOptions;
use App\Modules\Menu\Application\Actions\SavePublicPolicies;
use App\Modules\Menu\Application\PublicPageAppearance;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Settings → Policies: the cancellation and terms texts a guest reads on the
 * booking page, in each content language.
 *
 * Words to customers, not rules: the notice periods the Booking Engine
 * enforces are in Settings → Booking. Texts for a language that is switched
 * off are kept and saved back untouched.
 */
final class Policies extends Component
{
    /** @var array<string, array<string, string>> */
    public array $texts = [];

    public string $notice = '';

    public string $noticeTone = 'success';

    public function mount(PublicPageAppearance $pages, LanguageRegistry $languages): void
    {
        $this->viewer();
        $this->fillFrom($pages, $languages);
    }

    public function save(SavePublicPolicies $save, PublicPageAppearance $pages, LanguageRegistry $languages): void
    {
        $this->resetErrorBag();

        try {
            $save($this->viewer(), $this->texts);
        } catch (AuthorizationException) {
            $this->notice = __('manager_settings.errors.forbidden');
            $this->noticeTone = 'danger';

            return;
        } catch (AppearanceRejected $e) {
            $this->addError($e->field, MenuOptions::appearanceMessage($e));

            return;
        }

        $this->fillFrom($pages, $languages);
        $this->notice = __('manager_settings.policies.saved');
        $this->noticeTone = 'success';
    }

    public function render(TenantLocales $locales, PublicPageAppearance $pages): View
    {
        $user = $this->viewer();
        $schema = $pages->schema('policies');

        return view('livewire.center.settings.policies', [
            'canManage' => $user->hasPermission(Permission::SettingsManage),
            'contentLocales' => $locales->enabled(),
            'primaryLocale' => $locales->default(),
            'fields' => [
                ['name' => 'texts.cancellation', 'label' => __('manager_settings.policies.cancellation'), 'type' => 'textarea', 'rows' => 5, 'max' => $schema->texts['cancellation'], 'counter' => true],
                ['name' => 'texts.terms', 'label' => __('manager_settings.policies.terms'), 'type' => 'textarea', 'rows' => 7, 'max' => $schema->texts['terms'], 'counter' => true],
            ],
            'values' => [
                'texts.cancellation' => $this->texts['cancellation'] ?? [],
                'texts.terms' => $this->texts['terms'] ?? [],
            ],
            'appearanceUrl' => $user->hasPermission(Permission::AppearanceView) ? route('center.appearance.booking') : null,
        ]);
    }

    private function fillFrom(PublicPageAppearance $pages, LanguageRegistry $languages): void
    {
        $policies = $pages->get('policies');

        foreach (['cancellation', 'terms'] as $key) {
            foreach ($languages->supported() as $locale) {
                $this->texts[$key][$locale] = $policies->texts($key)[$locale] ?? '';
            }
        }
    }

    private function viewer(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User && $user->hasPermission(Permission::SettingsView), 403);

        return $user;
    }
}
