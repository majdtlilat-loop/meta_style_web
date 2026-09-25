<?php

declare(strict_types=1);

namespace App\Livewire\Sadmin\Announcements;

use App\Kernel\Audit\Actor;
use App\Kernel\Platform\Announcements\PlatformAnnouncement;
use App\Kernel\SaaS\Models\Plan;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use App\Livewire\Sadmin\Concerns\AuthorizesPlatform;
use App\Modules\PlatformOperations\Application\SendPlatformAnnouncement;
use DomainException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Messages from Meta Style to centers. They arrive in each center's own
 * notification inbox, in the reader's language.
 */
#[Layout('layouts.superadmin.app')]
final class Index extends Component
{
    use AuthorizesPlatform;
    use WithPagination;

    /** `compose`, `confirm`, `view:<uuid>`. */
    public ?string $panel = null;

    /** @var array<string, string> */
    public array $title = ['en' => '', 'ar' => '', 'ckb' => ''];

    /** @var array<string, string> */
    public array $body = ['en' => '', 'ar' => '', 'ckb' => ''];

    public string $severity = 'info';

    public string $audience = 'all';

    /** @var array<int, string> */
    public array $tenantIds = [];

    public string $centerSearch = '';

    public string $status = '';

    public string $planId = '';

    public function openPanel(string $panel): void
    {
        $this->requirePlatformPermission('platform.announcement.send');
        if ($panel === 'compose') {
            $this->reset('title', 'body', 'severity', 'audience', 'tenantIds', 'centerSearch', 'status', 'planId');
            $this->resetValidation();
        }
        $this->panel = $panel;
    }

    public function closePanel(): void
    {
        $this->panel = null;
        $this->resetValidation();
    }

    /** Checks the message and shows who will receive it before sending. */
    public function review(): void
    {
        $this->requirePlatformPermission('platform.announcement.send');
        $this->validate($this->rules(), [], $this->attributeNames());
        $this->panel = 'confirm';
    }

    public function send(SendPlatformAnnouncement $send): void
    {
        $user = $this->requirePlatformPermission('platform.announcement.send');
        $this->validate($this->rules(), [], $this->attributeNames());

        try {
            $announcement = $send($this->title, $this->body, $this->severity, $this->target(), Actor::platform($user));
        } catch (DomainException $exception) {
            $this->panel = 'compose';
            $this->addError('audience', $exception->getMessage());

            return;
        }

        $this->reset('title', 'body', 'severity', 'audience', 'tenantIds', 'centerSearch', 'status', 'planId');
        $this->panel = null;
        session()->flash('notice', trans_choice('sadmin_notifications.sent', $announcement->centers_count, ['count' => $announcement->centers_count]));
    }

    public function render(SendPlatformAnnouncement $send): mixed
    {
        $this->requirePlatformPermission('platform.announcement.send');
        $composing = in_array($this->panel, ['compose', 'confirm'], true);

        return view('livewire.sadmin.announcements.index', [
            'announcements' => PlatformAnnouncement::query()->latest('sent_at')->paginate(15),
            'recipients' => $composing ? count($send->recipients($this->target())) : 0,
            'centers' => $composing && $this->audience === 'selected' ? TenantModel::query()
                ->whereIn('status', ['active', 'suspended'])
                ->when($this->centerSearch !== '', fn ($q) => $q->where('name', 'like', '%'.$this->centerSearch.'%'))
                ->orderBy('name')->limit(50)->get(['id', 'name', 'status']) : collect(),
            'plans' => Plan::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'name']),
            'viewing' => $this->panel !== null && str_starts_with($this->panel, 'view:') ? PlatformAnnouncement::query()->where('uuid', substr($this->panel, 5))->first() : null,
        ]);
    }

    /** @return array{audience: 'all'|'selected'|'filtered', tenant_ids: list<string>, status: string|null, plan_id: int|null} */
    private function target(): array
    {
        $audience = in_array($this->audience, ['all', 'selected', 'filtered'], true) ? $this->audience : 'all';

        return [
            'audience' => $audience,
            'tenant_ids' => array_values(array_map('strval', $this->tenantIds)),
            'status' => $this->status !== '' ? $this->status : null,
            'plan_id' => ctype_digit($this->planId) ? (int) $this->planId : null,
        ];
    }

    /** @return array<string, list<string>> */
    private function rules(): array
    {
        return [
            'title.en' => ['required', 'string', 'max:140'],
            'title.ar' => ['nullable', 'string', 'max:140'],
            'title.ckb' => ['nullable', 'string', 'max:140'],
            'body.en' => ['required', 'string', 'max:1000'],
            'body.ar' => ['nullable', 'string', 'max:1000'],
            'body.ckb' => ['nullable', 'string', 'max:1000'],
            'severity' => ['required', 'in:info,important'],
            'audience' => ['required', 'in:all,selected,filtered'],
            'tenantIds' => [$this->audience === 'selected' ? 'required' : 'nullable', 'array'],
            'status' => ['nullable', 'in:active,suspended'],
        ];
    }

    /** @return array<string, string> */
    private function attributeNames(): array
    {
        return [
            'title.en' => __('sadmin_notifications.fields.title'),
            'body.en' => __('sadmin_notifications.fields.body'),
            'tenantIds' => __('sadmin_notifications.fields.centers'),
        ];
    }
}
