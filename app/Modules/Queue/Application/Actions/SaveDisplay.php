<?php

declare(strict_types=1);

namespace App\Modules\Queue\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\LanguageRegistry;
use App\Kernel\Localization\TenantLocales;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Departments\Domain\Models\Department;
use App\Modules\Queue\Domain\Exceptions\QueueFailed;
use App\Modules\Queue\Domain\Models\QueueDisplay;
use App\Modules\Queue\Domain\Models\QueueServicePoint;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Configure a television.
 *
 * ## Scope is validated against the branch, not merely recorded
 *
 * A display shows one branch. If it is narrowed to a department or a service
 * point, that department must exist and that service point must stand at THIS
 * branch — otherwise a screen at Karrada would be showing Mansour's calls,
 * which is the cross-branch leak §20 forbids outright (docs/17-QUEUE.md §9).
 *
 * Only one narrowing at a time. "Department AND service point" is a filter
 * nobody has asked for, and allowing both would mean deciding what their
 * intersection means on a screen a customer is reading.
 *
 * ## No markup, ever
 *
 * A center configures which calls to show, in which language, with sound on or
 * off. It does not supply HTML, CSS or JavaScript: a display is a guest-facing
 * page, and center-authored script on one is stored XSS against that center's
 * own customers (ADR-038).
 *
 * ## Rotating languages
 *
 * A screen may cycle its labels through several of the CENTER's languages
 * (`rotation_*`). Every language named must be one the center has switched on
 * — a screen can never switch on a language the center has not — and at least
 * two are needed, or there is nothing to rotate. The interval is clamped
 * (5–60 s). Presentation only: the ticket voice keeps its own
 * `voice_locales` (docs/17-QUEUE.md §§9, 16).
 *
 * ## Rotating the key
 *
 * `rotate()` replaces the public identifier and nothing else. A screen that was
 * stolen, or a link that was shared, stops working immediately without
 * disturbing anything that references this row (ADR-027).
 */
final class SaveDisplay
{
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly LanguageRegistry $languages,
        private readonly TenantLocales $centerLocales,
        private readonly Audit $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws QueueFailed
     * @throws AuthorizationException
     */
    public function __invoke(array $input, User $actingUser, ?QueueDisplay $display = null): QueueDisplay
    {
        $this->authorize($actingUser);

        $branch = $display instanceof QueueDisplay
            ? $display->branch
            : $this->branch($input['branch'] ?? null);

        if (! $branch instanceof Branch) {
            throw QueueFailed::policy('That branch was not found.');
        }

        if (! $actingUser->canAccessBranch((int) $branch->getKey())) {
            throw new AuthorizationException('You may not work in that branch.');
        }

        $department = $this->department($input['department'] ?? null);
        $point = $this->servicePoint($input['service_point'] ?? null, $branch);

        if ($department instanceof Department && $point instanceof QueueServicePoint) {
            throw QueueFailed::policy('A display shows a department or a service point, not both.');
        }

        $name = trim((string) ($input['name'] ?? $display->name ?? ''));

        if ($name === '') {
            throw QueueFailed::policy('A display needs a name, so staff can tell the screens apart.');
        }

        $attributes = [
            'branch_id' => $branch->getKey(),
            'name' => mb_substr($name, 0, 190),
            'department_id' => $department?->getKey(),
            'service_point_id' => $point?->getKey(),
            'locale' => $this->locale($input['locale'] ?? null, $display),
            'recent_calls_limit' => $this->recentLimit($input['recent_calls_limit'] ?? null, $display),
            'sound_enabled' => (bool) ($input['sound_enabled'] ?? $display->sound_enabled ?? true),
            'voice_enabled' => (bool) ($input['voice_enabled'] ?? $display->voice_enabled ?? true),
            'voice_locales' => $this->voiceLocales($input['voice_locales'] ?? null, $display),
            'is_active' => (bool) ($input['is_active'] ?? $display->is_active ?? true),
            ...$this->rotation($input, $display),
        ];

        if ($display instanceof QueueDisplay) {
            $display->forceFill($attributes)->save();
            $saved = $display;
        } else {
            /** @var QueueDisplay $saved */
            $saved = QueueDisplay::query()->create($attributes);
        }

        $this->audit->record(new AuditEvent(
            action: $display instanceof QueueDisplay ? 'queue.display.updated' : 'queue.display.created',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: QueueDisplay::class,
            targetId: $saved->uuid,
            targetLabel: $saved->name,
            after: [
                'branch_id' => $saved->branch_id,
                'department_id' => $saved->department_id,
                'service_point_id' => $saved->service_point_id,
                'locale' => $saved->locale,
                'is_active' => $saved->is_active,
                'voice_enabled' => $saved->voice_enabled,
                'rotation_enabled' => $saved->rotation_enabled,
                'rotation_locales' => $saved->rotationLocales(),
                // The public key is NOT audited. An audit row is readable by
                // more people than a display URL should be.
            ],
        ));

        return $saved;
    }

    /**
     * @throws AuthorizationException
     */
    public function rotate(QueueDisplay $display, User $actingUser): QueueDisplay
    {
        $this->authorize($actingUser);

        if (! $actingUser->canAccessBranch((int) $display->branch_id)) {
            throw new AuthorizationException('You may not work in that branch.');
        }

        $display->forceFill(['public_key' => QueueDisplay::newPublicKey()])->save();

        $this->audit->record(new AuditEvent(
            action: 'queue.display.key_rotated',
            category: AuditCategory::Security,
            actor: Actor::staff($actingUser),
            targetType: QueueDisplay::class,
            targetId: $display->uuid,
            targetLabel: $display->name,
        ));

        return $display;
    }

