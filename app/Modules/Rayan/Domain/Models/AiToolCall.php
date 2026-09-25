<?php

declare(strict_types=1);

namespace App\Modules\Rayan\Domain\Models;

use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One approved operation the assistant requested, and what happened.
 *
 * The audit trail for "the bot booked something". `tool` is a registry key, so
 * a row here can only ever name one of the ten allow-listed operations — a
 * model that asked for anything else was refused before a row existed
 * (docs/27-RAYAN.md §11).
 *
 * `arguments` are stored AFTER validation and allow-listing against the tool's
 * declared schema, so a model that invented an extra field cannot smuggle it
 * into this table. They are kept because "which service, at which branch, at
 * what time" is the only question anybody asks about an automated booking.
 *
 * @property int $id
 * @property string $uuid
 * @property int $ai_run_id
 * @property string $tool
 * @property string|null $provider_call_id
 * @property array<string, mixed>|null $arguments
 * @property string $result
 * @property string|null $refusal_code
 * @property int|null $duration_ms
 */
final class AiToolCall extends Model
{
    use UsesTenantConnection;

    protected $table = 'ai_tool_calls';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'arguments' => 'array',
            'duration_ms' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $call): void {
            $call->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @return BelongsTo<AiRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AiRun::class, 'ai_run_id');
    }
}
