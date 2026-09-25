<?php

declare(strict_types=1);

use App\Kernel\Authorization\Models\Role;
use App\Kernel\Authorization\SystemRole;
use App\Kernel\Identity\Models\User;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Employees\Application\Actions\SaveAvailabilityBlock;
use App\Modules\Employees\Domain\Enums\AvailabilityBlockType;
use App\Modules\Employees\Domain\Models\EmployeeAvailabilityBlock;
use App\Modules\Resources\Application\Actions\SaveResource;
use App\Modules\Resources\Application\Actions\SaveResourceType;
use App\Modules\Resources\Domain\Data\ResourceInput;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| Resources and time off — the record's OWN branch is checked
|--------------------------------------------------------------------------
|
| The API compensated in its controllers; the Livewire screens called the
| Actions directly and skipped that. The Actions now check the branch a
| resource or block already stands in, not only the one it is moving to.
|
*/

function rsScopedManager(Branch $branch, string $email): User
{
    /** @var User $user */
    $user = User::query()->create(['name' => 'Scoped', 'email' => $email, 'is_active' => true, 'all_branches' => false]);
    $user->roles()->sync([Role::query()->where('key', SystemRole::Manager->value)->firstOrFail()->id]);
    $user->syncBranchScope([$branch->id]);
    $user->forgetPermissionCache();

    return $user;
}

it('refuses to edit, archive or pull in a resource that stands in another branch, and restores in scope', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $owner = User::query()->where('is_owner', true)->firstOrFail();
        $main = Branch::main();
        $second = $this->seedBranch('Karrada');
        $type = $this->seedResourceType('Chair');
        $theirs = $this->seedResource($type, $second, 'Karrada chair');
        $manager = rsScopedManager($main, 'rs-manager@x.test');

        $input = static fn (Branch $branch): ResourceInput => ResourceInput::fromArray([
            'resource_type' => $type->uuid, 'branch' => $branch->uuid, 'name' => ['en' => 'Moved'],
            'capacity' => 1, 'is_active' => true, 'sort_order' => 0,
        ]);

        // Moving another branch's chair INTO the manager's branch passes the
        // target check — the old code allowed exactly this.
        expect(fn () => app(SaveResource::class)($input($main), $manager, $theirs))->toThrow(AuthorizationException::class)
            ->and(fn () => app(SaveResource::class)->archive($theirs, $manager))->toThrow(AuthorizationException::class)
            ->and($theirs->refresh()->branch_id)->toBe($second->id)
            ->and($theirs->archived_at)->toBeNull();

        $mine = $this->seedResource($type, $main, 'Main chair');
        app(SaveResource::class)->archive($mine, $manager);
        app(SaveResource::class)->restore($mine->refresh(), $manager);

        expect($mine->refresh()->archived_at)->toBeNull()
            ->and($mine->is_active)->toBeTrue();

        // A resource of a retired type cannot come back.
        $old = $this->seedResourceType('Old machine');
        $orphan = $this->seedResource($old, $main, 'Old one');
        app(SaveResource::class)->archive($orphan, $owner);
        app(SaveResourceType::class)->archive($old, $owner);

        expect(fn () => app(SaveResource::class)->restore($orphan->refresh(), $owner))->toThrow(ValidationException::class);

        app(SaveResourceType::class)->restore($old->refresh(), $owner);
        expect($old->refresh()->isBookable())->toBeTrue();
    });
});

it('refuses to edit or delete a time-off block that stands in another branch', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $main = Branch::main();
        $second = $this->seedBranch('Karrada');
        $employee = $this->seedEmployee('Ahmed', $second);
        $employee->branches()->syncWithoutDetaching([$main->id]);
        $manager = rsScopedManager($main, 'rs-blocks@x.test');

        /** @var EmployeeAvailabilityBlock $block */
        $block = EmployeeAvailabilityBlock::query()->create([
            'employee_id' => $employee->id,
            'branch_id' => $second->id,
            'starts_at' => CarbonImmutable::now()->addDay()->utc(),
            'ends_at' => CarbonImmutable::now()->addDay()->addHour()->utc(),
            'type' => AvailabilityBlockType::Break,
        ]);

        $day = Carbon::now()->addDays(2)->format('Y-m-d');

        // Re-pointing another branch's block into the manager's branch.
        expect(fn () => app(SaveAvailabilityBlock::class)([
            'employee' => $employee->uuid, 'branch' => $main->uuid,
            'starts_at' => $day.' 10:00', 'ends_at' => $day.' 11:00', 'type' => 'break',
        ], $manager, $block))->toThrow(AuthorizationException::class)
            ->and(fn () => app(SaveAvailabilityBlock::class)->delete($block, $manager))->toThrow(AuthorizationException::class)
            ->and(EmployeeAvailabilityBlock::query()->whereKey($block->id)->value('branch_id'))->toBe($second->id);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
