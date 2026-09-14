<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Kernel\SaaS\Models\Plan;
use App\Kernel\SaaS\Models\PlatformSetting;
use Illuminate\Database\Seeder;

/**
 * Control-plane reference data.
 *
 * Idempotent — safe to re-run after a deploy adds a plan or a setting. Uses a
 * stable natural key (the plan code) rather than an auto id, so re-running
 * updates rather than duplicates (docs/03-DATABASE-MIGRATIONS.md §8).
 *
 * Plans are DATA. The entitlement codes below are the whole definition of what
 * a plan sells; no code anywhere asks which plan a tenant is on.
 */
final class ControlPlaneSeeder extends Seeder
{
    public function run(): void
    {
        $this->settings();
        $this->plans();
    }

    private function settings(): void
    {
        if (PlatformSetting::get(PlatformSetting::DEFAULT_TRIAL_DAYS) === null) {
            PlatformSetting::put(
                PlatformSetting::DEFAULT_TRIAL_DAYS,
                (int) config('metastyle.saas.default_trial_days'),
            );
        }

        if (PlatformSetting::get(PlatformSetting::DEFAULT_PLAN_CODE) === null) {
            PlatformSetting::put(
                PlatformSetting::DEFAULT_PLAN_CODE,
                (string) config('metastyle.saas.default_plan_code'),
            );
        }
    }

    private function plans(): void
    {
        foreach ($this->definitions() as $code => $definition) {
            /** @var Plan $plan */
            $plan = Plan::query()->updateOrCreate(
                ['code' => $code],
                [
                    'name' => $definition['name'],
                    'price_minor' => $definition['price_minor'],
                    'currency' => 'IQD',
                    'billing_period' => 'monthly',
                    'trial_days' => $definition['trial_days'],
                    'is_public' => $definition['is_public'],
                    'is_active' => true,
                    'sort_order' => $definition['sort_order'],
                ],
            );

            $plan->syncEntitlements($definition['entitlements']);
        }
    }

    /**
     * @return array<string, array{name: array<string, string>, price_minor: int, trial_days: int|null, is_public: bool, sort_order: int, entitlements: list<string>}>
     */
    private function definitions(): array
    {
        return [
            // What a new center gets during its free trial. Deliberately
            // generous: the trial should show the product, not tease it.
            'trial' => [
                'name' => ['en' => 'Trial', 'ar' => 'تجريبي', 'ckb' => 'تاقیکردنەوە'],
                'price_minor' => 0,
                'trial_days' => null,
                'is_public' => false,
                'sort_order' => 0,
                'entitlements' => ['booking', 'customer_accounts', 'crm', 'pos'],
            ],

            'starter' => [
                'name' => ['en' => 'Starter', 'ar' => 'الأساسية', 'ckb' => 'دەستپێک'],
                'price_minor' => 50000,
                'trial_days' => null,
                'is_public' => true,
                'sort_order' => 1,
                'entitlements' => ['booking', 'customer_accounts'],
            ],

            'business' => [
                'name' => ['en' => 'Business', 'ar' => 'الأعمال', 'ckb' => 'بازرگانی'],
                'price_minor' => 150000,
                'trial_days' => null,
                'is_public' => true,
                'sort_order' => 2,
                'entitlements' => [
                    'booking', 'customer_accounts', 'crm', 'pos',
                    'printing', 'finance', 'payments',
                ],
            ],
        ];
    }
}
