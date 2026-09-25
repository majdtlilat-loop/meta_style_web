<?php

declare(strict_types=1);

namespace App\Modules\Rayan\Domain\Models;

use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use App\Modules\Rayan\Domain\Enums\AiRunStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One attempt to produce an answer: EXECUTION METADATA, never the conversation.
 *
 * What was said lives in `messages` and is the domain record a center is
 * accountable for. This is the operational and commercial shadow of it — which
 * model, how many tokens, how long, did it work — and the two are separate
 * tables so that pruning one never silently rewrites the other
 * (docs/27-RAYAN.md §8).
 *
 * No prompt, no raw model output. Both would duplicate customer PII into a
 * second table with a different retention policy, and the prompt additionally
 * carries the curated customer context that §10 exists to keep bounded.
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $conversation_id
 * @property string $source
 * @property string|null $report_code
 * @property int|null $requested_by_user_id
 * @property string $provider
 * @property string $model
 * @property AiRunStatus $status
 * @property int|null $input_tokens
 * @property int|null $output_tokens
 * @property int|null $total_tokens
 * @property int $turns
 * @property int $tool_calls
 * @property string|null $failure_code
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $finished_at
 */
final class AiRun extends Model
{
    use UsesTenantConnection;

    protected $table = 'ai_runs';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AiRunStatus::class,
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'total_tokens' => 'integer',
            'turns' => 'integer',
            'tool_calls' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $run): void {
            $run->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @return HasMany<AiToolCall, $this>
     */
    public function toolCalls(): HasMany
    {
        return $this->hasMany(AiToolCall::class)->orderBy('id');
    }

    public function durationMs(): ?int
    {
        if ($this->finished_at === null) {
            return null;
        }

        return (int) $this->started_at->diffInMilliseconds($this->finished_at);
    }
}
