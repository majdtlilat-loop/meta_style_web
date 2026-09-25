<?php

declare(strict_types=1);

namespace App\Modules\PlatformOperations\Application;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Platform\Announcements\PlatformAnnouncement;
use App\Kernel\SaaS\Models\Subscription;
use App\Kernel\Tenancy\Enums\ProvisioningStatus;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use App\Modules\PlatformOperations\Infrastructure\Jobs\DeliverPlatformAnnouncement;
use DomainException;

/**
 * Sends a Super Admin announcement to centers.
 *
 * Audiences: every center, a hand-picked list, or a filter (center status,
 * plan). Only provisioned, non-archived centers receive it. The message is
 * stored once in the control plane; one queued job per center hands it to
 * that center's staff through the center's own notification inbox — this
 * builds no second notification engine.
 */
final class SendPlatformAnnouncement
{
    public function __construct(private readonly Audit $audit) {}

    /**
     * @param  array<string, string>  $title
     * @param  array<string, string>  $body
     * @param  array{audience: 'all'|'selected'|'filtered', tenant_ids?: list<string>, status?: string|null, plan_id?: int|null}  $target
     */
    public function __invoke(array $title, array $body, string $severity, array $target, Actor $actor): PlatformAnnouncement
    {
        $title = $this->localized($title, 140);
        $body = $this->localized($body, 1000);
        if ($title['en'] === '' || $body['en'] === '') {
            throw new DomainException(__('sadmin_notifications.errors.english'));
        }
        if (! in_array($severity, ['info', 'important'], true)) {
            throw new DomainException(__('sadmin_notifications.errors.severity'));
        }

        $tenantIds = $this->recipients($target);
        if ($tenantIds === []) {
            throw new DomainException(__('sadmin_notifications.errors.no_centers'));
        }

        /** @var PlatformAnnouncement $announcement */
        $announcement = PlatformAnnouncement::query()->create([
            'title' => $title,
            'body' => $body,
            'severity' => $severity,
            'audience' => $target['audience'],
            'tenant_ids' => $target['audience'] === 'all' ? null : $tenantIds,
            'centers_count' => count($tenantIds),
            'created_by_id' => (string) $actor->id,
            'created_by_label' => (string) $actor->label,
            'sent_at' => now(),
        ]);

        foreach ($tenantIds as $tenantId) {
            DeliverPlatformAnnouncement::dispatch($announcement->uuid, $tenantId, $severity === 'important');
        }

        $this->audit->record(new AuditEvent(
            action: 'platform.announcement.sent',
            category: AuditCategory::Config,
            actor: $actor,
            targetType: PlatformAnnouncement::class,
            targetId: $announcement->uuid,
            targetLabel: $title['en'],
            after: ['audience' => $target['audience'], 'centers' => count($tenantIds), 'severity' => $severity],
        ));

        return $announcement;
    }

    /**
     * @param  array{audience: string, tenant_ids?: list<string>, status?: string|null, plan_id?: int|null}  $target
     * @return list<string>
     */
    public function recipients(array $target): array
    {
        $query = TenantModel::query()
            ->where('provisioning_status', ProvisioningStatus::Completed->value)
            ->whereIn('status', ['active', 'suspended']);

        if ($target['audience'] === 'selected') {
            $query->whereIn('id', $target['tenant_ids'] ?? []);
        } elseif ($target['audience'] === 'filtered') {
            if (($target['status'] ?? null) !== null && $target['status'] !== '') {
                $query->where('status', $target['status']);
            }
            if (($target['plan_id'] ?? null) !== null) {
                $query->whereIn('id', Subscription::query()->where('plan_id', $target['plan_id'])->select('tenant_id'));
            }
        } elseif ($target['audience'] !== 'all') {
            return [];
        }

        return array_values(array_map('strval', $query->pluck('id')->all()));
    }

    /**
     * @param  array<string, string>  $values
     * @return array<string, string>
     */
    private function localized(array $values, int $max): array
    {
        $clean = [];
        foreach (['en', 'ar', 'ckb'] as $locale) {
            $value = trim((string) ($values[$locale] ?? ''));
            if ($value !== strip_tags($value) || mb_strlen($value) > $max) {
                throw new DomainException(__('sadmin_notifications.errors.text'));
            }
            $clean[$locale] = $value;
        }

        return $clean;
    }
}
