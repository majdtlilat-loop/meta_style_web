<?php

declare(strict_types=1);

namespace App\Livewire\Center\Benefits;

use App\Kernel\Identity\Models\User;
use App\Modules\Loyalty\Application\LoyaltyPresenter;
use App\Modules\Loyalty\Application\LoyaltyQuery;
use App\Modules\Loyalty\Domain\Models\LoyaltyAccount;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Who holds points — on the loyalty page. Largest balance first, searchable by
 * NAME only (a member list is not a contact list; contact lives on the
 * customer's page, masked there as everywhere). Balances are the ledger's own
 * cache; nothing here repairs one (docs/21 §§1, 4).
 */
final class LoyaltyMembers extends Component
{
    use WithPagination;

    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render(LoyaltyQuery $query, LoyaltyPresenter $presenter): View
    {
        $user = auth('web')->user();

        if (! $user instanceof User) {
            abort(403);
        }

        try {
            $page = $query->members($user, $this->search, 15);
        } catch (AuthorizationException) {
            return view('livewire.center.customers.panel-denied');
        }

        $program = $query->currentProgram();

        /** @var list<LoyaltyAccount> $accounts */
        $accounts = $page->items();

        return view('livewire.center.benefits.loyalty-members', [
            'page' => $page,
            'members' => array_map(function (LoyaltyAccount $account) use ($presenter, $program): array {
                $row = $presenter->member($account, $program);

                return $row + [
                    'url' => $row['customer'] === null ? null : route('center.customers.show', ['uuid' => $row['customer']['uuid'], 'tab' => 'loyalty']),
                ];
            }, $accounts),
        ]);
    }
}
