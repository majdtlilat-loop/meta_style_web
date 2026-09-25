<?php

declare(strict_types=1);

namespace App\Kernel\Tenancy\Infrastructure;

use Illuminate\Contracts\Foundation\Application;
use RuntimeException;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Gives a center's suffixed storage path the one directory the FRAMEWORK
 * writes into on its own.
 *
 * The filesystem bootstrapper moves `storage_path()` to `tenants/{key}/`
 * (docs/09-STORAGE.md §2) but creates nothing there; the disks make their own
 * directories as files are written. Laravel's real-time facades do not use a
 * disk: the first time a `Facades\…` class is used in a process, the framework
 * writes its stub to `storage_path('framework/cache')`. Livewire's file uploads
 * use one (`GenerateSignedUploadUrl`), so a center's first upload — a logo, a
 * catalog photo, a screen's promotional image — failed with a 500 whenever that
 * directory did not exist yet, which for a center that has never stored a file
 * is always.
 *
 * Checked on every bootstrap, not only at provisioning: centers that already
 * exist need it too, and a new application server starts with an empty
 * storage directory. It is a single `is_dir()` once the directory exists.
 *
 * Runs after `FilesystemTenancyBootstrapper`, which it depends on. Nothing to
 * revert — the central storage path is restored by that bootstrapper.
 */
final class TenantStorageBootstrapper implements TenancyBootstrapper
{
    public function __construct(private readonly Application $app) {}

    public function bootstrap(Tenant $tenant): void
    {
        $directory = $this->app->storagePath('framework/cache');

        // `@mkdir` only because two requests may create it at the same moment,
        // and the loser's "File exists" warning is not a failure. Whether the
        // directory exists afterwards is what decides, and that is checked.
        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException('The center storage directory could not be created: '.$directory);
        }
    }

    public function revert(): void
    {
        // The filesystem bootstrapper restores the central storage path.
    }
}
