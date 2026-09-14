<?php

declare(strict_types=1);

namespace App\Kernel\Tenancy\Infrastructure;

use Stancl\Tenancy\Database\Models\Domain as BaseDomain;

/**
 * A host that identifies a tenant.
 *
 * Inside the tenancy infrastructure layer — the only place `Stancl\*` may be
 * referenced (ADR-018).
 *
 * @property string $domain
 * @property string $tenant_id
 * @property bool $is_primary
 */
final class DomainModel extends BaseDomain
{
    protected $table = 'domains';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }
}
