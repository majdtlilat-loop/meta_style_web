<?php

declare(strict_types=1);

namespace App\Kernel\Platform\Currencies;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Money\Currency;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The platform currency catalog.
 *
 * Plans are priced and SaaS invoices are issued in any ENABLED catalog
 * currency. A center's own operational currency must additionally be one the
 * center money engine can hold ({@see Currency}), because that is what its
 * prices, sales and invoices are recorded in.
 *
 * Amounts are always integer minor units; the catalog's `decimals` is the
 * exponent. Once a plan or an invoice uses a currency its exponent is locked —
 * changing it would silently rescale every stored amount.
 */
final class PlatformCurrencies
{
    /** @var array<string, PlatformCurrency>|null */
    private ?array $memo = null;

    public function __construct(private readonly Audit $audit) {}

    /** @return Collection<int, PlatformCurrency> */
    public function all(): Collection
    {
        return collect($this->catalog())->values();
    }

    /** @return Collection<int, PlatformCurrency> */
    public function enabled(): Collection
    {
        return $this->all()->filter(fn (PlatformCurrency $currency): bool => $currency->is_enabled)->values();
    }

    /** @return list<string> */
    public function enabledCodes(): array
    {
        return $this->enabled()->pluck('code')->values()->all();
    }

    /**
     * Codes a center may use as its operational currency.
     *
     * @return list<string>
     */
    public function centerCodes(): array
    {
        return array_values(array_filter($this->enabledCodes(), fn (string $code): bool => Currency::tryFrom($code) instanceof Currency));
    }

    public function find(string $code): ?PlatformCurrency
    {
        return $this->catalog()[mb_strtoupper($code)] ?? null;
    }

    public function defaultCode(): string
    {
        $default = $this->all()->first(fn (PlatformCurrency $currency): bool => $currency->is_default && $currency->is_enabled);

        return $default instanceof PlatformCurrency ? $default->code : Currency::default()->value;
    }

    public function decimals(string $code): int
    {
        $currency = $this->find($code);
        if ($currency instanceof PlatformCurrency) {
            return $currency->decimals;
        }

        return Currency::tryFrom(mb_strtoupper($code))?->exponent() ?? 2;
    }

    public function symbol(string $code, ?string $locale = null): string
    {
        $known = Currency::tryFrom(mb_strtoupper($code));
        if ($known instanceof Currency) {
            return $known->symbol($locale);
        }

        return $this->find($code)->symbol ?? mb_strtoupper($code);
    }

    /** "25,000 IQD", "$12.50 " — the same shape Kernel\Money renders. */
    public function format(int $minor, string $code, ?string $locale = null): string
    {
        $decimals = $this->decimals($code);
        $sign = $minor < 0 ? '-' : '';
        $absolute = abs($minor);
        $subunits = 10 ** $decimals;
        $whole = number_format(intdiv($absolute, $subunits));
        $number = $decimals === 0 ? $whole : $whole.'.'.str_pad((string) ($absolute % $subunits), $decimals, '0', STR_PAD_LEFT);

        return $sign.$number.' '.$this->symbol($code, $locale);
    }

    /** The plain major-unit string for a form field: 25000, 12.50. */
    public function major(int $minor, string $code): string
    {
        $decimals = $this->decimals($code);
        if ($decimals === 0) {
            return (string) $minor;
        }
        $subunits = 10 ** $decimals;

        return intdiv($minor, $subunits).'.'.str_pad((string) (abs($minor) % $subunits), $decimals, '0', STR_PAD_LEFT);
    }

    /**
     * Minor units from what a person typed.
     *
     * @throws InvalidArgumentException with a translated message
     */
    public function parse(string $typed, string $code): int
    {
        $normalized = strtr(trim($typed), [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٫' => '.', '٬' => '', ',' => '', ' ' => '', "\u{00A0}" => '', "\u{202F}" => '',
        ]);
        if (preg_match('/^\d+(\.\d+)?$/', $normalized) !== 1) {
            throw new InvalidArgumentException(__('ui.errors.amount'));
        }
        $decimals = $this->decimals($code);
        [$whole, $fraction] = str_contains($normalized, '.') ? explode('.', $normalized, 2) : [$normalized, ''];
        if (mb_strlen($fraction) > $decimals) {
            throw new InvalidArgumentException(__('ui.errors.amount_decimals', ['currency' => mb_strtoupper($code), 'places' => $decimals]));
        }

        return (int) ($whole.str_pad($fraction, $decimals, '0'));
    }

