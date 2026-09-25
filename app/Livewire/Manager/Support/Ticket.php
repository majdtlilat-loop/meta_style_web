<?php

declare(strict_types=1);

namespace App\Livewire\Manager\Support;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Modules\PlatformSupport\Application\Actions\CloseCenterSupportTicket;
use App\Modules\PlatformSupport\Application\Actions\ReopenCenterSupportTicket;
use App\Modules\PlatformSupport\Application\Actions\ReplyToCenterSupportTicket;
use App\Modules\PlatformSupport\Application\CenterSupportAttachments;
use App\Modules\PlatformSupport\Application\CenterSupportDesk;
use App\Modules\PlatformSupport\Domain\Models\SupportTicket;
use App\Modules\PlatformSupport\Domain\Models\SupportTicketAttachment;
use App\Modules\PlatformSupport\Domain\Models\SupportTicketMessage;
use App\View\Label;
use App\View\Manager\ViewerTimezone;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * One of the center's own tickets: the conversation with Meta Style, a reply
 * box, and close / reopen.
 *
 * The ticket is looked up through the BOUND tenant on every request, so a
 * uuid of another center's ticket is simply not found. Internal platform
 * notes never reach this page ({@see CenterSupportDesk::conversation()}).
 */
#[Layout('components.layouts.app')]
final class Ticket extends Component
{
    use WithFileUploads;

    #[Locked]
    public string $ticketUuid = '';

    public string $body = '';

    /** @var array<int, UploadedFile> */
    public array $files = [];

    public string $error = '';

    public function mount(string $ticket, TenantContext $tenants, CenterSupportDesk $desk): void
    {
        $this->authorizeView();
        $this->ticketUuid = $desk->find($tenants->require()->id, $ticket)->uuid;
    }

    public function removeFile(int $index): void
    {
        unset($this->files[$index]);
        $this->files = array_values($this->files);
    }

    public function reply(ReplyToCenterSupportTicket $reply): void
    {
        $user = $this->requireManage();
        $this->error = '';

        $data = $this->validate([
            'body' => ['required', 'string', 'max:5000'],
            'files' => ['array', 'max:'.CenterSupportAttachments::MAX_FILES],
            'files.*' => ['file', 'max:'.CenterSupportAttachments::MAX_KILOBYTES, 'mimes:'.implode(',', CenterSupportAttachments::EXTENSIONS)],
        ], attributes: [
            'body' => __('manager_support.fields.reply'),
            'files' => __('manager_support.fields.attachments'),
            'files.*' => __('manager_support.fields.attachment'),
        ]);

        try {
            $reply($user, $this->ticketUuid, $data['body'], array_values($this->files));
        } catch (DomainException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->reset('body', 'files');
        session()->flash('notice', __('manager_support.flash.replied'));
    }

    public function close(CloseCenterSupportTicket $close): void
    {
        $close($this->requireManage(), $this->ticketUuid);
        $this->error = '';
        session()->flash('notice', __('manager_support.flash.closed'));
    }

    public function reopen(ReopenCenterSupportTicket $reopen): void
    {
        $this->error = '';

        try {
            $reopen($this->requireManage(), $this->ticketUuid);
        } catch (DomainException $e) {
            $this->error = $e->getMessage();

            return;
        }

        session()->flash('notice', __('manager_support.flash.reopened'));
    }

    public function render(TenantContext $tenants, CenterSupportDesk $desk, ViewerTimezone $timezones): View
    {
        $user = $this->authorizeView();
        $ticket = $desk->find($tenants->require()->id, $this->ticketUuid);
        $timezone = $timezones->for($user);
        $locale = app()->getLocale();
        $canManage = $user->hasPermission(Permission::PlatformSupportManage);

        return view('livewire.manager.support.ticket', [
            'ticket' => [
                'uuid' => (string) $ticket->uuid,
                'subject' => (string) $ticket->subject,
                'reference' => (string) $ticket->reference,
                'status' => (string) $ticket->status,
                'status_label' => __('manager_support.status.'.$ticket->status),
                'priority_label' => Label::for('ticket_priority', (string) $ticket->priority),
                'priority_tone' => match ($ticket->priority) {
                    'urgent' => 'danger',
                    'high' => 'warning',
                    default => 'neutral',
                },
                'opened_by' => (string) $ticket->created_by_label,
                'opened' => $this->when($ticket->created_at, $timezone, $locale),
                'resolved' => $this->when($ticket->resolved_at, $timezone, $locale),
                'closed' => $this->when($ticket->closed_at, $timezone, $locale),
            ],
            'messages' => array_map(fn (SupportTicketMessage $message): array => $this->message($ticket, $message, $timezone, $locale), $desk->conversation($ticket)),
            'canReply' => $canManage && $ticket->status !== 'closed',
            'canClose' => $canManage && $ticket->status !== 'closed',
            'canReopen' => $canManage && in_array($ticket->status, ReopenCenterSupportTicket::REOPENABLE, true),
            'back' => route('center.support'),
        ])->title($ticket->subject);
    }

    /** @return array<string, mixed> */
    private function message(SupportTicket $ticket, SupportTicketMessage $message, string $timezone, string $locale): array
    {
        $fromCenter = $message->author_type === 'center';

        return [
            'id' => (string) $message->uuid,
            'author' => (string) $message->author_label,
            'initial' => mb_strtoupper(mb_substr((string) $message->author_label, 0, 1)) ?: '·',
            'side' => $fromCenter ? 'self' : 'platform',
            'origin' => $fromCenter ? __('manager_support.author.center') : __('manager_support.author.platform'),
            'body' => (string) $message->body,
            'time' => $this->when($message->created_at, $timezone, $locale),
            'attachments' => $message->attachments
                // Only files this center can actually be handed (see CenterSupportDesk::attachment()).
                ->filter(static fn (SupportTicketAttachment $file): bool => $file->disk === CenterSupportAttachments::DISK)
                ->map(fn (SupportTicketAttachment $file): array => [
                    'name' => (string) $file->original_name,
                    'size' => $this->size((int) $file->size),
                    'href' => Route::has('center.support.attachment')
                        ? route('center.support.attachment', ['ticket' => $ticket->uuid, 'attachment' => $file->uuid])
                        : null,
                ])
                ->values()
                ->all(),
        ];
    }

    /** @return array{relative: string, full: string, iso: string}|null */
    private function when(mixed $value, string $timezone, string $locale): ?array
    {
        $instant = match (true) {
            $value instanceof DateTimeInterface => CarbonImmutable::instance($value),
            is_string($value) && $value !== '' => CarbonImmutable::parse($value, 'UTC'),
            default => null,
        };
        if ($instant === null) {
            return null;
        }

        $local = $instant->setTimezone($timezone)->locale($locale);

        return ['relative' => $local->diffForHumans(), 'full' => $local->translatedFormat('j F Y, H:i'), 'iso' => $local->toIso8601String()];
    }

    private function size(int $bytes): string
    {
        return $bytes >= 1048576
            ? __('manager_support.size.mb', ['size' => number_format($bytes / 1048576, 1)])
            : __('manager_support.size.kb', ['size' => number_format(max(1, (int) round($bytes / 1024)))]);
    }

    private function authorizeView(): User
    {
        $user = auth('web')->user();
        abort_unless($user instanceof User && $user->hasPermission(Permission::PlatformSupportView), 403);

        return $user;
    }

    private function requireManage(): User
    {
        $user = auth('web')->user();
        abort_unless($user instanceof User && $user->hasPermission(Permission::PlatformSupportManage), 403);

        return $user;
    }
}
