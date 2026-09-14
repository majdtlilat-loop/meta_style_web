<?php

declare(strict_types=1);

namespace App\Kernel\Http;

use App\Kernel\Http\Middleware\EnsureIdempotency;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Replay protection for operations that must not happen twice.
 *
 * ## The problem
 *
 * A phone on a bad connection retries. A customer taps "confirm" again because
 * the page is slow. A WhatsApp webhook is delivered at least once. RAYAN times
 * out and calls again. Each of those turns one booking into two, and by Phase 9
 * into two invoices (docs/10-API-FOUNDATION.md §6).
 *
 * ## How it behaves
 *
 * | Situation | Answer |
 * |---|---|
 * | New key | Runs the operation, stores the response |
 * | Same key, same payload | Replays the stored response — no second write |
 * | Same key, DIFFERENT payload | `409 IDEMPOTENCY.CONFLICT` |
 * | Same key, still in flight | `409` with `Retry-After` |
 *
 * The third row is the one that matters most. Silently replaying a stored
 * response for a different payload would tell a client "your booking for 3pm
 * succeeded" when what actually exists is the 2pm booking it sent first.
 *
 * ## Why the unique index is the concurrency control
 *
 * Two simultaneous retries both find no row and both try to insert; the
 * database lets exactly one win. That is the whole mechanism — no lock table,
 * no advisory lock, nothing that behaves differently between MariaDB and MySQL
 * 8. The loser is told the request is in flight rather than being allowed to
 * run the operation a second time.
 *
 * ## One implementation, every channel
 *
 * {@see EnsureIdempotency} wraps API routes; the
 * public booking form calls this class directly with a key its confirm page
 * generated. Two entry points, one table, one set of rules (§29).
 */
final class Idempotency
{
    /** Long enough for any realistic retry, short enough to prune itself. */
    private const TTL_HOURS = 24;

    /**
     * A request that has been running this long is assumed dead.
     *
     * Without this, a worker killed mid-booking would leave a key that refuses
     * every retry forever — the failure mode where the safety mechanism becomes
     * the outage.
     */
    private const STALE_MINUTES = 5;

    /**
     * Runs the operation once, or returns what it returned last time.
     *
     * @param  array<string, mixed>  $payload  the request, for the hash
     * @param  callable(): JsonResponse  $operation
     */
    public function run(string $key, string $endpoint, array $payload, callable $operation): JsonResponse
    {
        $hash = $this->hash($payload);
        $now = CarbonImmutable::now();

        $existing = $this->find($key, $endpoint);

        if ($existing !== null) {
            $replay = $this->replay($existing, $hash, $now);

            if ($replay instanceof JsonResponse) {
                return $replay;
            }
        }

        if (! $this->claim($key, $endpoint, $hash, $now)) {
            // Somebody else inserted between the read and the write. Their
            // request is in flight; this one must not run the operation too.
            return $this->inFlight();
        }

        try {
            $response = $operation();
        } catch (Throwable $e) {
            $this->release($key, $endpoint);

            throw $e;
        }

        /*
         * ONLY A SUCCESS IS WORTH REPLAYING.
         *
         * A failed request releases its key, so a client that fixes the problem
         * — a time outside opening hours, a missing field — can retry with the
         * same key and succeed. Storing the failure instead would answer that
         * corrected retry with IDEMPOTENCY.CONFLICT, turning one bad request
         * into a permanently unusable key: the failure mode where the safety
         * mechanism becomes the outage.
         *
         * Nothing was committed either way, because the operations this guards
         * run in their own transaction.
         *
         * Checked on the RESPONSE rather than by catching: Laravel's routing
         * pipeline converts an exception into a response before it reaches this
         * middleware, so the catch above only fires for non-HTTP callers.
         */
        if ($response->getStatusCode() >= 400) {
            $this->release($key, $endpoint);

            return $response;
        }

        $this->complete($key, $endpoint, $response);

        return $response;
    }

    /**
     * Deletes expired rows.
     *
     * Called by `metastyle:idempotency:sweep`. Opportunistic per-key expiry
     * handles the common case, but a center with a burst of traffic and no
     * repeats would otherwise accumulate rows nothing ever looks at again.
     */
    public function sweep(?CarbonImmutable $now = null): int
    {
        return DB::connection('tenant')
            ->table('idempotency_keys')
            ->where('expires_at', '<', ($now ?? CarbonImmutable::now()))
            ->delete();
    }

