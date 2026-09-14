<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Control-plane system seeder.
 *
 * Runs on the default (control) connection. Tenant system data is seeded
 * separately, at provisioning, by the tenancy pipeline — the two planes never
 * share a seeder (docs/03-DATABASE-MIGRATIONS.md §8).
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(ControlPlaneSeeder::class);
    }
}
