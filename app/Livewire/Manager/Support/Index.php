<?php

declare(strict_types=1);

namespace App\Livewire\Manager\Support;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Modules\PlatformSupport\Application\Actions\OpenCenterSupportTicket;
use App\Modules\PlatformSupport\Application\CenterSupportAttachments;
use App\Modules\PlatformSupport\Application\CenterSupportDesk;
use App\Modules\PlatformSupport\Domain\Models\SupportTicket;
use App\View\Label;
use App\View\Manager\ViewerTimezone;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * The center's own tickets with Meta Style — separate from its customers'
 * conversations. `platform_support.view` to read, `.manage` to open one.
 *
 * Every read and write goes through {@see CenterSupportDesk} and the center
 * Actions, scoped by the BOUND tenant: nothing on this page names a center.
 * `?subject=` (from the Plan page's "Ask to switch") opens the form prefilled
 * for someone who may open a ticket.
 */
#[Layout('components.layouts.app')]
final class Index extends Component
{
    use WithFileUploads;
    use WithPagination;

    #[Url(except: 'active')]
    public string $status = 'active';

    #[Url(as: 'subject', except: '')]
    public string $prefill = '';

    public bool $creating = false;

    public string $subject = '';

    public string $body = '';

    public string $priority = 'normal';

    /** @var array<int, UploadedFile> */
    public array $files = [];

    public string $error = '';

    public function mount(): void
    {
        $this->authorizeView();

        if ($this->prefill !== '' && $this->can(Permission::PlatformSupportManage)) {
            $this->openCreate();
            $this->subject = Str::limit(trim($this->prefill), 190, '');
        }

        $this->prefill = '';
    }

    public function setStatus(string $status): void
    {
        $this->status = array_key_exists($status, CenterSupportDesk::FILTERS) ? $status : 'active';
        $this->resetPage();
    }

    public function openCreate(): void
    {
        $this->requireManage();
        $this->reset('subject', 'body', 'priority', 'files', 'error');
        $this->resetValidation();
        $this->creating = true;
    }

    public function closeCreate(): void
    {
        $this->creating = false;
        $this->reset('files', 'error');
        $this->resetValidation();
    }

    public function removeFile(int $index): void
    {
        unset($this->files[$index]);
        $this->files = array_values($this->files);
    }

    public function create(OpenCenterSupportTicket $open): mixed
    {
        $user = $this->requireManage();
        $this->error = '';

        $data = $this->validate([
            'subject' => ['required', 'string', 'max:190'],
            'body' => ['required', 'string', 'max:5000'],
            'priority' => ['required', 'in:'.implode(',', OpenCenterSupportTicket::PRIORITIES)],
            'files' => ['array', 'max:'.CenterSupportAttachments::MAX_FILES],
            'files.*' => ['file', 'max:'.CenterSupportAttachments::MAX_KILOBYTES, 'mimes:'.implode(',', CenterSupportAttachments::EXTENSIONS)],
        ], attributes: [
            'subject' => __('manager_support.fields.subject'),
            'body' => __('manager_support.fields.message'),
            'priority' => __('manager_support.fields.priority'),
            'files' => __('manager_support.fields.attachments'),
            'files.*' => __('manager_support.fields.attachment'),
        ]);

        try {
            $ticket = $open($user, $data['subject'], $data['body'], $data['priority'], array_values($this->files));
        } catch (DomainException $e) {
            $this->error = $e->getMessage();

            return null;
        }

        $this->reset('subject', 'body', 'priority', 'files');
        $this->creating = false;
        session()->flash('notice', __('manager_support.flash.created', ['reference' => $ticket->reference]));

        return $this->redirectRoute('center.support.show', ['ticket' => $ticket->uuid], navigate: true);
    }

    public function render(TenantContext $tenants, CenterSupportDesk $desk, ViewerTimezone $timezones): View
    {
        $user = $this->authorizeView();
        $tenantId = $tenants->require()->id;
        $this->status = array_key_exists($this->status, CenterSupportDesk::FILTERS) ? $this->status : 'active';

        $tickets = $desk->page($tenantId, $this->status);
        $timezone = $timezones->for($user);
        $locale = app()->getLocale();

        return view('livewire.manager.support.index', [
            'tickets' => $tickets,
            'rows' => array_map(fn (SupportTicket $ticket): array => $this->row($ticket, $timezone, $locale), $tickets->items()),
            'counts' => $desk->counts($tenantId),
            'filters' => array_keys(CenterSupportDesk::FILTERS),
            'canManage' => $user->hasPermission(Permission::PlatformSupportManage),
            'priorities' => array_map(static fn (string $value): array => ['value' => $value, 'label' => Label::for('ticket_priority', $value)], OpenCenterSupportTicket::PRIORITIES),
        ])->title(__('manager_support.title'));
    }

    /** @return array<string, mixed> */
    private function row(SupportTicket $ticket, string $timezone, string $locale): array
    {
        $activity = self::instant($ticket->last_activity_at)?->setTimezone($timezone)->locale($locale);

        return [
            'uuid' => (string) $ticket->uuid,
            'subject' => (string) $ticket->subject,
            'reference' => (string) $ticket->reference,
            'status' => (string) $ticket->status,
            'status_label' => __('manager_support.status.'.$ticket->status),
            'priority' => (string) $ticket->priority,
            'priority_label' => Label::for('ticket_priority', (string) $ticket->priority),
            'priority_tone' => match ($ticket->priority) {
                'urgent' => 'danger',
                'high' => 'warning',
                default => 'neutral',
            },
            'opened_by' => (string) $ticket->created_by_label,
            'activity' => $activity?->diffForHumans(),
            'activity_full' => $activity?->translatedFormat('j F Y, H:i'),
            'activity_iso' => $activity?->toIso8601String(),
            'href' => route('center.support.show', ['ticket' => $ticket->uuid]),
        ];
    }

    /** A stored instant, whatever shape the model hands it over in. */
    private static function instant(mixed $value): ?CarbonImmutable
    {
        return match (true) {
            $value instanceof DateTimeInterface => CarbonImmutable::instance($value),
            is_string($value) && $value !== '' => CarbonImmutable::parse($value, 'UTC'),
            default => null,
        };
    }

    private function can(Permission $permission): bool
    {
        $user = auth('web')->user();

        return $user instanceof User && $user->hasPermission($permission);
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