    /**
     * @throws AuthorizationException
     */
    private function authorize(User $actingUser): void
    {
        $this->entitlements->ensure('queue_management');

        if (! $actingUser->hasPermission(Permission::QueueDisplayManage)) {
            throw new AuthorizationException('You may not manage queue displays.');
        }
    }

    private function branch(mixed $uuid): ?Branch
    {
        if (! is_string($uuid) || $uuid === '') {
            return null;
        }

        return Branch::query()->where('uuid', $uuid)->first();
    }

    /**
     * @throws QueueFailed
     */
    private function department(mixed $uuid): ?Department
    {
        if (! is_string($uuid) || $uuid === '') {
            return null;
        }

        $department = Department::query()->where('uuid', $uuid)->first();

        if (! $department instanceof Department) {
            throw QueueFailed::policy('That department was not found.');
        }

        return $department;
    }

    /**
     * @throws QueueFailed
     */
    private function servicePoint(mixed $uuid, Branch $branch): ?QueueServicePoint
    {
        if (! is_string($uuid) || $uuid === '') {
            return null;
        }

        $point = QueueServicePoint::query()
            ->where('uuid', $uuid)
            ->where('branch_id', $branch->getKey())
            ->first();

        if (! $point instanceof QueueServicePoint) {
            throw QueueFailed::policy('That service point is not available at this branch.');
        }

        return $point;
    }

    /**
     * @throws QueueFailed
     */
    private function locale(mixed $locale, ?QueueDisplay $display): ?string
    {
        if ($locale === null) {
            return $display?->locale;
        }

        if (! is_string($locale) || $locale === '') {
            return null;
        }

        // The language registry is the authority, so direction and font stack
        // follow automatically and nobody hardcodes a list of RTL codes
        // (docs/07-LOCALIZATION.md §10).
        if (! $this->languages->supports($locale)) {
            throw QueueFailed::policy('That language is not one this platform knows.');
        }

        return $locale;
    }

    private function recentLimit(mixed $limit, ?QueueDisplay $display): int
    {
        if (! is_numeric($limit)) {
            return $display->recent_calls_limit ?? 5;
        }

        // Clamped, not trusted: a stored 0 blanks the screen and a stored 500
        // makes every three-second poll read a day of tickets (§19).
        return max(1, min(QueueDisplay::MAX_RECENT, (int) $limit));
    }

    /**
     * Language rotation: the center's own languages only, at least two, and a
     * clamped interval. Keys left out keep what the screen has.
     *
     * A language already on the screen's list may stay there after the center
     * switches it off — disabling never deletes a choice, and turning the
     * language back on restores it (DisplayLanguages). Only a NEWLY added
     * language must be one the center has on.
     *
     * @param  array<string, mixed>  $input
     * @return array{rotation_enabled: bool, rotation_locales: list<string>|null, rotation_seconds: int}
     *
     * @throws QueueFailed
     */
    private function rotation(array $input, ?QueueDisplay $display): array
    {
        $enabled = (bool) ($input['rotation_enabled'] ?? $display->rotation_enabled ?? false);

        $locales = $display?->rotation_locales;
        $stored = $display?->rotationLocales() ?? [];

        if (array_key_exists('rotation_locales', $input)) {
            $locales = [];

            foreach (is_array($input['rotation_locales']) ? $input['rotation_locales'] : [] as $locale) {
                if (! is_string($locale) || $locale === '') {
                    continue;
                }

                if (! $this->languages->supports($locale)) {
                    throw QueueFailed::policy('That language is not one this platform knows.');
                }

                // The center decides which languages exist for it; a screen
                // only chooses among them (docs/07-LOCALIZATION.md §4).
                if (! $this->centerLocales->isEnabled($locale) && ! in_array($locale, $stored, true)) {
                    throw QueueFailed::policy('That language is not switched on for this center.');
                }

                $locales[] = $locale;
            }

            $locales = array_values(array_unique($locales));
        }

        if ($enabled && count($locales ?? []) < 2) {
            throw QueueFailed::policy('Choose at least two languages to rotate.');
        }

        $seconds = $input['rotation_seconds'] ?? null;

        return [
            'rotation_enabled' => $enabled,
            'rotation_locales' => $locales === [] ? null : $locales,
            // Clamped, not trusted: 0 would strobe the screen, 255 would
            // strand a room in a language most of it cannot read.
            'rotation_seconds' => is_numeric($seconds)
                ? max(QueueDisplay::MIN_ROTATION_SECONDS, min(QueueDisplay::MAX_ROTATION_SECONDS, (int) $seconds))
                : ($display?->rotationSeconds() ?? 10),
        ];
    }

    /**
     * @return list<string>|null
     *
     * @throws QueueFailed
     */
    private function voiceLocales(mixed $locales, ?QueueDisplay $display): ?array
    {
        if ($locales === null) {
            return $display?->voice_locales;
        }

        if (! is_array($locales)) {
            return null;
        }

        $clean = [];

        foreach ($locales as $locale) {
            if (! is_string($locale) || $locale === '') {
                continue;
            }

            if (! $this->languages->supports($locale)) {
                throw QueueFailed::policy('That language is not one this platform knows.');
            }

            $clean[] = $locale;
        }

        return array_values(array_unique($clean));
    }
}