    /**
     * A stable hash of what was asked for.
     *
     * Sorted recursively, so a client that serialises its JSON keys in a
     * different order on a retry is not accused of sending a different request.
     *
     * @param  array<string, mixed>  $payload
     */
    public function hash(array $payload): string
    {
        return hash('sha256', (string) json_encode($this->normalise($payload)));
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private function normalise(array $value): array
    {
        ksort($value);

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->normalise($item);
            }
        }

        return $value;
    }

    private function find(string $key, string $endpoint): ?object
    {
        $row = DB::connection('tenant')
            ->table('idempotency_keys')
            ->where('endpoint', $endpoint)
            ->where('key', $key)
            ->first();

        return is_object($row) ? $row : null;
    }

    /**
     * Decides what an existing row means for this request.
     *
     * Returns a response to send, or null to fall through and claim the key —
     * which happens when the previous attempt expired or died mid-flight.
     */
    private function replay(object $row, string $hash, CarbonImmutable $now): ?JsonResponse
    {
        /** @var object{request_hash: string, status: string, response_code: int|null, response_body: string|null, locked_at: string|null, expires_at: string} $row */
        if (CarbonImmutable::parse($row->expires_at) <= $now) {
            return null;
        }

        if (! hash_equals($row->request_hash, $hash)) {
            // The same key with a different body. Never replay: the stored
            // response describes something else entirely.
            return ApiResponse::error(
                ApiErrorCode::IdempotencyConflict,
                'That idempotency key was already used for a different request.',
            );
        }

        if ($row->status === 'completed' && $row->response_body !== null) {
            /** @var array<string, mixed> $body */
            $body = json_decode($row->response_body, true) ?: [];

            return new JsonResponse($body, $row->response_code ?? 200, [
                // So a client can tell a replay from a fresh result — useful
                // when debugging a retry storm, and harmless to expose.
                'Idempotent-Replay' => 'true',
            ]);
        }

        $lockedAt = $row->locked_at === null ? null : CarbonImmutable::parse($row->locked_at);

        if ($lockedAt !== null && $lockedAt->addMinutes(self::STALE_MINUTES) < $now) {
            // Abandoned. Let this attempt take it over.
            return null;
        }

        return $this->inFlight();
    }

    private function claim(string $key, string $endpoint, string $hash, CarbonImmutable $now): bool
    {
        DB::connection('tenant')
            ->table('idempotency_keys')
            ->where('endpoint', $endpoint)
            ->where('key', $key)
            ->where('expires_at', '<', $now)
            ->delete();

        try {
            DB::connection('tenant')->table('idempotency_keys')->insert([
                'key' => $key,
                'endpoint' => $endpoint,
                'request_hash' => $hash,
                'status' => 'in_progress',
                'locked_at' => $now,
                'expires_at' => $now->addHours(self::TTL_HOURS),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return true;
        } catch (QueryException) {
            // The unique index fired: a concurrent request got there first.
            // This is the mechanism working, not an error.
            return false;
        }
    }

    private function complete(string $key, string $endpoint, JsonResponse $response): void
    {
        DB::connection('tenant')
            ->table('idempotency_keys')
            ->where('endpoint', $endpoint)
            ->where('key', $key)
            ->update([
                'status' => 'completed',
                'response_code' => $response->getStatusCode(),
                'response_body' => $response->getContent() === false ? null : $response->getContent(),
                'locked_at' => null,
                'updated_at' => CarbonImmutable::now(),
            ]);
    }

    private function release(string $key, string $endpoint): void
    {
        DB::connection('tenant')
            ->table('idempotency_keys')
            ->where('endpoint', $endpoint)
            ->where('key', $key)
            ->delete();
    }

    private function inFlight(): JsonResponse
    {
        $response = ApiResponse::error(
            ApiErrorCode::IdempotencyConflict,
            'An identical request is still being processed.',
        );

        return $response->withHeaders(['Retry-After' => '2']);
    }
}
