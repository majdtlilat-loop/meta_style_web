<?php

declare(strict_types=1);

namespace App\Livewire\Sadmin\Support;

use App\Kernel\Platform\Identity\Models\PlatformUser;
use App\Livewire\Sadmin\Concerns\AuthorizesPlatform;
use App\Modules\PlatformSupport\Application\Actions\ChangeSupportTicket;
use App\Modules\PlatformSupport\Application\Actions\ReplyToSupportTicket;
use App\Modules\PlatformSupport\Application\CenterSupportAttachments;
use App\Modules\PlatformSupport\Domain\Models\SupportTicket;
use DomainException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * One ticket: the conversation on the left, its state on the right.
 * An internal note never reaches the center; that is decided by the Action,
 * the toggle here only asks for it.
 */
#[Layout('layouts.superadmin.app')]
final class Show extends Component
{
    use AuthorizesPlatform;
    use WithFileUploads;

    public string $ticketUuid = '';

    public string $body = '';

    public bool $internal = false;

    public string $status = '';

    public string $priority = '';

    public ?int $assigneeId = null;

    public string $reason = '';

    public mixed $attachment = null;

    public function mount(string $ticket): void
    {
        $this->ticketUuid = $ticket;
        $model = $this->ticket();
        $this->status = $model->status;
        $this->priority = $model->priority;
        $this->assigneeId = $model->assigned_platform_user_id === null ? null : (int) $model->assigned_platform_user_id;
    }

    public function reply(ReplyToSupportTicket $reply, CenterSupportAttachments $stored): void
    {
        $user = $this->requirePlatformPermission('platform.support.manage');
        $this->validate([
            'body' => ['required', 'string', 'max:5000'],
            'internal' => ['boolean'],
            'attachment' => ['nullable', 'file', 'max:5120', 'mimes:pdf,png,jpg,jpeg,txt'],
        ]);
        // The same control-plane store the center writes to, so a file Meta
        // Style attaches to a public reply is one the center can download.
        $attachments = [];
        if ($this->attachment !== null) {
            try {
                $attachments = $stored->store($this->ticketUuid, [$this->attachment]);
            } catch (DomainException $exception) {
                $this->addError('attachment', $exception->getMessage());

                return;
            }
        }
        $wasInternal = $this->internal;
        $reply($this->ticket(), $this->body, $this->internal, 'platform', (string) $user->getKey(), $user->name, $attachments);
        $this->reset('body', 'internal', 'attachment');
        session()->flash('notice', $wasInternal ? __('sadmin_support.note_added') : __('sadmin_support.reply_added'));
    }

    public function updateState(ChangeSupportTicket $change): void
    {
        $user = $this->requirePlatformPermission('platform.support.manage');
        $this->validate([
            'status' => ['required', 'in:open,in_progress,waiting_center,resolved,closed'],
            'priority' => ['required', 'in:low,normal,high,urgent'],
            'assigneeId' => ['nullable', 'integer', 'exists:control.platform_users,id'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        $change($this->ticket(), $this->status, $this->priority, $this->assigneeId, (string) $user->getKey(), $user->name, $this->reason);
        $this->reset('reason');
        session()->flash('notice', __('sadmin_support.state_updated'));
    }

    public function render(): mixed
    {
        $user = $this->requirePlatformPermission('platform.support.view');
        $ticket = $this->ticket()->load(['tenant:id,name,slug', 'messages' => fn ($q) => $q->orderBy('created_at')->orderBy('id'), 'messages.attachments']);

        return view('livewire.sadmin.support.show', [
            'ticket' => $ticket,
            'agents' => PlatformUser::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'canManage' => $user->hasPermission('platform.support.manage'),
            'stateChanged' => $this->status !== $ticket->status || $this->priority !== $ticket->priority
                || $this->assigneeId !== ($ticket->assigned_platform_user_id === null ? null : (int) $ticket->assigned_platform_user_id),
        ]);
    }

    private function ticket(): SupportTicket
    {
        return SupportTicket::query()->where('uuid', $this->ticketUuid)->firstOrFail();
    }
}
