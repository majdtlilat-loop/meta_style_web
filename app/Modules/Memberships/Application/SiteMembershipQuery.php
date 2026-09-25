<?php

declare(strict_types=1);

namespace App\Modules\Memberships\Application;

use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Money\Currency;
use App\Kernel\Money\Money;
use App\Modules\Memberships\Contracts\SiteMembershipReader;
use App\Modules\Memberships\Domain\Models\MembershipPlan;

/**
 * {@see SiteMembershipReader} over the tenant's membership plans.
 */
final class SiteMembershipQuery implements SiteMembershipReader
{
    public function __construct(private readonly Entitlements $entitlements) {}

    public function references(): array
    {
        $references = [];
        foreach (MembershipPlan::query()->get(['id', 'uuid', 'archived_at']) as $plan) {
            $references[$plan->uuid] = $plan->archived_at === null;
        }

        return $references;
    }

    public function offered(): bool
    {
        return $this->entitlements->enabled('memberships');
    }

    public function options(string $locale): array
    {
        return MembershipPlan::query()->active()->orderBy('sort_order')->orderBy('id')->get(['id', 'uuid', 'name'])
            ->map(fn (MembershipPlan $plan): array => ['uuid' => $plan->uuid, 'name' => $plan->name->get($locale)])
            ->values()->all();
    }

    public function plans(?array $uuids, string $locale): array
    {
        if ($uuids === [] || ! $this->offered()) {
            return [];
        }

        $query = MembershipPlan::query()->active()->withCount('benefits')->orderBy('sort_order')->orderBy('id');
        if ($uuids !== null) {
            $query->whereIn('uuid', $uuids);
        }
        $currency = Currency::default();

        return $query->get()->map(fn (MembershipPlan $plan): array => [
            'uuid' => $plan->uuid,
            'name' => $plan->name->get($locale),
            'price' => Money::fromMinor($plan->price_minor, $currency)->formatted($locale),
            'duration_days' => $plan->duration_days,
            'benefits' => (int) $plan->getAttribute('benefits_count'),
        ])->values()->all();
    }
}
