<?php

declare(strict_types=1);

namespace App\Livewire\Center;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\TenantLocales;
use App\Modules\Branches\Application\Actions\ArchiveBranch;
use App\Modules\Branches\Application\Actions\SaveBranch;
use App\Modules\Branches\Application\Actions\SaveBranchSchedule;
use App\Modules\Branches\Domain\Data\BranchInput;
use App\Modules\Branches\Domain\Models\Branch;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Branch management: details, and the weekly schedule.
 *
 * Every write goes through an Action, so the permission check, the branch-scope
 * check, the transaction and the audit entry are the same ones the API gets.
 * A Livewire component that wrote to the model directly would be a second,
 * quieter way to change a branch.
 */
#[Layout('components.layouts.app')]
final class Branches extends Component
{
    public ?string $editing = null;

    /** @var array<string, string> */
    public array $name = [];

    /** @var array<string, string> */
    public array $address = [];

    public string $timezone = 'Asia/Baghdad';

    public string $phone = '';

    public string $whatsapp = '';

    public string $email = '';

    public string $mapUrl = '';

    public bool $isActive = true;

    public bool $isPublic = true;

    /** @var list<array{day_of_week: int, opens_at: string, closes_at: string}> */
    public array $hours = [];

    public string $notice = '';

    public function mount(): void
    {
        $this->resetForm();
    }

    /**
     * The locales this center has enabled.
     *
     * A plain method rather than a Livewire computed property: the forms need
     * it once per render, and a magic `$this->locales` is invisible to static
     * analysis.
     *
     * @return list<string>
     */
    private function enabledLocales(): array
    {
        return app(TenantLocales::class)->enabled();
    }

    public function edit(string $uuid): void
    {
        $branch = Branch::query()->where('uuid', $uuid)->firstOrFail();

        $this->editing = $uuid;
        $this->name = $branch->name->all();
        $this->address = $branch->address?->all() ?? [];
        $this->timezone = $branch->timezone;
        $this->phone = (string) $branch->phone;
        $this->whatsapp = (string) $branch->whatsapp;
        $this->email = (string) $branch->email;
        $this->mapUrl = (string) $branch->map_url;
        $this->isActive = $branch->is_active;
        $this->isPublic = $branch->is_public;

        $this->hours = $branch->workingHours->map(fn ($h): array => [
            'day_of_week' => $h->day_of_week,
            'opens_at' => mb_substr($h->opens_at, 0, 5),
            'closes_at' => mb_substr($h->closes_at, 0, 5),
        ])->values()->all();
    }

    public function addInterval(int $day = 0): void
    {
        // Adding a second interval to a day is how a split shift is entered —
        // 09:00–13:00 and 16:00–22:00 are two rows, not one field.
        $this->hours[] = ['day_of_week' => $day, 'opens_at' => '09:00', 'closes_at' => '17:00'];
    }

    public function removeInterval(int $index): void
    {
        unset($this->hours[$index]);

        $this->hours = array_values($this->hours);
    }

    public function save(SaveBranch $save, SaveBranchSchedule $saveSchedule): void
    {
        $this->validate([
            'name' => ['required', 'array'],
            'timezone' => ['required', 'timezone'],
            'email' => ['nullable', 'email:rfc'],
            'mapUrl' => ['nullable', 'url'],
            'hours.*.day_of_week' => ['required', 'integer', 'between:0,6'],
            'hours.*.opens_at' => ['required', 'date_format:H:i'],
            'hours.*.closes_at' => ['required', 'date_format:H:i'],
        ]);

        $existing = $this->editing === null
            ? null
            : Branch::query()->where('uuid', $this->editing)->first();

        try {
            // ONE transaction around both Actions. The branch and its hours are
            // saved from a single form, so a rejected schedule — overlapping
            // intervals, a zero-length one — must not leave a branch behind
            // with no opening hours and an audit entry saying it was created.
            DB::connection('tenant')->transaction(function () use ($save, $saveSchedule, $existing): void {
                $branch = $save(BranchInput::fromArray([
                    'name' => $this->name,
                    'address' => $this->address,
                    'timezone' => $this->timezone,
                    'phone' => $this->phone,
                    'whatsapp' => $this->whatsapp,
                    'email' => $this->email,
                    'map_url' => $this->mapUrl,
                    'is_active' => $this->isActive,
                    'is_public' => $this->isPublic,
                ]), $this->actor(), $existing);

                $saveSchedule($branch, $this->hours, [], $this->actor());
            });
        } catch (AuthorizationException $e) {
            $this->notice = $e->getMessage();

            return;
        } catch (ValidationException $e) {
            // Overlapping or zero-length intervals are reported where the
            // person entering them can see them.
            $this->addError('hours', $e->getMessage());

            return;
        }

        $this->notice = __('Saved.');
        $this->resetForm();
    }

    public function archive(string $uuid, ArchiveBranch $archive): void
    {
        try {
            $archive(Branch::query()->where('uuid', $uuid)->firstOrFail(), $this->actor());

            $this->notice = __('Branch archived.');
        } catch (AuthorizationException|ValidationException $e) {
            $this->notice = $e->getMessage();
        }
    }

    public function render(): mixed
    {
        $user = $this->actor();

        return view('livewire.center.branches', [
            'branches' => Branch::query()->with('workingHours')
                ->orderBy('sort_order')->orderBy('id')->get(),
            'canManage' => $user->hasPermission(Permission::BranchManage),
            'locales' => $this->enabledLocales(),
            'days' => [
                0 => __('Sunday'), 1 => __('Monday'), 2 => __('Tuesday'),
                3 => __('Wednesday'), 4 => __('Thursday'), 5 => __('Friday'),
                6 => __('Saturday'),
            ],
        ]);
    }

    private function resetForm(): void
    {
        $this->editing = null;
        $this->name = [];
        $this->address = [];
        $this->timezone = 'Asia/Baghdad';
        $this->phone = '';
        $this->whatsapp = '';
        $this->email = '';
        $this->mapUrl = '';
        $this->isActive = true;
        $this->isPublic = true;
        $this->hours = [];
    }

    private function actor(): User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : abort(403);
    }
}
