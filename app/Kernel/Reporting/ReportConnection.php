<?php

declare(strict_types=1);

namespace App\Kernel\Reporting;

use App\Kernel\Reporting\Exceptions\ReportingConnectionUnavailable;
use App\Kernel\Tenancy\TenantConnectionGuard;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

/**
 * Resolves a tenant read connection lazily.
 *
 * Standard reports use the already-bound tenant Primary connection. Advanced
 * reports ask for Reporting; only then are replica credentials inspected and
 * a tenant-specific connection assembled. This keeps application boot and
 * Standard Reports independent from optional replica configuration.
 */
final class ReportConnection
{
    public const REPORTING_CONNECTION = 'tenant_reporting';

    private ?string $boundReportingDatabase = null;

    public function __construct(
        private readonly Config $config,
        private readonly DatabaseManager $database,
        private readonly TenantConnectionGuard $tenant,
    ) {}

    public function for(ReadTarget $target): Connection
    {
        $this->tenant->ensureInitialized('reporting read');

        if ($target === ReadTarget::Primary) {
            return $this->database->connection(TenantConnectionGuard::CONNECTION);
        }

        return $this->reporting();
    }

    public function reportingConfigured(): bool
    {
        $template = $this->template();

        return $this->nonEmpty($template['host'] ?? null)
            && $this->nonEmpty($template['username'] ?? null);
    }

    public function forget(): void
    {
        $this->database->purge(self::REPORTING_CONNECTION);
        $this->config->set('database.connections.'.self::REPORTING_CONNECTION, null);
        $this->boundReportingDatabase = null;
    }

    private function reporting(): Connection
    {
        if (! $this->reportingConfigured()) {
            throw ReportingConnectionUnavailable::notConfigured();
        }

        $database = $this->tenant->assertBoundDatabaseIsValid();

        if ($this->boundReportingDatabase !== $database) {
            $this->forget();

            $connection = $this->template();
            $connection['database'] = $database;
            $connection['name'] = self::REPORTING_CONNECTION;

            $this->config->set('database.connections.'.self::REPORTING_CONNECTION, $connection);
            $this->boundReportingDatabase = $database;
        }

        return $this->database->connection(self::REPORTING_CONNECTION);
    }

    /** @return array<string, mixed> */
    private function template(): array
    {
        $template = $this->config->get('database.connections.reporting_template', []);

        return is_array($template) ? $template : [];
    }

    private function nonEmpty(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
