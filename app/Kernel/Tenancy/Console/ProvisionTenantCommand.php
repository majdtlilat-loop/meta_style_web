<?php

declare(strict_types=1);

namespace App\Kernel\Tenancy\Console;

use App\Kernel\Audit\Actor;
use App\Kernel\Tenancy\Exceptions\TenantProvisioningFailed;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use App\Kernel\Tenancy\PlatformHosts;
use App\Kernel\Tenancy\TenantProvisioningService;
use Illuminate\Console\Command;

/**
 * Provisions a tenant from the console.
 *
 * Phase 2's only entry point for creating tenants. Self-service signup is
 * Phase 3 and will call the same TenantProvisioningService — the pipeline is
 * the product, this command is just one caller.
 */
final class ProvisionTenantCommand extends Command
{
    protected $signature = 'metastyle:tenant:provision
        {name : Display name of the center}
        {--domain=* : Host(s) that resolve to this tenant}
        {--slug= : Slug of the center; labels its database name (ADR-106)}
        {--retry= : Retry provisioning for an existing tenant id}';

    protected $description = 'Create a tenant, its database, schema and system data';

    public function handle(TenantProvisioningService $provisioner, PlatformHosts $hosts): int
    {
        $actor = Actor::console($this->getName() ?? 'metastyle:tenant:provision');

        // Only a slug the platform would accept for a center may label a
        // database; refused before anything is created. Without one the
        // database takes the neutral label, never the display name.
        $slug = $this->option('slug');
        $slug = is_string($slug) && $slug !== '' ? $slug : null;

        // Taken as typed: a value that only becomes a slug after normalising
        // ("Salon'; DROP …") is refused rather than quietly rewritten.
        if ($slug !== null && ($hosts->normalizeSlug($slug) !== $slug || ! $hosts->isValidCenterSlug($slug))) {
            $this->components->error('The slug must be lowercase letters, digits and hyphens, and not reserved.');

            return self::FAILURE;
        }

        /** @var list<string> $domains */
        $domains = array_values(array_filter((array) $this->option('domain')));

        try {
            $retryId = $this->option('retry');

            if (is_string($retryId) && $retryId !== '') {
                $existing = TenantModel::query()->findOrFail($retryId);

                $this->components->info("Retrying provisioning for [{$existing->name}]");

                $tenant = $provisioner->retry($existing, $domains, $actor);
            } else {
                $tenant = $provisioner->provision((string) $this->argument('name'), $domains, $actor, $slug);
            }
        } catch (TenantProvisioningFailed $e) {
            $this->components->error($e->getMessage());
            $this->line('  Retry with: <comment>metastyle:tenant:provision "name" --retry=<id></comment>');

            return self::FAILURE;
        }

        $this->components->info("Tenant provisioned: {$tenant->name}");
        $this->table(['Field', 'Value'], [
            ['id', $tenant->id],
            ['sequence', (string) $tenant->sequence],
            ['database', $tenant->databaseName],
            ['status', $tenant->status->value],
            ['schema version', (string) $tenant->schemaVersion],
        ]);

        return self::SUCCESS;
    }
}
