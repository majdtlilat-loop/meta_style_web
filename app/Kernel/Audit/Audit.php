<?php

declare(strict_types=1);

namespace App\Kernel\Audit;

use App\Kernel\Audit\Models\PlatformAuditLog;
use App\Kernel\Audit\Models\TenantAuditLog;
use App\Kernel\Observability\RequestId;
use App\Kernel\Tenancy\Contracts\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Records audit entries.
 *
 * Called explicitly from the code that knows what happened and why — never
 * from a global model observer. Observers cannot supply a reason, cannot tell
 * a customer cancelling from a job expiring, and silently miss every
 * query-builder write, which produces a log that is simultaneously enormous
 * and useless (ADR-010).
 *
 * Routing:
 *   tenant bound   → the tenant's own audit_logs, so the center can see it
 *   platform-side  → platform_audit_logs in the control plane
 *
 * A tenant-scoped platform action (provisioning, migrating) is written to BOTH,
 * joined by correlation id, so the center has visibility and the platform keeps
 * its own independent record (docs/08-AUDIT-SECURITY.md §2).
 */
final class Audit
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly RequestId $requestId,
        private readonly Redactor $redactor,
    ) {}

    /**
     * Records an entry wherever it belongs for the current context.
     */
    public function record(AuditEvent $event): void
    {
        if ($this->tenants->isBound()) {
            $this->writeToTenant($event);

            return;
        }

        $this->writeToPlatform($event, null);
    }

    /**
     * Records a platform action performed against a specific tenant.
     *
     * Used by provisioning and migrations, where the tenant is known but no
     * tenant context is (or should be) initialised.
     */
    public function recordForTenant(string $tenantId, AuditEvent $event): void
    {
        $this->writeToPlatform($event, $tenantId);
    }

    private function writeToPlatform(AuditEvent $event, ?string $tenantId): void
    {
        PlatformAuditLog::create($this->attributes($event) + [
            'tenant_id' => $tenantId ?? $this->tenants->id(),
        ]);
    }

    private function writeToTenant(AuditEvent $event): void
    {
        TenantAuditLog::create($this->attributes($event));
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(AuditEvent $event): array
    {
        $request = $this->currentRequest();

        return [
            'uuid' => (string) Str::uuid(),
            // The action's time, not the row's.
            'occurred_at' => Carbon::now(),
            'correlation_id' => $this->correlationUuid(),

            'actor_type' => $event->actor->type->value,
            'actor_id' => $event->actor->id,
            'actor_label' => $event->actor->label,
            'source' => $event->actor->source->value,

            'action' => $event->action,
            'category' => $event->category->value,
            'severity' => $event->severity->value,

            'target_type' => $event->targetType,
            'target_id' => $event->targetId,
            'target_label' => $event->targetLabel,

            'before' => $this->redactor->redact($event->before),
            'after' => $this->redactor->redact($event->after),
            'meta' => $this->redactor->redact($event->meta),

            'reason' => $event->reason,

            'ip' => $request?->ip(),
            'user_agent' => $request === null ? null : Str::limit((string) $request->userAgent(), 250, ''),
            'session_id' => $request !== null && $request->hasSession() ? $request->session()->getId() : null,
        ];
    }

    /**
     * The correlation id column is a UUID, but a client may supply any safe
     * token as X-Request-Id. Non-UUID ids are kept in `meta` rather than
     * dropped, so the trail is still followable.
     */
    private function correlationUuid(): ?string
    {
        $value = $this->requestId->value();

        return Str::isUuid($value) ? $value : null;
    }

    private function currentRequest(): ?Request
    {
        return app()->bound('request') ? app('request') : null;
    }
}
