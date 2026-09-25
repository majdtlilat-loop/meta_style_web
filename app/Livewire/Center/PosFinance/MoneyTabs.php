<?php

declare(strict_types=1);

namespace App\Livewire\Center\PosFinance;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * The money workspace's own tabs — sales, receipts, shifts, finance,
 * expenses — shown on each of those pages so they read as one place.
 *
 * Orientation only: a tab is listed when the viewer holds the permission its
 * page reads with, and every page and Action authorises again on the server.
 */
final class MoneyTabs
{
    /**
     * @return list<array{key: string, label: string, href: string, icon: string, active: bool}>
     */
    public static function for(User $user, string $active): array
    {
        $tabs = [
            ['sales', 'center.sales', 'sales', [Permission::SaleView]],
            ['receipts', 'center.payments', 'payments', [Permission::PaymentView]],
            ['shifts', 'center.shifts', 'clock', [Permission::CashierShiftManage, Permission::CashierShiftSupervise]],
            ['finance', 'center.finance', 'finance', [Permission::FinanceView]],
            ['expenses', 'center.expenses', 'expenses', [Permission::ExpenseManage]],
        ];

        $visible = [];

        foreach ($tabs as [$key, $route, $icon, $permissions]) {
            $allowed = false;

            foreach ($permissions as $permission) {
                $allowed = $allowed || $user->hasPermission($permission);
            }

            if (! $allowed || ! Route::has($route)) {
                continue;
            }

            $visible[] = [
                'key' => $key,
                'label' => (string) __('manager_finance.tabs.'.$key),
                'href' => route($route),
                'icon' => $icon,
                'active' => $key === $active,
            ];
        }

        // A single tab is not navigation.
        return count($visible) > 1 ? $visible : [];
    }
}
