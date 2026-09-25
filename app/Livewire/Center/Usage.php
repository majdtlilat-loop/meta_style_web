<?php

declare(strict_types=1);

namespace App\Livewire\Center;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Usage\Usage as UsageMeter;
use App\Kernel\Usage\UsageSummary;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * What this center has used of its product allowances, this period.
 *
 * Functional only — three groups, their numbers, and when the period resets
 * (docs/26-USAGE-QUOTAS.md §14).
 *
 * ## `settings.view`, not a new permission
 *
 * Usage is a commercial read about the center as a whole, which is what
 * `settings.view` already means. A `usage.view` code would be a
 * micro-permission nothing else distinguishes, and every role that would hold
 * it already holds this one (Phase 13 §61).
 *
 * Deliberately NOT `ai.manage` or `whatsapp.manage`: a manager may read the
 * numbers without being trusted with the provider credentials.
 *
 * ## Separate commercial products, never one number
 *
 * Customer RAYAN, Advanced Report analysis and WhatsApp have distinct run or
 * volume identities even though the AI paths share token metering (§14).
 */
#[Layout('components.layouts.app')]
final class Usage extends Component
{
    public function render(UsageMeter $usage): View
    {
        $user = $this->user();

        if (! $user->hasPermission(Permission::SettingsView)) {
            return view('livewire.center.usage', ['allowed' => false, 'groups' => []]);
        }

        return view('livewire.center.usage', [
            'allowed' => true,
            'groups' => [
                'ai' => $this->group($usage, 'ai'),
                'advanced_reports' => $this->group($usage, 'advanced_reports'),
                'whatsapp' => $this->group($usage, 'whatsapp'),
            ],
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function group(UsageMeter $usage, string $group): array
    {
        return array_map(
            static fn (UsageSummary $summary): array => $summary->toArray(),
            $usage->summaries($group),
        );
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
