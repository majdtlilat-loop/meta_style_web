<?php

declare(strict_types=1);

namespace App\Kernel\SaaS;

use App\Kernel\Entitlements\TenantAccessLevel;
use App\Kernel\SaaS\Enums\SubscriptionStatus;
use App\Kernel\SaaS\Models\Plan;
use Carbon\CarbonInterface;

/**
 * What a center is subscribed to, as the center itself may see it: plan,
 * effective status, cycle, the price it was sold at, and the dates that
 * matter (trial end, renewal, grace, a scheduled plan change).
 *
 * Read-only. Nothing here changes a subscription — plan changes are platform
 * actions (Super Admin), never a center's.
 */
final readonly class SubscriptionSummary
{
    public function __construct(
        public ?Plan $plan,
        /** @var array<string, string> Plan name per locale, as sold (snapshot first). */
        public array $planName,
        public SubscriptionStatus $status,
        public TenantAccessLevel $accessLevel,
        /** 'monthly' | 'yearly' | null when the plan has no billed cycle. */
        public ?string $cycle,
        public ?int $priceMinor,
        public ?string $currency,
        public ?CarbonInterface $trialEndsAt,
        public ?int $trialDaysLeft,
        public ?CarbonInterface $renewsAt,
        public ?CarbonInterface $graceEndsAt,
        public ?Plan $scheduledPlan,
        public ?CarbonInterface $scheduledAt,
    ) {}

    /** The plan name in the reader's language, falling back to any recorded name. */
    public function planName(string $locale): string
    {
        return $this->planName[$locale]
            ?? $this->plan?->name->get($locale)
            ?? (array_values($this->planName)[0] ?? '');
    }
}
