<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Kernel\Authorization\Permission;
use App\Kernel\Http\ApiResponse;
use App\Kernel\Identity\Models\User;
use App\Kernel\Notes\Models\InternalNote;
use App\Kernel\Notes\NoteVisibility;
use App\Modules\Customers\Application\Actions\ArchiveCustomer;
use App\Modules\Customers\Application\Actions\ManageCustomerNotes;
use App\Modules\Customers\Application\Actions\SaveCustomer;
use App\Modules\Customers\Application\CustomerPresenter;
use App\Modules\Customers\Application\CustomerQuery;
use App\Modules\Customers\Domain\Data\CustomerInput;
use App\Modules\Customers\Domain\Enums\CustomerSource;
use App\Modules\Customers\Domain\Models\Customer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Staff CRM.
 *
 * Never gated on the `customer_accounts` entitlement. That entitlement controls
 * whether a center offers customers a LOGIN; keeping customer records is core
 * operational work every center does, and blocking it would make the product
 * unusable for anyone who has not bought the add-on
 * (docs/13-ROADMAP.md Phase 5 §26).
 *
 * Every response goes through {@see CustomerPresenter}, which masks contact
 * details for a viewer without `customer.contact.view`. The masking is not in
 * this class, because the Livewire screens need exactly the same behaviour and
 * two implementations would drift.
 */
final class CustomerController extends Controller
{
    public function index(Request $request, CustomerQuery $query, CustomerPresenter $presenter): JsonResponse
    {
        $user = $this->user($request);

        $this->require($user, Permission::CustomerView, 'You may not view customers.');

        $request->validate([
            'search' => ['nullable', 'string', 'max:190'],
            'archived' => ['nullable', 'boolean'],
            'registered' => ['nullable', 'boolean'],
            'tag' => ['nullable', 'string', 'max:64'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        // Read through `boolean()` rather than the validated array: the
        // `boolean` RULE accepts "1" and "true" but does not convert them, so a
        // strict comparison against a query-string value would always be false.
        $page = $query->paginate([
            'search' => $request->string('search')->toString() ?: null,
            'archived' => $request->boolean('archived'),
            'registered' => $request->has('registered') ? $request->boolean('registered') : null,
            'tag' => $request->string('tag')->toString() ?: null,
        ], $user, $request->integer('per_page', 25));

        return ApiResponse::data(
            ['customers' => array_map(
                fn (Customer $c): array => $presenter->summary($c, $user),
                $page->items(),
            )],
            meta: [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        );
    }

    public function show(string $uuid, Request $request, CustomerPresenter $presenter): JsonResponse
    {
        $user = $this->user($request);

        $this->require($user, Permission::CustomerView, 'You may not view customers.');

        $customer = $this->find($uuid);

        $customer->load(['tags', 'account', 'internalNotes']);

        return ApiResponse::data($presenter->detail($customer, $user));
    }

    public function store(Request $request, SaveCustomer $save, CustomerPresenter $presenter): JsonResponse
    {
        $user = $this->user($request);

        $data = $request->validate($this->rules());

        $customer = $save(CustomerInput::fromArray($data), $user);

        return ApiResponse::data($presenter->detail($customer->load(['tags', 'account']), $user), 201);
    }

    public function update(
        string $uuid,
        Request $request,
        SaveCustomer $save,
        CustomerPresenter $presenter,
    ): JsonResponse {
        $user = $this->user($request);

        $data = $request->validate($this->rules());

        $customer = $save(CustomerInput::fromArray($data), $user, $this->find($uuid));

        return ApiResponse::data($presenter->detail($customer->load(['tags', 'account']), $user));
    }

    public function archive(string $uuid, Request $request, ArchiveCustomer $archive): JsonResponse
    {
        $customer = $archive($this->find($uuid), $this->user($request));

        return ApiResponse::data(['uuid' => $customer->uuid, 'archived' => true]);
    }

    public function restore(string $uuid, Request $request, ArchiveCustomer $archive): JsonResponse
    {
        $customer = $archive->restore($this->find($uuid), $this->user($request));

        return ApiResponse::data(['uuid' => $customer->uuid, 'archived' => false]);
    }

    // ----------------------------------------------------------------- notes

    public function storeNote(string $uuid, Request $request, ManageCustomerNotes $notes): JsonResponse
    {
        $user = $this->user($request);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
            'visibility' => ['nullable', 'string', 'in:internal,manager_only'],
        ]);

        $note = $notes->add(
            $this->find($uuid),
            $data['body'],
            $user,
            NoteVisibility::from($data['visibility'] ?? 'internal'),
        );

        return ApiResponse::data([
            'uuid' => $note->uuid,
            'visibility' => $note->visibility->value,
        ], 201);
    }

    public function destroyNote(
        string $uuid,
        string $noteUuid,
        Request $request,
        ManageCustomerNotes $notes,
    ): JsonResponse {
        $customer = $this->find($uuid);

        /** @var InternalNote|null $note */
        $note = InternalNote::query()->where('uuid', $noteUuid)->first();

        $notes->delete($customer, $note ?? throw new ModelNotFoundException, $this->user($request));

        return ApiResponse::data(['uuid' => $noteUuid, 'deleted' => true]);
    }

    // ----------------------------------------------------------------- utils

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:190'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'preferred_locale' => ['nullable', 'string', 'max:12'],
            'date_of_birth' => ['nullable', 'date_format:Y-m-d'],
            'source' => ['nullable', 'string', 'in:'.implode(',', CustomerSource::values())],
            'allow_operational_messages' => ['boolean'],
            'marketing_opt_in' => ['boolean'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:64'],
        ];
    }

    private function find(string $uuid): Customer
    {
        /** @var Customer|null $customer */
        $customer = Customer::query()->where('uuid', $uuid)->first();

        // A customer in another tenant is indistinguishable from one that does
        // not exist — and unreachable anyway, since the query runs on the
        // tenant connection (docs/08-AUDIT-SECURITY.md §19).
        return $customer ?? throw new ModelNotFoundException;
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new AuthorizationException('Not authenticated.');
    }

    private function require(User $user, Permission $permission, string $message): void
    {
        if (! $user->hasPermission($permission)) {
            throw new AuthorizationException($message);
        }
    }
}
