<?php

declare(strict_types=1);

use App\Kernel\Authorization\Models\Role;
use App\Kernel\Authorization\Permission;
use App\Kernel\Authorization\SystemRole;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\TranslatedText;
use App\Modules\Branches\Application\Actions\ArchiveBranch;
use App\Modules\Branches\Application\Actions\SaveBranch;
use App\Modules\Branches\Application\Actions\SaveBranchSchedule;
use App\Modules\Branches\Domain\Data\BranchInput;
use App\Modules\Branches\Domain\Models\Branch;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| Branches and working hours
|--------------------------------------------------------------------------
|
| docs/13-ROADMAP.md Phase 4 §1.
|
| The working-hours model is the part worth testing hardest: split shifts and
| overnight opening are both normal in this market, and a schema or an action
| that quietly assumed one interval per day would be wrong for most centers.
|
*/

it('creates a branch with contact details and coordinates', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $branch = $this->asCenter($center['tenant'], fn (): Branch => app(SaveBranch::class)(
        BranchInput::fromArray([
            'name' => ['en' => 'Karrada Branch', 'ar' => 'فرع الكرادة'],
            'address' => ['en' => '52 Karrada St', 'ar' => 'شارع الكرادة ٥٢'],
            'timezone' => 'Asia/Baghdad',
            'phone' => '+9647700000001',
            'whatsapp' => '+9647700000002',
            'email' => 'karrada@alpha.test',
            'latitude' => '33.3152000',
            'longitude' => '44.3661000',
        ]),
        $owner,
    ));

    expect($branch->name->get('ar'))->toBe('فرع الكرادة')
        ->and($branch->name->get('en'))->toBe('Karrada Branch')
        ->and($branch->address?->get('ar'))->toBe('شارع الكرادة ٥٢')
        ->and($branch->whatsapp)->toBe('+9647700000002')
        // DECIMAL, not float: the value that went in is the value that comes
        // back, digit for digit.
        ->and((string) $branch->latitude)->toBe('33.3152000')
        ->and($branch->is_main)->toBeFalse();
});

it('never lets an ordinary edit promote a branch to main', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $result = $this->asCenter($center['tenant'], function () use ($owner): array {
        $main = Branch::main();

        $second = app(SaveBranch::class)(
            // Even if a caller smuggles it into the payload, `is_main` is not
            // read by the input object at all.
            BranchInput::fromArray(['name' => ['en' => 'Second'], 'is_main' => true]),
            $owner,
        );

        return ['main' => $main, 'second' => $second, 'mains' => Branch::query()->where('is_main', true)->count()];
    });

    expect($result['second']->is_main)->toBeFalse()
        ->and($result['mains'])->toBe(1)
        ->and($result['main']->id)->not->toBe($result['second']->id);
});

it('stores split shifts as separate intervals on the same day', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $hours = $this->asCenter($center['tenant'], function () use ($owner) {
        $branch = Branch::main();

        app(SaveBranchSchedule::class)($branch, [
            // 09:00–13:00, closed for the afternoon, 16:00–22:00. The normal
            // shape for a salon in this market.
            ['day_of_week' => 0, 'opens_at' => '09:00', 'closes_at' => '13:00'],
            ['day_of_week' => 0, 'opens_at' => '16:00', 'closes_at' => '22:00'],
        ], [], $owner);

        return $branch->workingHours()->where('day_of_week', 0)->get();
    });

    expect($hours)->toHaveCount(2)
        ->and($hours[0]->durationMinutes())->toBe(240)
        ->and($hours[1]->durationMinutes())->toBe(360)
        ->and($hours[0]->crossesMidnight())->toBeFalse();
});

it('understands an interval that runs past midnight', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $interval = $this->asCenter($center['tenant'], function () use ($owner) {
        $branch = Branch::main();

        app(SaveBranchSchedule::class)($branch, [
            ['day_of_week' => 4, 'opens_at' => '20:00', 'closes_at' => '02:00'],
        ], [], $owner);

        return $branch->workingHours()->firstOrFail();
    });

    // A barber open until 2am is one row, not two, and not an error.
    expect($interval->crossesMidnight())->toBeTrue()
        ->and($interval->opensAtMinutes())->toBe(1200)
        ->and($interval->closesAtMinutes())->toBe(1560)
        ->and($interval->durationMinutes())->toBe(360);
});

it('refuses an interval that opens and closes at the same time', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        expect(fn () => app(SaveBranchSchedule::class)(Branch::main(), [
            ['day_of_week' => 1, 'opens_at' => '09:00', 'closes_at' => '09:00'],
        ], [], $owner))->toThrow(ValidationException::class);
    });
});

it('refuses overlapping intervals on one day', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        // Two overlapping windows are not a split shift, they are a mistake —
        // and Booking would later have to pick one arbitrarily.
        expect(fn () => app(SaveBranchSchedule::class)(Branch::main(), [
            ['day_of_week' => 2, 'opens_at' => '09:00', 'closes_at' => '14:00'],
            ['day_of_week' => 2, 'opens_at' => '13:00', 'closes_at' => '18:00'],
        ], [], $owner))->toThrow(ValidationException::class, 'overlap');
    });
});

it('records date exceptions for closures and special hours', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $exceptions = $this->asCenter($center['tenant'], function () use ($owner) {
        $branch = Branch::main();

        app(SaveBranchSchedule::class)($branch, [
            ['day_of_week' => 0, 'opens_at' => '09:00', 'closes_at' => '17:00'],
        ], [
            ['date' => '2026-03-20', 'is_closed' => true, 'note' => 'Eid'],
            ['date' => '2026-03-21', 'is_closed' => false, 'opens_at' => '12:00', 'closes_at' => '20:00'],
        ], $owner);

        return $branch->hourExceptions()->orderBy('date')->get();
    });

    expect($exceptions)->toHaveCount(2)
        ->and($exceptions[0]->is_closed)->toBeTrue()
        ->and($exceptions[0]->note)->toBe('Eid')
        // A closed day has no times at all: keeping them would leave two
        // contradictory answers on one row.
        ->and($exceptions[0]->opens_at)->toBeNull()
        ->and($exceptions[1]->is_closed)->toBeFalse()
        ->and(mb_substr((string) $exceptions[1]->opens_at, 0, 5))->toBe('12:00');
});

