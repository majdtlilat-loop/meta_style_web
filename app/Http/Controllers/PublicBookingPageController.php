<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Kernel\Http\Idempotency;
use App\Kernel\Localization\LanguageRegistry;
use App\Kernel\Money\Currency;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Kernel\Tenancy\Http\Middleware\ResolvePublicTenant;
use App\Modules\Booking\Contracts\BookingEngine;
use App\Modules\Booking\Domain\Availability\AvailabilityEngine;
use App\Modules\Booking\Domain\Data\AvailabilityQuery;
use App\Modules\Booking\Domain\Data\AvailabilitySlot;
use App\Modules\Booking\Domain\Data\BookingActor;
use App\Modules\Booking\Domain\Data\BookingLine;
use App\Modules\Booking\Domain\Data\BookingRequest;
use App\Modules\Booking\Domain\Data\CustomerRef;
use App\Modules\Booking\Domain\Exceptions\BookingFailed;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Catalog\Domain\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The guest booking flow, reached from the electronic menu.
 *
 * ## Plain server-rendered forms, not Livewire
 *
 * The menu itself is deliberately a static page: a customer opens it from a QR
 * code on a table, often on a slow connection, and it should render without a
 * JavaScript payload. The booking flow keeps that promise — three GETs and one
 * POST, each a real URL a customer can go back to, all of it working with
 * JavaScript off (docs/13-ROADMAP.md Phase 6 §§23, 26).
 *
 * It also keeps the public surface out of Livewire's persistent-middleware
 * machinery entirely, which matters here: `ResolvePublicTenant` reads a center
 * key from the URL path, and the fewer places that middleware can be reached
 * from, the smaller the thing ADR-036 has to keep true.
 *
 * ## The entitlement, and what a center without it sees
 *
 * Booking requires the `booking` entitlement. A center that does not own it
 * keeps its MENU — that is marketing, and switching it off would punish the
 * center's customers for a billing decision — but the Book action is not
 * rendered and the endpoints refuse. The policy is documented rather than
 * implied (§31).
 *
 * ## Idempotency without an API client
 *
 * A browser has no `Idempotency-Key` header. The confirm page embeds one in a
 * hidden field, so a double submit or a refresh replays instead of booking
 * twice — the same {@see Idempotency} service the API middleware uses, called
 * directly. One implementation, two entry points (§29).
 */
final class PublicBookingPageController extends Controller
{
    public function show(
        Request $request,
        AvailabilityEngine $availability,
        Entitlements $entitlements,
        TenantContext $tenants,
        LanguageRegistry $languages,
    ): View {
        $this->assertBookable($entitlements);

        $branches = Branch::query()->publiclyVisible()->get();

        if ($branches->isEmpty()) {
            throw new NotFoundHttpException;
        }

        $branch = $this->chosenBranch($branches, $request->query('branch'));

        $services = Service::query()
            ->publiclyVisible()
            ->where('is_online_bookable', true)
            ->atBranch((int) $branch->getKey())
            ->with(['variations' => fn ($q) => $q->where('is_active', true)])
            ->get();

        $service = $this->chosenService($services, $request->query('service'));

        $date = $this->chosenDate($request->query('date'), $branch->timezone);

        $slots = [];
        $error = null;

        if ($service instanceof Service) {
            try {
                $slots = array_map(
                    static fn (AvailabilitySlot $slot): array => [
                        'starts_at' => $slot->startsAt->toIso8601String(),
                        'time' => $slot->localTime,
                    ],
                    $availability->slots(
                        new AvailabilityQuery(
                            branchUuid: $branch->uuid,
                            lines: [$this->line($request, $service)],
                            fromDate: $date,
                            toDate: $date,
                        ),
                        publicChannel: true,
                    ),
                );
            } catch (BookingFailed $e) {
                $error = $e->getMessage();
            }
        }

        $locale = app()->getLocale();

        return view('menu.book', [
            'center' => $tenants->require(),
            'centerKey' => (string) $request->route(ResolvePublicTenant::PARAMETER),
            'branches' => $branches,
            'branch' => $branch,
            'services' => $services,
            'service' => $service,
            'variationUuid' => (string) $request->query('variation', ''),
            'employeeUuid' => (string) $request->query('employee', ''),
            'employees' => $service instanceof Service ? $this->employees($service, $branch) : [],
            'date' => $date,
            'slots' => $slots,
            'startsAt' => (string) $request->query('at', ''),
            'currency' => Currency::default(),
            'error' => $error,
            'locale' => $locale,
            'direction' => $languages->direction($locale),
            // Regenerated per render, so a back-button resubmit reuses the key
            // it was shown with and replays rather than double-booking.
            'idempotencyKey' => (string) Str::uuid(),
            'confirmation' => session('booking.confirmation'),
        ]);
    }

