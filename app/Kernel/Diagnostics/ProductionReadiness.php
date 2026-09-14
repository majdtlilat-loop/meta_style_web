<?php

declare(strict_types=1);

namespace App\Kernel\Diagnostics;

use App\Kernel\Http\MiddlewareOrderGuard;
use App\Kernel\Http\MiddlewareOrderViolation;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Router;

/**
 * Configuration that is merely inconvenient locally and dangerous in production.
 *
 * Every check here covers a failure that produces no error: the app serves
 * traffic, the tests pass, and something is quietly wrong. Rate limits that do
 * not limit. Cache entries shared between centers. A queue that runs inline.
 * None of these announce themselves — which is the whole reason for a check
 * rather than a runbook line (docs/12-DEPLOYMENT-MIGRATION-STRATEGY.md §11).
 *
 * Read-only: it inspects configuration and never connects to anything. Safe to
 * run at boot and safe to run in a deploy pipeline before the app is live.
 */
final class ProductionReadiness
{
    /**
     * Cache drivers whose state is visible to every process on every server.
     *
     * `database` qualifies and is deliberately included: it is slower than
     * Redis but it is genuinely shared, and excluding it would be dishonest.
     *
     * @var list<string>
     */
    private const SHARED_DRIVERS = ['redis', 'memcached', 'dynamodb', 'database'];

    /**
     * Cache drivers that support tags.
     *
     * Tenant cache isolation is implemented with tags, not key prefixes, so an
     * untagged store silently shares one keyspace across every center
     * (ADR-025). `database` and `file` are absent on purpose.
     *
     * @var list<string>
     */
    private const TAGGABLE_DRIVERS = ['redis', 'memcached', 'array', 'dynamodb'];

    public function __construct(
        private readonly Repository $config,
        private readonly Application $app,
        private readonly Router $router,
        private readonly MiddlewareOrderGuard $middlewareOrder,
    ) {}

    /**
     * @param  bool|null  $productionRules  null follows the real environment
     * @return list<ReadinessCheck>
     */
    public function checks(?bool $productionRules = null): array
    {
        $production = $productionRules ?? $this->app->isProduction();

        return [
            $this->middlewareOrdering(),
            $this->rateLimitBackend($production),
            $this->cacheTags($production),
            $this->queueDriver($production),
            $this->sessionDriver($production),
            $this->debugMode($production),
            $this->applicationKey(),
        ];
    }

    /**
     * @return list<ReadinessCheck>
     */
    public function failures(?bool $productionRules = null): array
    {
        return array_values(array_filter(
            $this->checks($productionRules),
            fn (ReadinessCheck $check): bool => $check->isFailure(),
        ));
    }

    public function passes(?bool $productionRules = null): bool
    {
        return $this->failures($productionRules) === [];
    }

    private function middlewareOrdering(): ReadinessCheck
    {
        try {
            $this->middlewareOrder->assert($this->router);
        } catch (MiddlewareOrderViolation $e) {
            return ReadinessCheck::failure(
                'middleware order',
                $e->getMessage(),
                'Fix the prependToPriorityList() call in bootstrap/app.php.',
            );
        }

        return ReadinessCheck::ok('middleware order', 'tenant resolution precedes authentication');
    }

    /**
     * The rate limiter is only a limit if every worker counts into one bucket.
     *
     * With the `array` store each PHP process keeps a private counter, so a
     * "5 attempts per minute" login limit becomes 5 x workers per minute, and
     * nothing anywhere reports that it happened. `file` has the same shape one
     * level up: per server rather than per process.
     */
    private function rateLimitBackend(bool $production): ReadinessCheck
    {
        $store = $this->limiterStore();
        $driver = $this->driverFor($store);

        if (! $production) {
            return ReadinessCheck::ok(
                'rate limit backend',
                sprintf('%s (%s) - local and test runs do not require a shared backend', $store, $driver),
            );
        }

        if (! in_array($driver, self::SHARED_DRIVERS, true)) {
            return ReadinessCheck::failure(
                'rate limit backend',
                sprintf(
                    'store "%s" uses the %s driver, which is not shared between processes. '
                    .'Every worker would keep its own counter, multiplying every published '
                    .'limit by the worker count with no error and no log line.',
                    $store,
                    $driver,
                ),
                'Set CACHE_STORE=redis (preferred), or point cache.limiter at a shared store.',
            );
        }

        if ($driver !== 'redis') {
            return ReadinessCheck::warning(
                'rate limit backend',
                sprintf('store "%s" uses the %s driver: shared, but slower than Redis under load', $store, $driver),
                'Redis is the recommended production backend.',
            );
        }

        return ReadinessCheck::ok('rate limit backend', sprintf('store "%s" (redis) is shared', $store));
    }

