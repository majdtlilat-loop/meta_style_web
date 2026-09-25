<?php

declare(strict_types=1);

namespace App\Kernel\Diagnostics;

use App\Kernel\Http\MiddlewareOrderGuard;
use App\Kernel\Http\MiddlewareOrderViolation;
use App\Kernel\Security\Keyring;
use App\Kernel\Tenancy\PlatformHosts;
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

    /**
     * Keyed peppers every deployment must carry, and what each one protects.
     *
     * Named here rather than discovered from config, so a key that is MISSING
     * from the environment is still checked. Iterating `security.keys` would
     * report nothing at all for the one deployment that most needs telling.
     *
     * @var array<string, string>
     */
    private const REQUIRED_KEYS = [
        'booking_verification' => 'booking verification codes could not be issued or checked',
    ];

    public function __construct(
        private readonly Repository $config,
        private readonly Application $app,
        private readonly Router $router,
        private readonly MiddlewareOrderGuard $middlewareOrder,
        private readonly Keyring $keyring,
        private readonly PlatformHosts $hosts,
    ) {}

    /**
     * @param  bool|null  $productionRules  null follows the real environment
     * @return list<ReadinessCheck>
     */
    public function checks(?bool $productionRules = null): array
    {
        $production = $productionRules ?? $this->app->isProduction();

        $checks = [
            $this->middlewareOrdering(),
            $this->rateLimitBackend($production),
            $this->cacheTags($production),
            $this->queueDriver($production),
            $this->sessionDriver($production),
            $this->debugMode($production),
            $this->applicationKey(),
            $this->domainArchitecture($production),
            $this->wildcardCoverage($production),
            $this->reportingConnection(),
            $this->publicMediaLink($production),
        ];

        foreach (self::REQUIRED_KEYS as $name => $consequence) {
            $checks[] = $this->verificationKey($name, $consequence, $production);
        }

        return $checks;
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

    /**
     * Landing-page media is uploaded to the public disk and served from
     * /storage. Without the link every CMS image and video 404s on the
     * corporate site, silently.
     */
    private function publicMediaLink(bool $production): ReadinessCheck
    {
        $link = $this->app->publicPath('storage');
        if (is_dir($link)) {
            return ReadinessCheck::ok('public media link', 'public/storage serves uploaded landing-page media');
        }

        $detail = 'public/storage does not exist, so landing-page images and videos cannot be served.';

        return $production
            ? ReadinessCheck::failure('public media link', $detail, 'Run php artisan storage:link.')
            : ReadinessCheck::warning('public media link', $detail, 'Run php artisan storage:link.');
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
     * A reporting replica is optional until the paid product is activated,
     * but its absence must be visible before somebody sells that product.
     */
    private function domainArchitecture(bool $production): ReadinessCheck
    {
        try {
            $base = $this->hosts->baseDomain();
            $corporate = $this->hosts->corporateHost();
            $superadmin = $this->hosts->superAdminHost();
            $scheme = $this->hosts->scheme();
        } catch (\RuntimeException $exception) {
            return ReadinessCheck::failure(
                'platform domains',
                $exception->getMessage(),
                'Set APP_URL to an http(s) origin containing only scheme, hostname and optional port.',
            );
        }

        $central = $this->config->get('tenancy.central_domains', []);
        if ($corporate !== $base
            || $superadmin !== 'superadmin.'.$base
            || ! is_array($central)
            || ! in_array($corporate, $central, true)
            || ! in_array($superadmin, $central, true)) {
            return ReadinessCheck::failure(
                'platform domains',
                'routing and tenancy do not share the APP_URL-derived host model',
                'Use PlatformHosts for corporate, Super Admin and center host configuration.',
            );
        }

        if ($production && $scheme !== 'https') {
            return ReadinessCheck::failure('platform domains', 'production URL generation is not HTTPS', 'Set APP_URL to the production HTTPS origin.');
        }

        return ReadinessCheck::ok('platform domains', $this->hosts->corporateUrl().' with superadmin and wildcard center subdomains');
    }

    private function wildcardCoverage(bool $production): ReadinessCheck
    {
        $dns = (bool) $this->config->get('metastyle.domains.wildcard_dns_ready', false);
        $tls = (bool) $this->config->get('metastyle.domains.wildcard_tls_ready', false);

        if ($dns && $tls) {
            return ReadinessCheck::ok('wildcard DNS and TLS', 'both are declared ready for center subdomains');
        }

        $missing = implode(' and ', array_filter([$dns ? null : 'DNS', $tls ? null : 'TLS']));
        $detail = 'wildcard '.$missing.' readiness is not confirmed; center subdomains cannot be activated honestly';
        $remedy = 'Configure and verify *.'.$this->hosts->baseDomain().' DNS/TLS, then set METASTYLE_WILDCARD_DNS_READY and METASTYLE_WILDCARD_TLS_READY true.';

        return $production
            ? ReadinessCheck::failure('wildcard DNS and TLS', $detail, $remedy)
            : ReadinessCheck::warning('wildcard DNS and TLS', $detail, $remedy);
    }

    private function reportingConnection(): ReadinessCheck
    {
        $host = $this->config->get('database.connections.reporting_template.host');
        $username = $this->config->get('database.connections.reporting_template.username');
        $configured = is_string($host) && trim($host) !== ''
            && is_string($username) && trim($username) !== '';

        if ($configured) {
            return ReadinessCheck::ok('advanced reports connection', 'reporting endpoint credentials are configured');
        }

        $detail = 'reporting endpoint credentials are missing; Standard Reports remain available, but Advanced Reports cannot be enabled';
        $remedy = 'Set DB_REPORTING_HOST and DB_REPORTING_USERNAME (plus the matching secret and TLS settings).';

        return (bool) $this->config->get('reports.advanced_enabled', false)
            ? ReadinessCheck::failure('advanced reports connection', $detail, $remedy)
            : ReadinessCheck::warning('advanced reports connection', $detail, $remedy);
    }

    /**
     * One keyed pepper: is there an active version, and is its key real?
     *
     * FAILS CLOSED on every branch (docs/24-BOOKING-VERIFICATION.md §7). The
     * failure this guards against writes no error at runtime — codes are issued
     * happily against a missing key only to refuse verification later, or are
     * issued under a weak one that nothing reports. Neither surfaces until a
     * customer is at a counter unable to prove a booking.
     *
     * Outside production it is a WARNING: a developer machine has no pepper and
     * does not need one, and failing there would make `metastyle:doctor`
     * something everybody learns to ignore.
     */
    private function verificationKey(string $name, string $consequence, bool $production): ReadinessCheck
    {
        $label = str_replace('_', ' ', $name).' key';
        $described = $this->keyring->describe($name);
        $minimum = $this->minimumKeyBytes();

        $refuse = fn (string $detail): ReadinessCheck => $production
            ? ReadinessCheck::failure($label, $detail.', so '.$consequence.'.', $this->keyRemedy($name))
            : ReadinessCheck::warning($label, $detail.' (not required outside production)', $this->keyRemedy($name));

        $active = $described['active'];

        if ($active === null) {
            return $refuse('no active version is configured');
        }

        if (! array_key_exists($active, $described['versions'])) {
            return $refuse(sprintf('the active version "%s" has no key material', $active));
        }

        /*
         * EVERY configured version, not only the active one. A retired key that
         * has been blanked rather than removed stops verifying the codes still
         * relying on it, and the row that names it fails closed — the exact
         * outage this check exists to catch before it happens.
         */
        foreach ($described['versions'] as $version => $bytes) {
            if ($bytes < $minimum) {
                return $refuse(sprintf(
                    'version "%s" is %d byte(s); at least %d are required',
                    $version,
                    $bytes,
                    $minimum,
                ));
            }
        }

        return ReadinessCheck::ok($label, sprintf(
            'active version "%s", %d version(s) configured',
            $active,
            count($described['versions']),
        ));
    }

    private function keyRemedy(string $name): string
    {
        $prefix = mb_strtoupper($name);

        return sprintf(
            'Set %s_ACTIVE_KEY and %s_KEY_<VERSION> to "base64:" plus 32 random bytes.',
            $prefix,
            $prefix,
        );
    }

    private function minimumKeyBytes(): int
    {
        $minimum = $this->config->get('security.minimum_key_bytes', 32);

        return is_numeric($minimum) ? (int) $minimum : 32;
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
