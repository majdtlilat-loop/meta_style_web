<?php

declare(strict_types=1);

namespace App\Kernel\Tenancy\Infrastructure;

use App\Kernel\Tenancy\Enums\OperationStatus;
use App\Kernel\Tenancy\Enums\OperationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * One recorded attempt at a tenancy lifecycle operation.
 *
 * Deliberately dumb: start(), succeed(), fail(). No state machine, no steps,
 * no orchestration — that would be a workflow engine, which this phase
 * explicitly does not need.
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $type
 * @property string $status
 * @property int $attempt
 * @property string|null $error
 */
final class TenantOperation extends Model
{
    protected $connection = 'control';

    protected $table = 'tenant_operations';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public static function start(
        string $tenantId,
        OperationType $type,
        ?string $correlationId = null,
    ): self {
        $attempt = self::query()
            ->where('tenant_id', $tenantId)
            ->where('type', $type->value)
            ->count() + 1;

        return self::create([
            'tenant_id' => $tenantId,
            'type' => $type->value,
            'status' => OperationStatus::Running->value,
            'attempt' => $attempt,
            'correlation_id' => $correlationId,
            'started_at' => Carbon::now(),
        ]);
    }

    public function succeed(): void
    {
        $this->update([
            'status' => OperationStatus::Succeeded->value,
            'error' => null,
            'finished_at' => Carbon::now(),
        ]);
    }

    public function fail(Throwable $e): void
    {
        $this->update([
            'status' => OperationStatus::Failed->value,
            'error' => self::sanitize($e),
            'finished_at' => Carbon::now(),
        ]);
    }

    /**
     * Driver exceptions can carry connection strings and credentials, so the
     * stored message is trimmed to the class, a bounded message, and the
     * origin (docs/08-AUDIT-SECURITY.md §17).
     */
    public static function sanitize(Throwable $e): string
    {
        $message = preg_replace('/\b(password|pwd|secret|token)\s*=\s*\S+/i', '$1=[redacted]', $e->getMessage()) ?? '';

        return sprintf(
            '%s: %s (%s:%d)',
            $e::class,
            mb_substr($message, 0, 500),
            basename($e->getFile()),
            $e->getLine(),
        );
    }
}
