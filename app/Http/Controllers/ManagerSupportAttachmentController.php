<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Modules\PlatformSupport\Application\CenterSupportAttachments;
use App\Modules\PlatformSupport\Application\CenterSupportDesk;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Hands a center one file from its OWN support ticket with Meta Style.
 *
 * `platform_support.view`, then the attachment is found through the bound
 * tenant's ticket and a message the center may read — another center's file,
 * or one on an internal note, is not found. Always a download with the stored
 * MIME type and `nosniff`; an uploaded file is never rendered inline.
 */
final class ManagerSupportAttachmentController extends Controller
{
    public function __invoke(Request $request, TenantContext $tenants, CenterSupportDesk $desk): StreamedResponse
    {
        // By name: the host's {center} is a route parameter too, so positional
        // binding would hand the slug to the first string argument.
        $ticket = (string) $request->route('ticket');
        $attachment = (string) $request->route('attachment');
        $user = $request->user('web');
        abort_unless($user instanceof User && $user->hasPermission(Permission::PlatformSupportView), 403);

        $record = $desk->attachment($tenants->require()->id, $ticket, $attachment);
        abort_unless($record->disk === CenterSupportAttachments::DISK, 404);

        $disk = Storage::disk((string) $record->disk);
        abort_unless($disk->exists((string) $record->path), 404);

        return $disk->download((string) $record->path, (string) $record->original_name, [
            'Content-Type' => (string) $record->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
