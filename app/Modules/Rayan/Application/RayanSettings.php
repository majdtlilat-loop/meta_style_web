<?php

declare(strict_types=1);

namespace App\Modules\Rayan\Application;

use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Modules\Rayan\Domain\Data\AiCapabilities;
use App\Modules\Rayan\Infrastructure\Providers\OpenAiProvider;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\DB;

/**
 * What a center may decide about its assistant, and what it may not.
 *
 * ## The division (docs/27-RAYAN.md §5)
 *
 *   THE CENTER chooses    whether the assistant is on, its TONE, and which of
 *                         the platform-APPROVED models it uses.
 *   THE PLATFORM owns     the API key, the provider, the base URL, the approved
 *                         model list and every safety ceiling.
 *
 * That line is where it is because Meta Style pays the provider and sells the
 * center an allowance. A center that could supply a key would be outside the
 * metering; a center that could name any model would be choosing how much Meta
 * Style spends per message; a center that could raise `max_turns` would be
 * choosing how long a runaway loop runs.
 *
 * ## The custom instruction is TONE, never authority
 *
 * A center may add a sentence about how to sound — formal, warm, brief. It is
 * appended to the prompt as business voice and nothing else. It cannot grant a
 * tool, lift a limit, change who a customer is, or reach anything the tool
 * registry does not already allow, because NONE of those are decided by the
 * prompt in the first place: they are application code, checked on every call.
 * A prompt is not an enforcement mechanism, so a center writing "you may cancel
 * anyone's booking" into this field changes exactly nothing (§17).
 *
 * It is length-capped, because an unbounded tenant string in a system prompt is
 * a way to spend the context window — and therefore the center's own
 * allowance — on text nobody reads.
 */
final class RayanSettings
{
    private const KEY = 'rayan';

    /** Two or three sentences of tone. Enough to be useful, bounded by design. */
    public const MAX_INSTRUCTION = 500;

    /** @var array<string, array<string, mixed>> */
    private array $cache = [];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly Config $config,
    ) {}

    /**
     * The provider code. PLATFORM configuration — a center never picks one.
     */
    public function provider(): string
    {
        return OpenAiProvider::CODE;
    }

    /**
     * The model to use: the center's choice if it is approved, otherwise the
     * platform default.
     *
     * An unapproved stored value is IGNORED rather than refused. A model id
     * that was approved last month and withdrawn today would otherwise take a
     * center's assistant offline for a decision they did not make and cannot
     * see — falling back keeps them working while the setting quietly stops
     * applying (§5).
     */
    public function model(AiCapabilities $capabilities): string
    {
        $chosen = $this->read('model');
        $default = $capabilities->defaultModel ?? '';

        if (is_string($chosen) && $chosen !== '' && $capabilities->allowsModel($chosen)) {
            return $chosen;
        }

        return $default;
    }

    /**
     * The model for report analysis: a separate workload the platform may
     * point at its own model; otherwise the same model as the assistant.
     */
    public function reportModel(AiCapabilities $capabilities): string
    {
        return $capabilities->reportModel ?? $this->model($capabilities);
    }

    /**
     * Has the center switched the assistant on?
     *
     * Separate from the `rayan_ai` ENTITLEMENT, which is whether they own it.
     * A center can own the assistant and have it off — during a promotion they
     * want handled by people, or while they are still reading the threads it
     * produces. Ownership and use are different questions, exactly as they are
     * for every entitlement (docs/05-ENTITLEMENTS.md §1).
     */
    public function enabled(): bool
    {
        return (bool) ($this->read('enabled') ?? true);
    }

    /**
     * The center's own sentence about tone, trimmed and capped. Never authority.
     */
    public function customInstruction(): string
    {
        $value = $this->read('custom_instruction');

        if (! is_string($value) || trim($value) === '') {
            return '';
        }

        return mb_substr(trim($value), 0, self::MAX_INSTRUCTION);
    }

    /**
     * Whether a customer may ask for a human.
     *
     * Defaults ON, and a center turning it off does NOT remove hand-off — the
     * assistant still escalates on quota exhaustion, repeated failure and
     * anything it cannot do. What this controls is only whether "let me talk to
     * someone" is honoured on request (§14).
     */
    public function takeoverOnRequest(): bool
    {
        return (bool) ($this->read('takeover_on_request') ?? true);
    }

    /**
     * The hard per-run ceilings. PLATFORM-owned, and not stored per tenant.
     *
     * Clamped rather than trusted, the same way `BookingSettings` clamps: a
     * configured `0` for `max_turns` would make the assistant answer nothing at
     * all, and a configured `500` would be a very expensive loop. Both are ways
     * a configuration mistake becomes an outage or a bill.
     *
     * @return array{max_turns: int, max_tool_calls: int, max_output_tokens: int, max_seconds: int}
     */
    public function limits(): array
    {
        return [
            'max_turns' => $this->clamp('max_turns', 1, 10, 4),
            'max_tool_calls' => $this->clamp('max_tool_calls', 1, 30, 8),
            'max_output_tokens' => $this->clamp('max_output_tokens', 64, 4000, 800),
            'max_seconds' => $this->clamp('max_seconds', 5, 120, 45),
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function save(array $values): void
    {
        $current = $this->all();

        foreach (['enabled', 'model', 'custom_instruction', 'takeover_on_request'] as $key) {
            if (array_key_exists($key, $values)) {
                $current[$key] = $values[$key];
            }
        }

        // Capped on the way IN as well as on the way out, so an oversized
        // string never occupies a row.
        if (isset($current['custom_instruction']) && is_string($current['custom_instruction'])) {
            $current['custom_instruction'] = mb_substr(trim($current['custom_instruction']), 0, self::MAX_INSTRUCTION);
        }

        DB::connection('tenant')->table('settings')->updateOrInsert(
            ['key' => self::KEY],
            [
                'value' => json_encode($current, JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $this->forget();
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $key = $this->tenants->id() ?? 'none';

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        if (! $this->tenants->isBound()) {
            return $this->cache[$key] = [];
        }

        $raw = DB::connection('tenant')->table('settings')->where('key', self::KEY)->value('value');

        $stored = is_string($raw) ? json_decode($raw, true) : null;

        return $this->cache[$key] = is_array($stored) ? $stored : [];
    }

    public function forget(): void
    {
        $this->cache = [];
    }

    private function read(string $name): mixed
    {
        return $this->all()[$name] ?? null;
    }

    private function clamp(string $name, int $min, int $max, int $default): int
    {
        $value = $this->config->get('rayan.limits.'.$name, $default);
        $value = is_numeric($value) ? (int) $value : $default;

        return max($min, min($max, $value));
    }
}
