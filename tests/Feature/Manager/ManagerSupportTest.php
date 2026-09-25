<?php

declare(strict_types=1);

use App\Kernel\Authorization\SystemRole;
use App\Livewire\Manager\Support\Index as SupportIndex;
use App\Livewire\Manager\Support\Ticket as SupportTicketPage;
use App\Modules\PlatformSupport\Application\Actions\CloseCenterSupportTicket;
use App\Modules\PlatformSupport\Application\Actions\ReplyToSupportTicket;
use App\Modules\PlatformSupport\Application\CenterSupportAttachments;
use App\Modules\PlatformSupport\Domain\Models\SupportTicket;
use App\Modules\PlatformSupport\Domain\Models\SupportTicketAttachment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Center → Meta Style support
|--------------------------------------------------------------------------
|
| The center's own tickets, in the control plane, always scoped by the BOUND
| tenant. `platform_support.view` reads, `.manage` writes. Close and reopen
| are center Actions with a history row each; internal platform notes never
| reach the center; attachments are control-plane files with strict types.
|
*/

beforeEach(function (): void {
    Storage::fake(CenterSupportAttachments::DISK);
});

it('opens a request, lists it with its status filter, and prefills a plan-change subject', function (): void {
    $center = $this->registerCenter('Support Center', 'owner@support-center.test');
    $owner = $this->ownerOf($center['tenant']);
    $tenantId = $center['tenant']->id;

    $this->asCenter($center['tenant'], function () use ($owner, $tenantId): void {
        app()->setLocale('en');

        Livewire::withQueryParams(['subject' => 'Plan change request: Business'])
            ->actingAs($owner)
            ->test(SupportIndex::class)
            ->assertSet('creating', true)
            ->assertSet('subject', 'Plan change request: Business')
            ->assertSet('prefill', '')
            ->set('body', 'We would like finance and online payments.')
            ->set('priority', 'high')
            ->call('create')
            ->assertHasNoErrors()
            ->assertRedirect();

        /** @var SupportTicket $ticket */
        $ticket = SupportTicket::query()->where('tenant_id', $tenantId)->firstOrFail();
        expect($ticket->subject)->toBe('Plan change request: Business')
            ->and($ticket->priority)->toBe('high')
            ->and($ticket->created_by_type)->toBe('center')
            ->and($ticket->created_by_id)->toBe((string) $owner->getKey());

        Livewire::actingAs($owner)
            ->test(SupportIndex::class)
            ->assertSee('Plan change request: Business')
            ->assertSee(__('manager_support.status.open'))
            ->call('setStatus', 'closed')
            ->assertSet('status', 'closed')
            ->assertDontSee('Plan change request: Business')
            ->call('setStatus', 'anything')
            ->assertSet('status', 'active');
    });
});

it('lets the center close and reopen its ticket, with history, and keeps platform notes out', function (): void {
    $center = $this->registerCenter('Support Lifecycle', 'owner@support-lifecycle.test');
    $owner = $this->ownerOf($center['tenant']);
    $slug = $center['registration']->requested_slug;
    $tenantId = $center['tenant']->id;

    $this->asCenter($center['tenant'], function () use ($owner, $slug, $tenantId): void {
        app()->setLocale('en');

        Livewire::actingAs($owner)->test(SupportIndex::class)
            ->call('openCreate')
            ->set('subject', 'Receipt printer')
            ->set('body', 'The 80mm layout cuts the logo.')
            ->call('create');

        /** @var SupportTicket $ticket */
        $ticket = SupportTicket::query()->where('tenant_id', $tenantId)->firstOrFail();
        // The platform team answers, and leaves itself a private note.
        app(ReplyToSupportTicket::class)($ticket, 'Please send a photo.', false, 'platform', '1', 'Meta Style Support');
        app(ReplyToSupportTicket::class)($ticket, 'Internal: known driver issue', true, 'platform', '1', 'Meta Style Support');

        $page = Livewire::actingAs($owner)
            ->test(SupportTicketPage::class, ['ticket' => $ticket->uuid])
            ->assertSee('Please send a photo.')
            ->assertDontSee('Internal: known driver issue')
            ->set('body', 'Here it is.')
            ->call('reply')
            ->assertHasNoErrors()
            ->assertSee('Here it is.')
            ->call('close');

        expect($ticket->fresh()?->status)->toBe('closed')
            ->and(DB::connection('control')->table('support_ticket_history')->where('ticket_id', $ticket->id)->where('event', 'closed_by_center')->count())->toBe(1);

        // Closed: no reply box, and the Action refuses a reply anyway.
        $page->assertDontSee('id="support-reply"', false)
            ->set('body', 'One more thing')
            ->call('reply')
            ->assertSet('error', __('manager_support.errors.closed'))
            ->call('reopen')
            ->assertSet('error', '');

        expect($ticket->fresh()?->status)->toBe('open')
            ->and($ticket->fresh()?->closed_at)->toBeNull()
            ->and(DB::connection('control')->table('support_ticket_history')->where('ticket_id', $ticket->id)->where('event', 'reopened_by_center')->where('actor_type', 'center')->count())->toBe(1);

        // An open ticket cannot be "reopened".
        $page->call('reopen')->assertSet('error', __('manager_support.errors.not_reopenable'));

        // The page renders in the shell under Communication › Support.
        $this->actingAs($owner);
        $this->get("http://{$slug}.localhost:8000/manager/support/{$ticket->uuid}?locale=en")
            ->assertOk()
            ->assertSee('Receipt printer')
            ->assertSee('data-confirm-tone="danger"', false)
            ->assertDontSee('Internal: known driver issue');
    });
});

