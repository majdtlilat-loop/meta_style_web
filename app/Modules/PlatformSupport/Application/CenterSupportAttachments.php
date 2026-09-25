<?php

declare(strict_types=1);

namespace App\Modules\PlatformSupport\Application;

use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Files a center attaches to its support conversation with Meta Style.
 *
 * ## A control-plane store, never the tenant's
 *
 * A support ticket lives in the control database and is read by the platform
 * team from the platform host, so its files live on the `platform_support`
 * disk (config/filesystems.php) — deliberately NOT one of the tenant-suffixed
 * disks in config/tenancy.php. The path is built from the ticket's uuid and a
 * fresh uuid; nothing a person typed reaches it.
 *
 * ## What is accepted
 *
 * PDF, PNG, JPEG and plain text, 5 MB each, three per message. The extension
 * is taken from the file's CONTENT (finfo), never from its name, and the
 * stored MIME type is what finfo saw. Downloads always go out as attachments
 * with `nosniff` — an uploaded file is never rendered inline.
 */
final class CenterSupportAttachments
{
    public const DISK = 'platform_support';

    public const MAX_KILOBYTES = 5120;

    public const MAX_FILES = 3;

    /** @var list<string> */
    public const EXTENSIONS = ['pdf', 'png', 'jpg', 'jpeg', 'txt'];

    /**
     * Stores the files and returns the attachment rows to record.
     *
     * @param  list<UploadedFile>  $files
     * @return list<array{disk: string, path: string, original_name: string, mime: string, size: int, sha256: string}>
     */
    public function store(string $ticketUuid, array $files): array
    {
        if (count($files) > self::MAX_FILES) {
            throw new DomainException(__('manager_support.errors.too_many_files', ['count' => self::MAX_FILES]));
        }

        $stored = [];

        try {
            foreach ($files as $file) {
                $extension = strtolower((string) $file->guessExtension());
                $size = (int) $file->getSize();

                if (! in_array($extension, self::EXTENSIONS, true) || $size <= 0 || $size > self::MAX_KILOBYTES * 1024) {
                    throw new DomainException(__('manager_support.errors.file_rejected'));
                }

                $real = (string) $file->getRealPath();
                $path = Storage::disk(self::DISK)->putFileAs('tickets/'.$ticketUuid, $file, Str::uuid()->toString().'.'.$extension);

                if (! is_string($path) || $path === '') {
                    throw new DomainException(__('manager_support.errors.file_rejected'));
                }

                $stored[] = [
                    'disk' => self::DISK,
                    'path' => $path,
                    'original_name' => $this->displayName($file->getClientOriginalName(), $extension),
                    'mime' => (string) ($file->getMimeType() ?: 'application/octet-stream'),
                    'size' => $size,
                    'sha256' => (string) hash_file('sha256', $real),
                ];
            }
        } catch (DomainException $e) {
            $this->discard($stored);

            throw $e;
        }

        return $stored;
    }

    /**
     * Removes files stored for a message that was then refused.
     *
     * @param  list<array{disk: string, path: string, original_name: string, mime: string, size: int, sha256: string}>  $stored
     */
    public function discard(array $stored): void
    {
        foreach ($stored as $file) {
            Storage::disk($file['disk'])->delete($file['path']);
        }
    }

    /** The name shown and offered on download: a plain base name, never a path. */
    private function displayName(string $original, string $extension): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '', basename(str_replace('\\', '/', $original))));
        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'attachment.'.$extension;
        }

        return Str::limit($name, 180, '');
    }
}
