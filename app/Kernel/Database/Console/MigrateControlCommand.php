<?php

declare(strict_types=1);

namespace App\Kernel\Database\Console;

use Illuminate\Console\Command;

/**
 * Migrates the control-plane database.
 *
 * Plain `php artisan migrate` targets the default connection and the default
 * migration path, which is neither of Meta Style's two migration sets. This
 * command is the supported workflow (docs/03-DATABASE-MIGRATIONS.md §3).
 *
 * Tenant migration commands arrive in Phase 2.
 */
final class MigrateControlCommand extends Command
{
    protected $signature = 'metastyle:control:migrate
        {--force : Run without confirmation in production}
        {--pretend : Show the SQL that would run}
        {--step : Run each migration in its own batch}';

    protected $description = 'Run the control-plane migrations (database/migrations/control)';

    public function handle(): int
    {
        $this->components->info('Migrating control plane ['.config('database.connections.control.database').']');

        return $this->call('migrate', [
            '--database' => 'control',
            '--path' => 'database/migrations/control',
            '--force' => (bool) $this->option('force'),
            '--pretend' => (bool) $this->option('pretend'),
            '--step' => (bool) $this->option('step'),
        ]);
    }
}