it('refuses writes to somebody who may only read, in the Action as well as the page', function (): void {
    $center = $this->registerCenter('Support Readonly', 'owner@support-readonly.test');
    $owner = $this->ownerOf($center['tenant']);
    $tenantId = $center['tenant']->id;

    $this->asCenter($center['tenant'], function () use ($owner, $tenantId): void {
        Livewire::actingAs($owner)->test(SupportIndex::class)
            ->call('openCreate')->set('subject', 'Question')->set('body', 'Hello')->call('create');
        $ticket = SupportTicket::query()->where('tenant_id', $tenantId)->firstOrFail();

        // A cashier holds no support permission at all.
        $cashier = $this->seedStaffMember(SystemRole::Cashier, name: 'Cashier Awat');
        Livewire::actingAs($cashier)->test(SupportIndex::class)->assertForbidden();

        expect(fn () => app(CloseCenterSupportTicket::class)($cashier, $ticket->uuid))->toThrow(AuthorizationException::class)
            ->and($ticket->fresh()?->status)->toBe('open');
    });
});

it('attaches an image and hands it back as a download with nosniff', function (): void {
    $center = $this->registerCenter('Support Files', 'owner@support-files.test');
    $owner = $this->ownerOf($center['tenant']);
    $slug = $center['registration']->requested_slug;
    $tenantId = $center['tenant']->id;

    $this->asCenter($center['tenant'], function () use ($owner, $slug, $tenantId): void {
        Livewire::actingAs($owner)->test(SupportIndex::class)
            ->call('openCreate')
            ->set('subject', 'Logo looks blurry')
            ->set('body', 'See the screenshot.')
            ->set('files', [UploadedFile::fake()->image('screenshot.png', 40, 40)])
            ->call('create')
            ->assertHasNoErrors();

        $ticket = SupportTicket::query()->where('tenant_id', $tenantId)->firstOrFail();
        /** @var SupportTicketAttachment $file */
        $file = SupportTicketAttachment::query()->firstOrFail();

        expect($file->disk)->toBe(CenterSupportAttachments::DISK)
            ->and($file->mime)->toBe('image/png')
            ->and($file->original_name)->toBe('screenshot.png')
            ->and(str_starts_with((string) $file->path, 'tickets/'.$ticket->uuid.'/'))->toBeTrue();
        Storage::disk(CenterSupportAttachments::DISK)->assertExists((string) $file->path);

        // A file that is not what it claims is refused.
        Livewire::actingAs($owner)->test(SupportTicketPage::class, ['ticket' => $ticket->uuid])
            ->set('body', 'And a script')
            ->set('files', [UploadedFile::fake()->create('notes.exe', 4, 'application/x-msdownload')])
            ->call('reply')
            ->assertHasErrors(['files.0']);

        $this->actingAs($owner);
        $this->get("http://{$slug}.localhost:8000/manager/support/{$ticket->uuid}/attachments/{$file->uuid}")
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Type', 'image/png')
            ->assertDownload('screenshot.png');
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
