<?php

declare(strict_types=1);

namespace App\Kernel\Storage;

use App\Kernel\Tenancy\Contracts\TenantContext;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\Local\LocalFilesystemAdapter;
use LogicException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The only way Meta Style touches tenant files.
 *
 * No module builds a storage path. A single `"tenants/{$id}/logos/..."`
 * concatenated in a controller is how tenant file isolation is lost, so the
 * layout is an implementation detail of this class and nothing else
 * (docs/09-STORAGE.md §1).
 *
 * Isolation itself comes from the tenancy filesystem bootstrapper, which roots
 * the `local` and `public` disks at `tenants/{tenant-key}/` whenever a tenant
 * is initialised. This class adds three things on top:
 *
 *  - a fail-closed check, so a write with no tenant bound raises a named error
 *    instead of landing in the shared storage root;
 *  - collection semantics (which disk, public or private);
 *  - unguessable generated filenames — "public" means no auth is required if
 *    you have the exact URL, never that the URL can be guessed.
 */
final class MediaStore
{
    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * Stores contents and returns the disk-relative path.
     */
    public function put(MediaCollection $collection, string $contents, ?string $extension = null): string
    {
        $path = $this->generatePath($collection, $extension);

        $this->disk($collection)->put($path, $contents);

        return $path;
    }

    public function get(MediaCollection $collection, string $path): ?string
    {
        $disk = $this->disk($collection);

        return $disk->exists($path) ? ($disk->get($path) ?? null) : null;
    }

    public function exists(MediaCollection $collection, string $path): bool
    {
        return $this->disk($collection)->exists($path);
    }

    public function delete(MediaCollection $collection, string $path): void
    {
        $this->disk($collection)->delete($path);
    }

    /**
     * @return list<string>
     */
    public function files(MediaCollection $collection): array
    {
        return $this->disk($collection)->files($collection->directory());
    }

    /**
     * The absolute path on a local disk. Useful in tests and for local tooling;
     * business code should not need it.
     */
    public function absolutePath(MediaCollection $collection, string $path): string
    {
        return Storage::disk($collection->disk())->path($path);
    }

    /**
     * A response serving one stored file, inline, with the caller's headers.
     *
     * On a local disk it is a FILE response, which answers byte ranges
     * (206 Partial Content) — Safari and WebKit will not play a <video>
     * without them. Any other driver is streamed, as before. The driver is
     * decided here and nowhere else (docs/09-STORAGE.md §10).
     *
     * @param  array<string, string>  $headers
     */
    public function response(MediaCollection $collection, string $path, array $headers): Response
    {
        $disk = $this->disk($collection);

        if (! $disk instanceof FilesystemAdapter) {
            throw new LogicException('Media is served from a Laravel filesystem disk.');
        }

        if ($disk->getAdapter() instanceof LocalFilesystemAdapter) {
            return new BinaryFileResponse($disk->path($path), 200, $headers, true, null, false, false);
        }

        return $disk->response($path, null, $headers, 'inline');
    }

    /**
     * The tenant-rooted disk for a collection.
     *
     * Resolving the disk always requires a tenant: without one, the disks are
     * still rooted at the shared storage path, and writing there would put one
     * center's files where another could read them.
     */
    public function disk(MediaCollection $collection): Filesystem
    {
        $this->tenants->require();

        return Storage::disk($collection->disk());
    }

    /**
     * Generated, never derived from user input: an uploaded filename is
     * attacker-controlled, and a predictable name defeats the point of a
     * private-by-default store.
     */
    private function generatePath(MediaCollection $collection, ?string $extension): string
    {
        $name = (string) Str::uuid();

        if ($extension !== null && $extension !== '') {
            $name .= '.'.mb_strtolower(preg_replace('/[^A-Za-z0-9]/', '', $extension) ?? '');
        }

        return $collection->directory().'/'.$name;
    }
}
