<?php

declare(strict_types=1);

namespace App\Livewire\Center\Dashboard;

use App\Kernel\SaaS\CurrentSubscription;
use App\Kernel\SaaS\Enums\SubscriptionStatus;
use App\View\Label;
use Illuminate\Support\Facades\Route;

/**
 * What the overview says about the center's own plan: a translated status
 * chip (never the raw `trialing`), the plan name in the reader's language,
 * the trial days left or the renewal date. The account-wide banner for a
 * limited subscription (past due, suspended, ended) is the shell's own
 * (App\View\Manager\SubscriptionBanner), so the overview never repeats it.
 *
 * Read from the control plane through CurrentSubscription (memoised per
 * request). Presentation only — entitlements, not this, decide what the
 * center may use.
 */
final class PlanStatus
{
    public function __construct(private readonly CurrentSubscription $subscription) {}

    /**
     * The chip, for viewers who may see the center's plan (`settings.view`).
     *
     * @return array{status: string, label: string, name: string, detail: string|null, href: string|null}|null
     */
    public function chip(string $locale): ?array
    {
        $summary = $this->subscription->summary();

        if ($summary === null) {
            return null;
        }

        $detail = null;

        if ($summary->status->isTrial() && $summary->trialDaysLeft !== null) {
            $detail = trans_choice('manager_dashboard.plan.trial_days_left', $summary->trialDaysLeft, ['count' => $summary->trialDaysLeft]);
        } elseif ($summary->status === SubscriptionStatus::Active && $summary->renewsAt !== null) {
            $detail = __('manager_dashboard.plan.renews', ['date' => $summary->renewsAt->locale($locale)->isoFormat('D MMM YYYY')]);
        }

        return [
            'status' => $summary->status->value,
            'label' => Label::for('subscription_status', $summary->status->value),
            'name' => $summary->planName($locale),
            'detail' => $detail,
            'href' => Route::has('center.plan') ? route('center.plan') : null,
        ];
    }
}
