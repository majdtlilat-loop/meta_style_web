<?php

declare(strict_types=1);

use App\Kernel\Platform\Identity\Http\Middleware\EnsurePlatformMfa;
use App\Kernel\Platform\Identity\Models\PlatformUser;
use App\Livewire\Sadmin\Support\Index as SupportIndex;
use App\Livewire\Sadmin\Support\Show as SupportShow;
use App\Modules\PlatformSupport\Domain\Models\SupportTicket;
use App\Modules\PlatformSupport\Domain\Models\SupportTicketAttachment;
use Database\Seeders\LocalDevelopmentPlatformUserSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The support workspace
|--------------------------------------------------------------------------
|
| A ticket is opened, answered, annotated internally, reassigned and its
| attachment downloaded — only through its own ticket's URL.
|
*/

beforeEach(function (): void {
    $this->withoutVite();
    app()->call([app(LocalDevelopmentPlatformUserSeeder::class), 'run']);
    $this->platformUser = PlatformUser::query()->where('email', 'admin@meta-style.local')->firstOrFail();
    $this->actingAs($this->platformUser, 'platform');
    Storage::fake('local');
});

afterEach(function (): void {
    $tickets = SupportTicket::query()->where('subject', 'like', 'Workspace test%')->pluck('id');
    $messages = DB::connection('control')->table('support_ticket_messages')->whereIn('ticket_id', $tickets)->pluck('id');
    DB::connection('control')->table('support_ticket_attachments')->whereIn('message_id', $messages)->delete();
    DB::connection('control')->table('support_ticket_messages')->whereIn('id', $messages)->delete();
    DB::connection('control')->table('support_tickets')->whereIn('id', $tickets)->delete();
    $this->tearDownRegisteredCenters();
});

it('opens, answers, annotates and reassigns a ticket', function (): void {
    $center = $this->registerCenter('Support Center', 'owner@support-workspace.test');

    Livewire::test(SupportIndex::class)
        ->call('openCreate')
        ->assertSet('creating', true)
        ->set('tenantId', $center['tenant']->id)
        ->set('subject', 'Workspace test: booking page')
        ->set('body', 'The booking page shows the wrong hours.')
        ->set('newPriority', 'high')
        ->call('create')
        ->assertHasNoErrors();

    $ticket = SupportTicket::query()->where('subject', 'Workspace test: booking page')->firstOrFail();

    Livewire::test(SupportShow::class, ['ticket' => $ticket->uuid])
        ->assertSee('Workspace test: booking page')
        ->assertSee('The booking page shows the wrong hours.')
        ->set('body', 'We are looking into it.')
        ->set('attachment', UploadedFile::fake()->create('hours.pdf', 12, 'application/pdf'))
        ->call('reply')
        ->assertHasNoErrors()
        ->set('internal', true)
        ->set('body', 'Branch timezone is misconfigured.')
        ->call('reply')
        ->assertHasNoErrors()
        ->assertSee('Branch timezone is misconfigured.')
        ->set('status', 'in_progress')
        ->set('assigneeId', $this->platformUser->id)
        ->set('reason', 'Taking ownership of the ticket')
        ->call('updateState')
        ->assertHasNoErrors();

    $ticket->refresh();
    expect($ticket->status)->toBe('in_progress')
        ->and((int) $ticket->assigned_platform_user_id)->toBe((int) $this->platformUser->id)
        ->and($ticket->messages()->where('is_internal', true)->count())->toBe(1);

    $attachment = SupportTicketAttachment::query()->whereIn('message_id', $ticket->messages()->pluck('id'))->firstOrFail();

    $this->platformUser->forceFill(['mfa_confirmed_at' => now()])->save();
    $this->withSession([EnsurePlatformMfa::SESSION_KEY => now()->timestamp])
        ->get('http://superadmin.localhost:8000/support/'.$ticket->uuid.'/attachments/'.$attachment->uuid)
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertDownload('hours.pdf');

    // The same attachment under another ticket's address does not exist.
    $other = SupportTicket::query()->create([
        'tenant_id' => $center['tenant']->id, 'reference' => 'ST-OTHER', 'subject' => 'Workspace test: other',
        'status' => 'open', 'priority' => 'low', 'created_by_type' => 'platform', 'created_by_label' => 'Test', 'last_activity_at' => now(),
    ]);
    $this->get('http://superadmin.localhost:8000/support/'.$other->uuid.'/attachments/'.$attachment->uuid)->assertNotFound();
});
