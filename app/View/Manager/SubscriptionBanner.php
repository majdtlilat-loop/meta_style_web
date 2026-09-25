<?php

declare(strict_types=1);

namespace App\View\Manager;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\SaaS\Enums\SubscriptionStatus;
use App\Kernel\SaaS\SubscriptionSummary;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Route;

/**
 * The one account-wide line the shell shows about the subscription.
 *
 * Specific, never "needs attention": a trial with its days left, a past-due
 * account with the date its grace ends, a suspended (read-only) account, an
 * expired or cancelled one. An active subscription shows nothing.
 *
 * Who sees what: the trial and past-due lines are commercial news for the
 * people who can act on the plan (`settings.view`, the Plan page's own gate).
 * Suspension and expiry explain why the Manager refuses changes, so everybody
 * signed in sees them; only people who may open the Plan page get its link.
 */
final class SubscriptionBanner
{
    /** A trial reads as a warning from this many days left. */
    public const TRIAL_WARNING_DAYS = 3;

    /**
     * @return array{status: string, tone: string, icon: string, message: string, action: array{label: string, href: string}|null, support: array{label: string, href: string}|null}|null
     */
    public function for(?SubscriptionSummary $summary, User $viewer, string $locale, string $timezone): ?array
    {
        if ($summary === null) {
            return null;
        }

        $commercial = $viewer->hasPermission(Permission::SettingsView);
        $status = $summary->status;

        $message = match ($status) {
            SubscriptionStatus::Trialing => $commercial ? $this->trialMessage($summary, $locale, $timezone) : null,
            SubscriptionStatus::PastDue => $commercial
                ? ($summary->graceEndsAt !== null
                    ? __('manager_shell.banner.past_due_until', ['date' => $this->date($summary->graceEndsAt, $locale, $timezone)])
                    : __('manager_shell.banner.past_due'))
                : null,
            SubscriptionStatus::Suspended => __('manager_shell.banner.suspended'),
            SubscriptionStatus::Cancelled => __('manager_shell.banner.cancelled'),
            SubscriptionStatus::Expired => __('manager_shell.banner.expired'),
            SubscriptionStatus::Active => null,
        };

        if ($message === null) {
            return null;
        }

        $tone = match ($status) {
            SubscriptionStatus::Trialing => ($summary->trialDaysLeft ?? 0) <= self::TRIAL_WARNING_DAYS ? 'warning' : 'info',
            SubscriptionStatus::PastDue => 'warning',
            default => 'danger',
        };

        $plan = $commercial && Route::has('center.plan') ? route('center.plan') : null;
        $support = in_array($status, [SubscriptionStatus::Suspended, SubscriptionStatus::Cancelled, SubscriptionStatus::Expired, SubscriptionStatus::PastDue], true)
            && $viewer->hasPermission(Permission::PlatformSupportView) && Route::has('center.support')
                ? route('center.support')
                : null;

        return [
            'status' => $status->value,
            'tone' => $tone,
            'icon' => match ($tone) {
                'info' => 'clock',
                'warning' => 'alert-triangle',
                default => 'lock',
            },
            'message' => $message,
            'action' => $plan === null ? null : ['label' => __('manager_shell.banner.view_plan'), 'href' => $plan],
            'support' => $support === null ? null : ['label' => __('manager_shell.banner.contact'), 'href' => $support],
        ];
    }

    private function trialMessage(SubscriptionSummary $summary, string $locale, string $timezone): string
    {
        $days = $summary->trialDaysLeft;
        if ($days === null) {
            return __('manager_shell.banner.trial');
        }

        $ends = $summary->trialEndsAt !== null ? $this->date($summary->trialEndsAt, $locale, $timezone) : null;

        return $ends === null
            ? trans_choice('manager_shell.banner.trial_days', $days, ['count' => $days])
            : trans_choice('manager_shell.banner.trial_days_until', $days, ['count' => $days, 'date' => $ends]);
    }

    private function date(CarbonInterface $instant, string $locale, string $timezone): string
    {
        return CarbonImmutable::instance($instant)->setTimezone($timezone)->locale($locale)->translatedFormat('j F Y');
    }
}
