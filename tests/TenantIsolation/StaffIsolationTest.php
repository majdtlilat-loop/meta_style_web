<?php

declare(strict_types=1);

use App\Kernel\Authorization\Actions\CreateRole;
use App\Kernel\Authorization\Models\Role;
use App\Kernel\Identity\Actions\ManageStaffActivation;
use App\Kernel\Identity\Exceptions\InvalidActivationToken;
use App\Kernel\Identity\Models\User;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Catalog\Application\Actions\SetEmployeeServices;
use App\Modules\Employees\Application\Actions\CreateEmployee;
use App\Modules\Employees\Application\EmployeeQuery;
use App\Modules\Employees\Domain\Data\NewEmployee;
use App\Modules\Employees\Domain\Models\Employee;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Staff, roles and activation — isolation
|--------------------------------------------------------------------------
|
| CLAUDE.md: anything touching tenant data needs a case here. Two centers
| whose staff, roles and tokens share ids and even names: every read resolves
| inside its own center, and a credential issued by one is nothing in another.
|
*/

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});

it('keeps two centers\' staff, custom roles and service eligibility apart, even with colliding ids', function (): void {
    $alpha = $this->registerCenter('Barbershop Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Salon Beta', 'owner@beta.test');

    $seeded = [];

    foreach (['Alpha' => $alpha, 'Beta' => $beta] as $label => $center) {
        $seeded[$label] = $this->asCenter($center['tenant'], function () use ($label): array {
            $owner = User::query()->where('is_owner', true)->firstOrFail();
            $service = $this->seedService($label.' cut', 30, 10000);

            $employee = app(CreateEmployee::class)(new NewEmployee(
                name: ['en' => $label.' stylist'],
                branchIds: [Branch::main()->id],
                phone: $label === 'Alpha' ? '+9647701230401' : '+9647701230402',
            ), $owner)['employee'];

            app(SetEmployeeServices::class)($employee, [$service->uuid], $owner);
            $role = app(CreateRole::class)(['en' => 'Front desk'], [], $owner);

            return ['employee' => $employee->id, 'employee_uuid' => $employee->uuid, 'role' => $role->id];
        });
    }

    // Same auto-increment ids on both sides: the shape a leaky query breaks on.
    expect($seeded['Alpha']['employee'])->toBe($seeded['Beta']['employee'])
        ->and($seeded['Alpha']['role'])->toBe($seeded['Beta']['role']);

    foreach (['Alpha' => $alpha, 'Beta' => $beta] as $label => $center) {
        $other = $label === 'Alpha' ? 'Beta' : 'Alpha';

        $this->asCenter($center['tenant'], function () use ($label, $other, $seeded): void {
            $owner = User::query()->where('is_owner', true)->firstOrFail();
            $query = app(EmployeeQuery::class);

            $names = collect($query->paginate([], $owner)->items())->map(static fn (Employee $e): string => $e->name->get('en'))->all();

            expect($names)->toBe([$label.' stylist'])
                // The other center's uuid is not found here, whatever its id.
                ->and($query->find($seeded[$other]['employee_uuid'], $owner))->toBeNull()
                ->and($query->paginate(['search' => $other], $owner)->total())->toBe(0)
                ->and(Role::query()->where('name->en', 'Front desk')->count())->toBe(1)
                ->and(DB::connection('tenant')->table('services')->where('name->en', $other.' cut')->exists())->toBeFalse()
                ->and(DB::connection('tenant')->table('employee_service')->count())->toBe(1);
        });
    }
});

it('never redeems one center\'s activation link in another center', function (): void {
    $alpha = $this->registerCenter('Barbershop Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Salon Beta', 'owner@beta.test');

    $token = $this->asCenter($alpha['tenant'], function (): string {
        $owner = User::query()->where('is_owner', true)->firstOrFail();

        return (string) app(CreateEmployee::class)(new NewEmployee(name: ['en' => 'Sara'], phone: '+9647701230411'), $owner)['activation_token'];
    });

    $this->asCenter($beta['tenant'], function () use ($token): void {
        // The same position in Beta's sequence exists too — only the hash
        // lookup inside Beta's own database decides, and it finds nothing.
        app(CreateEmployee::class)(new NewEmployee(name: ['en' => 'Sara'], phone: '+9647701230412'), User::query()->where('is_owner', true)->firstOrFail());

        expect(app(ManageStaffActivation::class)->pending($token))->toBeNull()
            ->and(fn () => app(ManageStaffActivation::class)->redeem($token, 'a-brand-new-password-42'))->toThrow(InvalidActivationToken::class);
    });

    $this->asCenter($alpha['tenant'], function () use ($token): void {
        expect(app(ManageStaffActivation::class)->redeem($token, 'a-brand-new-password-42')->canAuthenticate())->toBeTrue();
    });
});
