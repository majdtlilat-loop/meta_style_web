<?php

declare(strict_types=1);

namespace App\Modules\Booking\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Identity\Models\User;
use App\Modules\Booking\Domain\BookingSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Changes the center's booking knobs — the five values {@see BookingSettings}
 * holds.
 *
 * The bands below are EXACTLY the clamps BookingSettings applies when it reads
 * a value. A value outside them is refused here with a message, rather than
 * stored and silently clamped later: an owner who types 500 days must be told
 * the limit, not discover that 365 is what customers get.
 *
 * `settings.manage` is the permission and `booking` the entitlement — losing it
 * stops the next change like every other booking operation, and the stored
 * values stay for when it comes back. Audited with before and after; these are
 * numbers, not personal data.
 */
final class UpdateBookingSettings
{
    /** @var array<string, array{0: int, 1: int}> field => [min, max] */
    public const BOUNDS = [
        'slot_interval_minutes' => [5, 120],
        'max_advance_days' => [1, 365],
        'min_lead_minutes' => [0, 60 * 24 * 30],
        'customer_cancel_notice_minutes' => [0, 60 * 24 * 30],
        'max_calendar_days' => [1, 92],
    ];

    public function __construct(
        private readonly BookingSettings $settings,
        private readonly Entitlements $entitlements,
        private readonly Audit $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $values  any subset of {@see BOUNDS}
     * @return array<string, int> the settings now in force
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $actingUser, array $values): array
    {
        if (! $actingUser->hasPermission(Permission::SettingsManage)) {
            throw new AuthorizationException(__('manager_settings.errors.forbidden'));
        }

        $this->entitlements->ensure('booking');

        $clean = [];
        $errors = [];

        foreach ($values as $field => $value) {
            $field = (string) $field;

            if (! isset(self::BOUNDS[$field])) {
                $errors[$field] = __('manager_settings.errors.unknown_setting');

                continue;
            }

            [$min, $max] = self::BOUNDS[$field];

            if (! is_int($value) && ! (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1)) {
                $errors[$field] = __('manager_settings.errors.whole_number', ['min' => $min, 'max' => $max]);

                continue;
            }

            $number = (int) $value;

            if ($number < $min || $number > $max) {
                $errors[$field] = __('manager_settings.errors.whole_number', ['min' => $min, 'max' => $max]);

                continue;
            }

            $clean[$field] = $number;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $before = $this->settings->all();
        $changed = array_filter($clean, static fn (int $value, string $field): bool => ($before[$field] ?? null) !== $value, ARRAY_FILTER_USE_BOTH);

        if ($changed === []) {
            return $before;
        }

        $this->settings->save($clean);
        $after = $this->settings->all();

        $this->audit->record(new AuditEvent(
            action: 'settings.booking.updated',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: BookingSettings::class,
            targetId: 'booking',
            before: array_intersect_key($before, $changed),
            after: array_intersect_key($after, $changed),
        ));

        return $after;
    }
}
