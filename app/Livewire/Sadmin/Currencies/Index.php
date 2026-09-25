<?php

declare(strict_types=1);

namespace App\Livewire\Sadmin\Currencies;

use App\Kernel\Audit\Actor;
use App\Kernel\Money\Currency;
use App\Kernel\Platform\Currencies\PlatformCurrencies;
use App\Kernel\Platform\Currencies\PlatformCurrency;
use App\Livewire\Sadmin\Concerns\AuthorizesPlatform;
use DomainException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The currencies Meta Style prices plans and bills centers in, and which of
 * them a center may run its own business in.
 */
#[Layout('layouts.superadmin.app')]
final class Index extends Component
{
    use AuthorizesPlatform;

    /** `create`, `edit:<code>`, `default:<code>`. */
    public ?string $panel = null;

    public string $code = '';

    /** @var array<string, string> */
    public array $name = ['en' => '', 'ar' => '', 'ckb' => ''];

    public string $symbol = '';

    public int $decimals = 2;

    public bool $enabled = true;

    public int $sortOrder = 0;

    public function openPanel(string $panel, PlatformCurrencies $currencies): void
    {
        $this->requirePlatformPermission('platform.settings.manage');
        $this->closePanel();
        [$kind, $code] = array_pad(explode(':', $panel, 2), 2, '');
        if ($kind === 'edit') {
            $currency = $currencies->find($code) ?? abort(404);
            $this->code = $currency->code;
            $this->name = array_merge(['en' => '', 'ar' => '', 'ckb' => ''], $currency->name->all());
            $this->symbol = $currency->symbol;
            $this->decimals = $currency->decimals;
            $this->enabled = $currency->is_enabled;
            $this->sortOrder = $currency->sort_order;
        }
        if ($kind === 'create') {
            $this->sortOrder = $currencies->all()->count() + 1;
        }
        $this->panel = $panel;
    }

    public function closePanel(): void
    {
        $this->panel = null;
        $this->reset('code', 'name', 'symbol', 'decimals', 'enabled', 'sortOrder');
        $this->resetValidation();
    }

    public function updatedCode(): void
    {
        $this->code = mb_strtoupper(trim($this->code));
        $known = Currency::tryFrom($this->code);
        if ($known instanceof Currency) {
            $this->decimals = $known->exponent();
            if ($this->symbol === '') {
                $this->symbol = $known->symbol('en');
            }
        }
    }

    public function save(PlatformCurrencies $currencies): void
    {
        $user = $this->requirePlatformPermission('platform.settings.manage');
        $this->validate([
            'code' => ['required', 'string', 'size:3', 'alpha'],
            'name.en' => ['required', 'string', 'max:80'],
            'name.ar' => ['nullable', 'string', 'max:80'],
            'name.ckb' => ['nullable', 'string', 'max:80'],
            'symbol' => ['required', 'string', 'max:12'],
            'decimals' => ['required', 'integer', 'min:0', 'max:3'],
            'enabled' => ['boolean'],
            'sortOrder' => ['required', 'integer', 'min:0', 'max:999'],
        ], [], [
            'code' => __('sadmin_currencies.fields.code'),
            'name.en' => __('sadmin_currencies.fields.name'),
            'symbol' => __('sadmin_currencies.fields.symbol'),
            'decimals' => __('sadmin_currencies.fields.decimals'),
            'sortOrder' => __('sadmin_currencies.fields.order'),
        ]);

        try {
            $currencies->save($this->code, $this->name, $this->symbol, $this->decimals, $this->enabled, $this->sortOrder, Actor::platform($user));
        } catch (DomainException $exception) {
            $this->addError('code', $exception->getMessage());

            return;
        }

        $this->closePanel();
        session()->flash('notice', __('sadmin_currencies.saved'));
    }

    public function toggle(string $code, PlatformCurrencies $currencies): void
    {
        $user = $this->requirePlatformPermission('platform.settings.manage');
        $currency = $currencies->find($code) ?? abort(404);
        try {
            $currencies->save($currency->code, $currency->name->all(), $currency->symbol, $currency->decimals, ! $currency->is_enabled, $currency->sort_order, Actor::platform($user));
        } catch (DomainException $exception) {
            session()->flash('notice-error', $exception->getMessage());

            return;
        }
        session()->flash('notice', $currency->is_enabled ? __('sadmin_currencies.disabled') : __('sadmin_currencies.enabled'));
    }

    public function makeDefault(PlatformCurrencies $currencies): void
    {
        $user = $this->requirePlatformPermission('platform.settings.manage');
        $code = substr((string) $this->panel, 8);
        try {
            $currencies->setDefault($code, Actor::platform($user));
        } catch (DomainException $exception) {
            $this->addError('code', $exception->getMessage());

            return;
        }
        $this->closePanel();
        session()->flash('notice', __('sadmin_currencies.default_changed', ['code' => $code]));
    }

    public function render(PlatformCurrencies $currencies): mixed
    {
        $this->requirePlatformPermission('platform.settings.manage');

        return view('livewire.sadmin.currencies.index', [
            'currencies' => $currencies->all(),
            'usage' => $currencies->all()->mapWithKeys(fn (PlatformCurrency $currency): array => [$currency->code => $currencies->usage($currency->code)])->all(),
            'centerSupported' => Currency::codes(),
            'locked' => $this->code !== '' && ($currencies->usage($this->code)['plans'] > 0 || $currencies->usage($this->code)['invoices'] > 0 || $currencies->usage($this->code)['centers'] > 0 || Currency::tryFrom($this->code) instanceof Currency),
        ]);
    }
}