    /**
     * Creates the booking.
     *
     * @return RedirectResponse
     */
    public function store(
        Request $request,
        BookingEngine $engine,
        Entitlements $entitlements,
        Idempotency $idempotency,
    ): mixed {
        $this->assertBookable($entitlements);

        $data = $request->validate([
            'branch' => ['required', 'string', 'max:64'],
            'service' => ['required', 'string', 'max:64'],
            'variation' => ['nullable', 'string', 'max:64'],
            'employee' => ['nullable', 'string', 'max:64'],
            'addons' => ['nullable', 'array', 'max:10'],
            'addons.*' => ['string', 'max:64'],
            'starts_at' => ['required', 'date'],
            'name' => ['required', 'string', 'max:190'],
            'phone' => ['required', 'string', 'max:32'],
            'note' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['required', 'string', 'max:190'],
        ]);

        $centerKey = (string) $request->route(ResolvePublicTenant::PARAMETER);

        try {
            /*
             * The same service the API middleware uses. A refresh or a
             * double-tap replays the stored answer instead of creating a second
             * appointment (§29).
             */
            $result = $idempotency->run(
                (string) $data['idempotency_key'],
                'menu.book',
                // The name and phone are part of the request and therefore part
                // of the hash. They never reach the stored RESPONSE below.
                $data,
                function () use ($data, $engine): JsonResponse {
                    $appointment = $engine->book(
                        new BookingRequest(
                            branchUuid: (string) $data['branch'],
                            lines: [new BookingLine(
                                serviceUuid: (string) $data['service'],
                                variationUuid: $this->nullable($data['variation'] ?? null),
                                addonUuids: array_values($data['addons'] ?? []),
                                employeeUuid: $this->nullable($data['employee'] ?? null),
                            )],
                            startsAt: CarbonImmutable::parse((string) $data['starts_at'])->utc(),
                            customer: CustomerRef::details(
                                (string) $data['name'],
                                (string) $data['phone'],
                                null,
                                app()->getLocale(),
                            ),
                            customerNote: $this->nullable($data['note'] ?? null),
                        ),
                        // Always a guest. Nothing in the form can change this.
                        BookingActor::guest(),
                    );

                    return new JsonResponse([
                        'uuid' => $appointment->uuid,
                        'date' => $appointment->localDate(),
                        'time' => $appointment->localStart()->format('H:i'),
                    ]);
                },
            );
        } catch (BookingFailed $e) {
            return redirect()
                ->route('menu.book', ['center' => $centerKey] + $request->only(['branch', 'service', 'date']))
                ->withInput()
                ->with('booking.error', $e->getMessage());
        }

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $result->getContent(), true) ?: [];

        return redirect()
            ->route('menu.book', ['center' => $centerKey])
            ->with('booking.confirmation', $body);
    }

    /**
     * @throws NotFoundHttpException
     */
    private function assertBookable(Entitlements $entitlements): void
    {
        try {
            $entitlements->ensure('booking');
        } catch (EntitlementRequired) {
            // 404, not 403. A guest has no billing relationship with the center
            // and telling them which features it has not bought is neither
            // useful nor the center's wish (§31).
            throw new NotFoundHttpException;
        }
    }

    /**
     * @param  Collection<int, Branch>  $branches
     */
    private function chosenBranch(Collection $branches, mixed $uuid): Branch
    {
        if (is_string($uuid) && $uuid !== '') {
            $branch = $branches->firstWhere('uuid', $uuid);

            if ($branch instanceof Branch) {
                return $branch;
            }

            // A branch that is not publicly visible is "not found", never a
            // silent substitution — that would show a customer the wrong
            // address.
            throw new NotFoundHttpException;
        }

        /** @var Branch $first */
        $first = $branches->first();

        return $first;
    }

    /**
     * @param  Collection<int, Service>  $services
     */
    private function chosenService(Collection $services, mixed $uuid): ?Service
    {
        if (! is_string($uuid) || $uuid === '') {
            return null;
        }

        $service = $services->firstWhere('uuid', $uuid);

        return $service instanceof Service ? $service : null;
    }

    private function chosenDate(mixed $date, string $timezone): string
    {
        if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
            return $date;
        }

        // Today AT THE BRANCH, not on the server. A customer in Baghdad at
        // 01:00 is looking at a different day from a server in UTC.
        return CarbonImmutable::now()->setTimezone($timezone)->format('Y-m-d');
    }

    private function line(Request $request, Service $service): BookingLine
    {
        /** @var list<string> $addons */
        $addons = array_values(array_filter((array) $request->query('addons', []), 'is_string'));

        return new BookingLine(
            serviceUuid: $service->uuid,
            variationUuid: $this->nullable($request->query('variation')),
            addonUuids: $addons,
            employeeUuid: $this->nullable($request->query('employee')),
        );
    }

    /**
     * Employees a customer may pick, by name only.
     *
     * No status, no branch list, no contact details — the same allow-list
     * discipline the menu uses (docs/08-AUDIT-SECURITY.md §19).
     *
     * @return list<array{uuid: string, name: string}>
     */
    private function employees(Service $service, Branch $branch): array
    {
        return $service->eligibleEmployees()
            ->where('employees.status', 'active')
            ->whereHas('branches', fn ($q) => $q->whereKey($branch->getKey()))
            ->orderBy('employees.id')
            ->get()
            ->map(static fn ($employee): array => [
                'uuid' => (string) $employee->uuid,
                'name' => (string) $employee->name->get(),
            ])
            ->values()
            ->all();
    }

    private function nullable(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
