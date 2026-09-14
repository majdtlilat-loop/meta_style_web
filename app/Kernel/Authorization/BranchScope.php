<?php

declare(strict_types=1);

namespace App\Kernel\Authorization;

/**
 * Which branches a user may act in.
 *
 * Deliberately expressed as "unrestricted" or "these ids" rather than as a
 * list of Branch models. Branch scope is an authorization concept and lives in
 * the Kernel; Branch is a business record and lives in a module. Keeping the
 * Kernel free of module imports is what makes the layering in
 * docs/04-MODULE-BOUNDARIES.md §2 hold — and it means "all branches" does not
 * have to enumerate rows that do not exist yet.
 */
final readonly class BranchScope
{
    /**
     * @param  list<int>|null  $branchIds  null means unrestricted
     */
    private function __construct(public ?array $branchIds) {}

    /**
     * Every branch, including any created later.
     */
    public static function all(): self
    {
        return new self(null);
    }

    /**
     * @param  list<int>  $branchIds
     */
    public static function limitedTo(array $branchIds): self
    {
        return new self(array_values(array_unique($branchIds)));
    }

    public static function none(): self
    {
        return new self([]);
    }

    public function isUnrestricted(): bool
    {
        return $this->branchIds === null;
    }

    public function allows(int $branchId): bool
    {
        return $this->branchIds === null || in_array($branchId, $this->branchIds, true);
    }

    /**
     * Applies the scope to a query builder.
     *
     * @template TQuery of \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public function applyTo(mixed $query, string $column = 'branch_id'): mixed
    {
        if ($this->branchIds === null) {
            return $query;
        }

        $query->whereIn($column, $this->branchIds);

        return $query;
    }
}