it('replaces the whole schedule rather than merging into it', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $count = $this->asCenter($center['tenant'], function () use ($owner): int {
        $branch = Branch::main();
        $save = app(SaveBranchSchedule::class);

        $save($branch, [
            ['day_of_week' => 0, 'opens_at' => '09:00', 'closes_at' => '17:00'],
            ['day_of_week' => 1, 'opens_at' => '09:00', 'closes_at' => '17:00'],
        ], [], $owner);

        // A stale interval left behind would reopen the shop on a day the owner
        // just closed.
        $save($branch, [
            ['day_of_week' => 0, 'opens_at' => '10:00', 'closes_at' => '16:00'],
        ], [], $owner);

        return $branch->workingHours()->count();
    });

    expect($count)->toBe(1);
});

it('refuses branch management without the permission', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        /** @var User $staff */
        $staff = User::query()->create([
            'name' => 'Reception',
            'email' => 'host@alpha.test',
            'is_active' => true,
            'is_owner' => false,
            'all_branches' => true,
        ]);

        $host = Role::query()->where('key', SystemRole::Host->value)->firstOrFail();
        $staff->roles()->sync([$host->id]);
        $staff->forgetPermissionCache();

        expect($staff->hasPermission(Permission::BranchView))->toBeTrue()
            ->and($staff->hasPermission(Permission::BranchManage))->toBeFalse();

        expect(fn () => app(SaveBranch::class)(
            BranchInput::fromArray(['name' => ['en' => 'Nope']]),
            $staff,
        ))->toThrow(AuthorizationException::class);
    });
});

it('refuses to manage a branch outside the actor\'s scope', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $main = Branch::main();

        /** @var Branch $other */
        $other = Branch::query()->create([
            'name' => TranslatedText::make('en', 'Other'),
            'timezone' => 'Asia/Baghdad',
            'is_active' => true,
            'is_main' => false,
        ]);

        /** @var User $manager */
        $manager = User::query()->create([
            'name' => 'Branch Manager',
            'email' => 'manager@alpha.test',
            'is_active' => true,
            'is_owner' => false,
            // Scoped to the main branch only.
            'all_branches' => false,
        ]);

        $role = Role::query()->where('key', SystemRole::Manager->value)->firstOrFail();
        $manager->roles()->sync([$role->id]);
        $manager->syncBranchScope([$main->id]);
        $manager->forgetPermissionCache();

        // Permission alone is not authorization: the manager holds
        // branch.manage and still may not touch a branch they do not run.
        expect($manager->hasPermission(Permission::BranchManage))->toBeTrue();

        expect(fn () => app(SaveBranch::class)(
            BranchInput::fromArray(['name' => ['en' => 'Renamed']]),
            $manager,
            $other,
        ))->toThrow(AuthorizationException::class);

        // Nor open a new one: it would lie outside their scope, a branch they
        // could then neither see nor edit.
        expect(fn () => app(SaveBranch::class)(
            BranchInput::fromArray(['name' => ['en' => 'Unreachable']]),
            $manager,
        ))->toThrow(AuthorizationException::class)
            ->and(Branch::query()->where('name->en', 'Unreachable')->exists())->toBeFalse();
    });
});

it('archives a branch instead of deleting it, and never the main one', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $archive = app(ArchiveBranch::class);

        // The main branch is what provisioning created and what an
        // unscoped owner falls back to.
        expect(fn () => $archive(Branch::main(), $owner))
            ->toThrow(ValidationException::class, 'main branch cannot be archived');

        $second = $this->seedBranch();

        $archived = $archive($second, $owner);

        expect($archived->archived_at)->not->toBeNull()
            ->and($archived->is_active)->toBeFalse()
            ->and($archived->is_public)->toBeFalse()
            // The row survives, because employees reference it and bookings
            // will.
            ->and(Branch::query()->whereKey($second->id)->exists())->toBeTrue()
            ->and(Branch::query()->active()->whereKey($second->id)->exists())->toBeFalse();
    });
});

it('manages branches through the API with a real token', function (): void {
    $center = $this->registerCenter();
    $token = $this->apiTokenFor($center['tenant']);

    $created = $this->withHeaders($this->tokenHeaders($token))
        ->postJson('/api/v1/tenant/branches', [
            'name' => ['en' => 'Mansour Branch'],
            'timezone' => 'Asia/Baghdad',
            'phone' => '+9647700000009',
        ])
        ->assertStatus(201)
        ->json('data');

    expect($created['uuid'])->toBeString()
        ->and($created['is_main'])->toBeFalse();

    $this->withHeaders($this->tokenHeaders($token))
        ->putJson("/api/v1/tenant/branches/{$created['uuid']}/schedule", [
            'hours' => [
                ['day_of_week' => 0, 'opens_at' => '09:00', 'closes_at' => '13:00'],
                ['day_of_week' => 0, 'opens_at' => '16:00', 'closes_at' => '22:00'],
            ],
            'exceptions' => [],
        ])
        ->assertOk()
        ->assertJsonCount(2, 'data.hours');

    $this->withHeaders($this->tokenHeaders($token))
        ->getJson('/api/v1/tenant/branches')
        ->assertOk()
        ->assertJsonCount(2, 'data.branches');
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
