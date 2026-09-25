<?php

declare(strict_types=1);

namespace App\Modules\Rayan\Infrastructure\Providers;

use App\Kernel\Platform\Credentials\AiProviderSettings;
use App\Modules\Rayan\Contracts\AiProvider;
use App\Modules\Rayan\Domain\Data\AiCapabilities;
use App\Modules\Rayan\Domain\Data\AiResponse;
use App\Modules\Rayan\Domain\Data\AiTokenUsage;
use App\Modules\Rayan\Domain\Data\AiToolCallRequest;
use App\Modules\Rayan\Domain\Data\AiTurnItem;
use App\Modules\Rayan\Domain\Data\ToolDefinition;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Client\Factory as Http;
use Throwable;

/**
 * OpenAI, through the Responses API.
 *
 * Built against OpenAI's published Responses API and function-calling
 * documentation, read 2026-09-20. The exact facts relied on — and they are
 * facts a future reader should re-verify rather than assume — are recorded in
 * docs/27-RAYAN.md §5:
 *
 *   endpoint        `POST {base}/responses`, bearer token
 *   tool shape      FLAT: `{type:"function", name, description, parameters,
 *                   strict}`. NOT the nested `{type:"function",
 *                   function:{...}}` that Chat Completions used — this is the
 *                   single most likely thing to get wrong by memory.
 *   tool request    an `output[]` item of `type:"function_call"` with `call_id`,
 *                   `name` and `arguments` (a JSON *string*)
 *   tool result     an INPUT item of `type:"function_call_output"` with the
 *                   matching `call_id` and a string `output`
 *   text            an `output[]` item of `type:"message"` whose `content[]`
 *                   holds `type:"output_text"` with `text`
 *   usage           `usage.input_tokens`, `.output_tokens`, `.total_tokens`
 *   truncation      `status:"incomplete"` with
 *                   `incomplete_details.reason:"max_output_tokens"`
 *
 * ## Not proven against live credentials
 *
 * No OpenAI key exists in this environment, so this has NOT completed a real
 * round trip. It is exercised by contract tests against recorded response
 * shapes, and the Phase 13 report says so plainly rather than implying
 * otherwise (§20).
 *
 * ## Storage is off
 *
 * `store: false` on every request. The API's default is to retain responses for
 * 30 days and expose them in the dashboard, and these requests carry a center's
 * customers' messages (§10).
 *
 * ## No SDK
 *
 * Laravel's HTTP client. The Responses API is a single JSON endpoint; a
 * dependency for one POST would be a package to keep updated, audit and explain
 * in `docs/DECISIONS.md` in exchange for nothing.
 */
final class OpenAiProvider implements AiProvider
{
    public const CODE = 'openai';

    public function __construct(
        private readonly Http $http,
        private readonly Config $config,
        private readonly AiProviderSettings $platform,
    ) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function displayName(): string
    {
        return 'OpenAI';
    }

    public function capabilities(): AiCapabilities
    {
        $key = $this->setting('api_key');

        if (! is_string($key) || $key === '') {
            /*
             * No key: honestly unavailable. Conversations hand off to staff
             * rather than failing at a customer, which is the right behaviour
             * for a deployment that has not been given one — and is exactly how
             * the test suite runs.
             */
            return AiCapabilities::unavailable();
        }

        $models = $this->setting('approved_models');
        $models = is_array($models) ? array_values(array_filter($models, 'is_string')) : [];

        $default = $this->setting('model');
        $report = $this->setting('report_model');

        return new AiCapabilities(
            available: true,
            tools: true,
            models: $models,
            defaultModel: is_string($default) && $default !== '' ? $default : null,
            reportModel: is_string($report) && $report !== '' ? $report : null,
        );
    }

    public function respond(
        string $model,
        string $instructions,
        array $transcript,
        array $tools,
        int $maxOutputTokens,
    ): AiResponse {
        $key = $this->setting('api_key');

        if (! is_string($key) || $key === '') {
            return AiResponse::failed('not_configured');
        }

        try {
            $response = $this->http
                ->timeout($this->seconds('timeout', 30))
                ->connectTimeout($this->seconds('connect_timeout', 5))
                ->withToken($key)
                ->acceptJson()
                ->asJson()
                ->post($this->endpoint(), [
                    'model' => $model,
                    'instructions' => $instructions,
                    'input' => array_map($this->item(...), $transcript),
                    'tools' => array_map($this->tool(...), $tools),
                    'max_output_tokens' => $maxOutputTokens,
                    // Off. See the class doc block — the default retains these
                    // for 30 days, and they carry customers' messages (§10).
                    'store' => (bool) $this->setting('store'),
                ]);
        } catch (Throwable) {
            /*
             * A timeout or a dropped connection. The exception is NOT reported
             * with its context: a Guzzle exception can carry the request, and
             * the request carries both the Authorization header and the
             * customer's messages.
             */
            return AiResponse::failed('timeout');
        }

        if (! $response->successful()) {
            return AiResponse::failed($this->failureCode($response->status()));
        }

        $body = $response->json();

        if (! is_array($body)) {
            return AiResponse::failed('malformed');
        }

        return $this->read($body);
    }

