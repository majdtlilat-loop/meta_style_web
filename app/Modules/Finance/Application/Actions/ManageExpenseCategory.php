<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application\Actions;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\TranslatedText;
use App\Modules\Finance\Application\FinanceAccess;
use App\Modules\Finance\Application\FinanceAudit;
use App\Modules\Finance\Domain\Exceptions\FinanceFailed;
use App\Modules\Finance\Domain\Models\ExpenseCategory;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Creates, renames and archives the center's expense categories.
 *
 * Center-wide (a category is the center's vocabulary, not a branch's), so the
 * branch scope is not narrowed. Archived, never deleted: posted expenses keep
 * their category (docs/20-FINANCE.md §38).
 */
final class ManageExpenseCategory
{
    public function __construct(
        private readonly FinanceAccess $access,
        private readonly FinanceAudit $audit,
    ) {}

    /**
     * @param  array<string, string|null>  $name  locale => text
     *
     * @throws FinanceFailed
     * @throws AuthorizationException
     */
    public function save(array $name, User $actingUser, ?ExpenseCategory $category = null, int $sortOrder = 0): ExpenseCategory
    {
        $this->access->ensure($actingUser, Permission::ExpenseManage, 0, 'You may not manage expense categories.');

        $clean = [];

        foreach ($name as $locale => $text) {
            $text = is_string($text) ? trim($text) : '';

            if ($text !== '') {
                if (mb_strlen($text) > 80) {
                    throw FinanceFailed::policy('A category name is at most 80 characters.');
                }

                $clean[(string) $locale] = $text;
            }
        }

        if ($clean === []) {
            throw FinanceFailed::policy('A category needs a name.');
        }

        /** @var ExpenseCategory $saved */
        $saved = DB::connection('tenant')->transaction(function () use ($clean, $actingUser, $category, $sortOrder): ExpenseCategory {
            $target = $category ?? new ExpenseCategory;
            $before = $category?->name->all();

            $target->forceFill([
                'name' => TranslatedText::fromArray($clean),
                'sort_order' => max(0, min(65535, $sortOrder)),
            ])->save();

            $this->audit->record($category === null ? 'finance.expense_category.created' : 'finance.expense_category.updated', $actingUser, $target, $target->uuid,
                after: ['name' => $clean],
                before: $before === null ? null : ['name' => $before],
            );

            return $target;
        });

        return $saved;
    }

    /**
     * Brings an archived category back into use. Its past expenses never left
     * it; new ones can be posted to it again.
     *
     * @throws AuthorizationException
     */
    public function unarchive(ExpenseCategory $category, User $actingUser): ExpenseCategory
    {
        $this->access->ensure($actingUser, Permission::ExpenseManage, 0, 'You may not manage expense categories.');

        if ($category->archived_at === null) {
            return $category;
        }

        /** @var ExpenseCategory $restored */
        $restored = DB::connection('tenant')->transaction(function () use ($category, $actingUser): ExpenseCategory {
            $category->forceFill(['archived_at' => null])->save();

            $this->audit->record('finance.expense_category.restored', $actingUser, $category, $category->uuid);

            return $category;
        });

        return $restored;
    }

    /**
     * @throws AuthorizationException
     */
    public function archive(ExpenseCategory $category, User $actingUser, ?CarbonImmutable $now = null): ExpenseCategory
    {
        $this->access->ensure($actingUser, Permission::ExpenseManage, 0, 'You may not manage expense categories.');

        if ($category->archived_at !== null) {
            return $category;
        }

        /** @var ExpenseCategory $archived */
        $archived = DB::connection('tenant')->transaction(function () use ($category, $actingUser, $now): ExpenseCategory {
            $category->forceFill(['archived_at' => ($now ?? CarbonImmutable::now())->utc()])->save();

            $this->audit->record('finance.expense_category.archived', $actingUser, $category, $category->uuid);

            return $category;
        });

        return $archived;
    }
}
