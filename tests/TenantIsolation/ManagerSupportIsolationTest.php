<?php

declare(strict_types=1);

use App\Livewire\Manager\Support\Index as SupportIndex;
use App\Modules\PlatformSupport\Application\Actions\CloseCenterSupportTicket;
use App\Modules\PlatformSupport\Application\Actions\OpenCenterSupportTicket;
use App\Modules\PlatformSupport\Application\Actions\ReopenCenterSupportTicket;
use App\Modules\PlatformSupport\Application\Actions\ReplyToCenterSupportTicket;
use App\Modules\PlatformSupport\Application\CenterSupportAttachments;
use App\Modules\PlatformSupport\Application\CenterSupportDesk;
use App\Modules\PlatformSupport\Domain\Models\SupportTicket;
use App\Modules\PlatformSupport\Domain\Models\SupportTicketAttachment;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Center support tickets (control plane) per center
|--------------------------------------------------------------------------
|
| Tickets live in the control database, so isolation is a WHERE, not a
| database: every read and write is scoped by the BOUND tenant's id. Another
| center's ticket, message or file must be "not found" — never listed,
| answered, closed, reopened or downloaded — and nothing done in one center
| may change the other's.
|
*/

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});

it('keeps each center to its own support tickets, replies, state and files', function (): void {
    Storage::fake(CenterSupportAttachments::DISK);

    $alpha = $this->registerCenter('Support Alpha', 'owner@support-alpha.test');
    $beta = $this->registerCenter('Support Beta', 'owner@support-beta.test');
    $alphaOwner = $this->ownerOf($alpha['tenant']);
    $betaOwner = $this->ownerOf($beta['tenant']);
    $betaSlug = $beta['registration']->requested_slug;

    /** @var SupportTicket $ticket */
    $ticket = $this->asCenter($alpha['tenant'], fn (): SupportTicket => app(OpenCenterSupportTicket::class)(
        $alphaOwner,
        'Alpha private question',
        'Only Alpha may read this.',
        'normal',
        [UploadedFile::fake()->image('alpha.png', 20, 20)],
    ));
    $file = SupportTicketAttachment::query()->firstOrFail();

    expect($ticket->tenant_id)->toBe($alpha['tenant']->id);

    $this->asCenter($beta['tenant'], function () use ($ticket, $betaOwner, $betaSlug, $file, $beta): void {
        $desk = app(CenterSupportDesk::class);

        // Not listed, not counted, not found.
        expect($desk->page($beta['tenant']->id, 'all')->total())->toBe(0)
            ->and($desk->counts($beta['tenant']->id)['all'])->toBe(0)
            ->and(fn () => $desk->find($beta['tenant']->id, $ticket->uuid))->toThrow(ModelNotFoundException::class);

        Livewire::actingAs($betaOwner)->test(SupportIndex::class)
            ->call('setStatus', 'all')
            ->assertDontSee('Alpha private question');

        // Beta's own Actions cannot reach it: the bound tenant decides.
        expect(fn () => app(ReplyToCenterSupportTicket::class)($betaOwner, $ticket->uuid, 'From Beta'))->toThrow(ModelNotFoundException::class)
            ->and(fn () => app(CloseCenterSupportTicket::class)($betaOwner, $ticket->uuid))->toThrow(ModelNotFoundException::class)
            ->and(fn () => app(ReopenCenterSupportTicket::class)($betaOwner, $ticket->uuid))->toThrow(ModelNotFoundException::class);

        // Nor Beta's host: the page and the file are simply not there.
        $this->actingAs($betaOwner);
        $this->get("http://{$betaSlug}.localhost:8000/manager/support/{$ticket->uuid}")->assertNotFound();
        $this->get("http://{$betaSlug}.localhost:8000/manager/support/{$ticket->uuid}/attachments/{$file->uuid}")->assertNotFound();
    });

    $fresh = $ticket->fresh();
    expect($fresh?->status)->toBe('open')
        ->and($fresh?->tenant_id)->toBe($alpha['tenant']->id)
        ->and(SupportTicket::query()->where('tenant_id', $beta['tenant']->id)->count())->toBe(0);

    // Alpha still reads its own ticket and file.
    $this->asCenter($alpha['tenant'], function () use ($ticket, $alpha): void {
        expect(app(CenterSupportDesk::class)->attachment($alpha['tenant']->id, $ticket->uuid, (string) SupportTicketAttachment::query()->value('uuid'))->original_name)->toBe('alpha.png');
    });
});