    /**
     * Turns a successful body into a turn.
     *
     * @param  array<string, mixed>  $body
     */
    private function read(array $body): AiResponse
    {
        $usage = $this->usage($body);

        /*
         * `incomplete` means the model was cut off — almost always by
         * `max_output_tokens`. Treated as a FAILURE rather than a partial
         * answer: half a sentence about a booking is worse than handing the
         * conversation to a person, and the tokens were still spent, which is
         * why the usage travels with the failure (§16).
         */
        if (($body['status'] ?? null) === 'incomplete') {
            $reason = $body['incomplete_details']['reason'] ?? null;

            return AiResponse::failed(is_string($reason) ? 'truncated_'.$reason : 'truncated', $usage);
        }

        $text = '';
        $toolCalls = [];

        foreach ($this->listAt($body, 'output') as $item) {
            if (! is_array($item)) {
                continue;
            }

            $type = $item['type'] ?? null;

            if ($type === 'function_call') {
                $call = $this->toolCall($item);

                if ($call instanceof AiToolCallRequest) {
                    $toolCalls[] = $call;
                }

                continue;
            }

            if ($type === 'message') {
                $text .= $this->messageText($item);
            }

            /*
             * Anything else — a `reasoning` item, a built-in tool call, a type
             * added after this was written — is SKIPPED. Not an error: OpenAI
             * adds output item types, and refusing a whole turn because one
             * item was unfamiliar would break conversations for no reason.
             */
        }

        $text = trim($text);

        if ($text === '' && $toolCalls === []) {
            // A turn with neither text nor a tool request is not usable.
            return AiResponse::failed('empty', $usage);
        }

        return AiResponse::answered($text === '' ? null : $text, $toolCalls, $usage);
    }

    /**
     * @param  array<array-key, mixed>  $item
     */
    private function toolCall(array $item): ?AiToolCallRequest
    {
        $callId = $item['call_id'] ?? null;
        $name = $item['name'] ?? null;

        if (! is_string($callId) || $callId === '' || ! is_string($name) || $name === '') {
            return null;
        }

        /*
         * `arguments` is a JSON *string*, not an object. Decoded here, and a
         * failure to decode yields an EMPTY argument list rather than a
         * refusal — the registry validates against the tool's schema next, and
         * missing required arguments is a refusal it already knows how to
         * express. Nothing is guessed (§11).
         */
        $decoded = is_string($item['arguments'] ?? null) ? json_decode((string) $item['arguments'], true) : null;

        return new AiToolCallRequest(
            callId: $callId,
            name: $name,
            arguments: is_array($decoded) ? $decoded : [],
        );
    }

    /**
     * @param  array<array-key, mixed>  $item
     */
    private function messageText(array $item): string
    {
        $text = '';

        foreach ($this->listAt($item, 'content') as $part) {
            if (is_array($part) && ($part['type'] ?? null) === 'output_text' && is_string($part['text'] ?? null)) {
                $text .= $part['text'];
            }

            /*
             * A `refusal` part is deliberately NOT read as text. The model
             * declining to answer is not an answer to forward to a customer;
             * the run ends with no text, and the loop hands off (§16).
             */
        }

        return $text;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function usage(array $body): AiTokenUsage
    {
        $usage = $body['usage'] ?? null;

        if (! is_array($usage)) {
            // Unknown, and left unknown. Never estimated (§7).
            return AiTokenUsage::unknown();
        }

        return new AiTokenUsage(
            input: $this->intAt($usage, 'input_tokens'),
            output: $this->intAt($usage, 'output_tokens'),
            // READ, not summed: a provider billing cached input differently is
            // entitled to report a total that is not input + output.
            total: $this->intAt($usage, 'total_tokens'),
        );
    }

    /**
     * One tool, in the Responses API's FLAT function shape.
     *
     * `strict: true` makes the provider itself enforce the schema, which is a
     * second line behind the registry's own validation — and it requires
     * `additionalProperties: false` plus every property listed in `required`,
     * both of which {@see ToolDefinition::schema()} guarantees.
     *
     * @return array<string, mixed>
     */
    private function tool(ToolDefinition $definition): array
    {
        return [
            'type' => 'function',
            'name' => $definition->name(),
            'description' => $definition->description,
            'parameters' => $definition->schema(),
            'strict' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function item(AiTurnItem $item): array
    {
        return match ($item->kind) {
            'tool_call' => [
                'type' => 'function_call',
                'call_id' => $item->callId,
                'name' => $item->toolName,
                // Back as a JSON string, the way it arrived.
                'arguments' => json_encode($item->arguments === [] ? new \stdClass : $item->arguments),
            ],
            'tool_output' => [
                'type' => 'function_call_output',
                'call_id' => $item->callId,
                'output' => $item->text,
            ],
            'assistant' => ['role' => 'assistant', 'content' => $item->text],
            default => ['role' => 'user', 'content' => $item->text],
        };
    }

    /**
     * A safe code for an HTTP failure. Never the provider's message body, which
     * can echo the customer's own text back into a log.
     */
    private function failureCode(int $status): string
    {
        return match (true) {
            $status === 401 || $status === 403 => 'not_authorised',
            $status === 429 => 'rate_limited',
            $status >= 500 => 'provider_error',
            default => 'http_'.$status,
        };
    }

    private function endpoint(): string
    {
        return rtrim((string) $this->setting('base_url'), '/').'/responses';
    }

    private function setting(string $key): mixed
    {
        // The key and the models are the Super Admin's to set (and AI can be
        // switched off there); the rest is deployment configuration.
        return match ($key) {
            'api_key' => $this->platform->apiKey(),
            'model' => $this->platform->defaultModel(),
            'report_model' => $this->platform->reportModel(),
            // The deployment allow-list plus the platform's own choices.
            'approved_models' => $this->platform->approvedModels(),
            default => $this->config->get('rayan.providers.openai.'.$key),
        };
    }

    private function seconds(string $key, int $default): int
    {
        $value = $this->config->get('rayan.http.'.$key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @param  array<array-key, mixed>  $source
     * @return list<mixed>
     */
    private function listAt(array $source, string $key): array
    {
        $value = $source[$key] ?? null;

        return is_array($value) ? array_values($value) : [];
    }

    /**
     * @param  array<array-key, mixed>  $source
     */
    private function intAt(array $source, string $key): ?int
    {
        $value = $source[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}