    private function cacheTags(bool $production): ReadinessCheck
    {
        $store = $this->defaultStore();
        $driver = $this->driverFor($store);

        if (in_array($driver, self::TAGGABLE_DRIVERS, true)) {
            return ReadinessCheck::ok('cache tags', sprintf('store "%s" (%s) supports tags', $store, $driver));
        }

        $detail = sprintf(
            'store "%s" uses the %s driver, which does not support tags. Tenant cache '
            .'isolation is tag-based, so every center would share one keyspace (ADR-025).',
            $store,
            $driver,
        );

        return $production
            ? ReadinessCheck::failure('cache tags', $detail, 'Set CACHE_STORE=redis.')
            : ReadinessCheck::warning('cache tags', $detail, 'Set CACHE_STORE=array locally, redis in production.');
    }

    private function queueDriver(bool $production): ReadinessCheck
    {
        /** @var string $connection */
        $connection = $this->config->get('queue.default', 'sync');
        /** @var string $driver */
        $driver = $this->config->get("queue.connections.{$connection}.driver", $connection);

        if (! $production) {
            return ReadinessCheck::ok('queue driver', sprintf('%s (%s)', $connection, $driver));
        }

        if ($driver === 'sync') {
            return ReadinessCheck::failure(
                'queue driver',
                'the sync driver runs jobs inline, so tenant provisioning would happen '
                .'inside the registration request and time out under any real load.',
                'Set QUEUE_CONNECTION=redis or database, and run a worker.',
            );
        }

        if ($driver === 'null') {
            return ReadinessCheck::failure(
                'queue driver',
                'the null driver discards jobs. Registrations would be accepted and never provisioned.',
                'Set QUEUE_CONNECTION=redis or database.',
            );
        }

        return ReadinessCheck::ok('queue driver', sprintf('%s (%s)', $connection, $driver));
    }

    private function sessionDriver(bool $production): ReadinessCheck
    {
        /** @var string $driver */
        $driver = $this->config->get('session.driver', 'file');

        if (! $production) {
            return ReadinessCheck::ok('session driver', $driver);
        }

        if ($driver === 'array') {
            return ReadinessCheck::failure(
                'session driver',
                'the array driver discards the session at the end of the request, so nobody could stay signed in.',
                'Set SESSION_DRIVER=redis or database.',
            );
        }

        if ($driver === 'file') {
            return ReadinessCheck::warning(
                'session driver',
                'file sessions live on one server. Behind more than one app server a center '
                .'would be signed out whenever a request lands elsewhere - and on web routes '
                .'the tenant is resolved from the session (ADR-030).',
                'Set SESSION_DRIVER=redis or database.',
            );
        }

        return ReadinessCheck::ok('session driver', $driver);
    }

    private function debugMode(bool $production): ReadinessCheck
    {
        $debug = (bool) $this->config->get('app.debug', false);

        if ($production && $debug) {
            return ReadinessCheck::failure(
                'debug mode',
                'APP_DEBUG is on. Stack traces would expose database names, connection '
                .'credentials and tenant identifiers to anyone who can trigger an error.',
                'Set APP_DEBUG=false.',
            );
        }

        return ReadinessCheck::ok('debug mode', $debug ? 'on (non-production)' : 'off');
    }

    private function applicationKey(): ReadinessCheck
    {
        $key = $this->config->get('app.key');

        if (! is_string($key) || $key === '') {
            return ReadinessCheck::failure(
                'application key',
                'APP_KEY is empty. Encrypted columns - including the bootstrap credential '
                .'on registrations (ADR-031) - cannot be written or read.',
                'Run php artisan key:generate.',
            );
        }

        return ReadinessCheck::ok('application key', 'set');
    }

    /**
     * The store Laravel's rate limiter actually uses: `cache.limiter` when set,
     * otherwise the default store.
     */
    private function limiterStore(): string
    {
        $limiter = $this->config->get('cache.limiter');

        return is_string($limiter) && $limiter !== '' ? $limiter : $this->defaultStore();
    }

    private function defaultStore(): string
    {
        $default = $this->config->get('cache.default');

        return is_string($default) && $default !== '' ? $default : 'array';
    }

    private function driverFor(string $store): string
    {
        $driver = $this->config->get("cache.stores.{$store}.driver");

        return is_string($driver) && $driver !== '' ? $driver : 'unknown';
    }
}