    /**
     * Adds or updates a catalog entry.
     *
     * @param  array<string, string>  $name
     */
    public function save(string $code, array $name, string $symbol, int $decimals, bool $enabled, int $sortOrder, Actor $actor): PlatformCurrency
    {
        $code = mb_strtoupper(trim($code));
        $symbol = trim($symbol);
        if (preg_match('/^[A-Z]{3}$/', $code) !== 1 || trim($name['en'] ?? '') === '' || $symbol === '' || mb_strlen($symbol) > 12 || $decimals < 0 || $decimals > 3) {
            throw new DomainException(__('sadmin_currencies.errors.invalid'));
        }
        $known = Currency::tryFrom($code);
        if ($known instanceof Currency && $known->exponent() !== $decimals) {
            throw new DomainException(__('sadmin_currencies.errors.decimals_fixed', ['places' => $known->exponent()]));
        }

        /** @var PlatformCurrency|null $existing */
        $existing = PlatformCurrency::query()->where('code', $code)->first();
        $before = $existing?->only(['name', 'symbol', 'decimals', 'is_enabled', 'sort_order']);
        if ($existing instanceof PlatformCurrency) {
            if ($existing->decimals !== $decimals && $this->inUse($code)) {
                throw new DomainException(__('sadmin_currencies.errors.decimals_locked'));
            }
            if (! $enabled) {
                $this->assertCanDisable($existing);
            }
        }

        /** @var PlatformCurrency $currency */
        $currency = PlatformCurrency::query()->updateOrCreate(['code' => $code], [
            'name' => array_map(static fn ($value): string => trim((string) $value), $name),
            'symbol' => $symbol,
            'decimals' => $decimals,
            'is_enabled' => $enabled,
            'sort_order' => max(0, $sortOrder),
        ]);
        $this->memo = null;

        $this->audit->record(new AuditEvent(
            action: $existing instanceof PlatformCurrency ? 'platform.currency.updated' : 'platform.currency.added',
            category: AuditCategory::Config,
            actor: $actor,
            targetType: PlatformCurrency::class,
            targetId: $code,
            targetLabel: $code,
            before: $before === null ? null : ['name' => $existing->name->all(), 'symbol' => $before['symbol'], 'decimals' => $before['decimals'], 'is_enabled' => $before['is_enabled']],
            after: ['name' => $currency->name->all(), 'symbol' => $symbol, 'decimals' => $decimals, 'is_enabled' => $enabled],
        ));

        return $currency;
    }

    public function setDefault(string $code, Actor $actor): void
    {
        $currency = $this->find($code);
        if (! $currency instanceof PlatformCurrency || ! $currency->is_enabled) {
            throw new DomainException(__('sadmin_currencies.errors.default_enabled'));
        }
        $before = $this->defaultCode();
        DB::connection('control')->transaction(function () use ($currency): void {
            PlatformCurrency::query()->where('is_default', true)->update(['is_default' => false]);
            PlatformCurrency::query()->whereKey($currency->id)->update(['is_default' => true]);
        });
        $this->memo = null;

        $this->audit->record(new AuditEvent(
            action: 'platform.currency.default_changed',
            category: AuditCategory::Config,
            actor: $actor,
            targetType: PlatformCurrency::class,
            targetId: $currency->code,
            targetLabel: $currency->code,
            before: ['default' => $before],
            after: ['default' => $currency->code],
        ));
    }

    /**
     * Plans, invoices and centers that already hold amounts in this currency.
     *
     * @return array{plans: int, active_plans: int, invoices: int, centers: int}
     */
    public function usage(string $code): array
    {
        $code = mb_strtoupper($code);

        return [
            'plans' => DB::connection('control')->table('plans')->where('currency', $code)->count(),
            'active_plans' => DB::connection('control')->table('plans')->where('currency', $code)->where('is_active', true)->count(),
            'invoices' => DB::connection('control')->table('saas_invoices')->where('currency', $code)->count(),
            'centers' => DB::connection('control')->table('tenants')->where('currency', $code)->count(),
        ];
    }

    private function inUse(string $code): bool
    {
        $usage = $this->usage($code);

        return $usage['plans'] > 0 || $usage['invoices'] > 0 || $usage['centers'] > 0;
    }

    private function assertCanDisable(PlatformCurrency $currency): void
    {
        if ($currency->is_default) {
            throw new DomainException(__('sadmin_currencies.errors.disable_default'));
        }
        $usage = $this->usage($currency->code);
        if ($usage['active_plans'] > 0 || $usage['centers'] > 0) {
            throw new DomainException(__('sadmin_currencies.errors.disable_in_use'));
        }
    }

    /** @return array<string, PlatformCurrency> */
    private function catalog(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $this->memo = [];
        foreach (PlatformCurrency::query()->orderBy('sort_order')->orderBy('code')->get() as $currency) {
            $this->memo[$currency->code] = $currency;
        }

        return $this->memo;
    }
}
