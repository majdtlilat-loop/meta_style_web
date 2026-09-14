{{--
    The service catalog.

    Prices are typed in major units and converted through Money as a STRING —
    a float would lose a fils on "25.10" (docs/10-API-FOUNDATION.md §9). A blank
    variation price means "follow the service", not zero (ADR-037).
--}}
<div>
    <h1>{{ __('Services') }}</h1>
    <p class="sub">{{ __('Departments organise the business. Categories organise the menu. Services are what you sell.') }}</p>

    @if ($notice !== '')
        <p class="notice">{{ $notice }}</p>
    @endif

    <div class="card">
        <table class="list">
            <thead>
                <tr>
                    <th>{{ __('Service') }}</th>
                    <th>{{ __('Department') }}</th>
                    <th>{{ __('Duration') }}</th>
                    <th>{{ __('Price') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($services as $service)
                    <tr>
                        <td>
                            <strong>{{ $service->name?->get() }}</strong>
                            @unless ($service->is_public)
                                <span class="tag">{{ __('Not on menu') }}</span>
                            @endunless
                            @unless ($service->is_active)
                                <span class="tag">{{ __('Inactive') }}</span>
                            @endunless
                            @if ($service->variations->isNotEmpty())
                                <div class="sub">
                                    {{ $service->variations->count() }} {{ __('variation(s)') }}
                                </div>
                            @endif
                        </td>
                        <td>{{ $service->department?->name?->get() ?? '—' }}</td>
                        <td>{{ $service->duration_minutes }} {{ __('min') }}</td>
                        <td>{{ $service->price($currency)->formatted() }}</td>
                        <td class="actions">
                            @if ($canManageCatalog)
                                <button type="button" wire:click="editService('{{ $service->uuid }}')">{{ __('Edit') }}</button>
                            @endif
                            @if ($canArchive)
                                <button type="button" wire:click="archiveService('{{ $service->uuid }}')"
                                    wire:confirm="{{ __('Archive this service? It stays on past invoices.') }}">
                                    {{ __('Archive') }}
                                </button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="sub">{{ __('No services yet.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($canManageGroups)
        <h2>{{ __('Departments and categories') }}</h2>

        <div class="card">
            <p class="sub">
                {{ __('A department is operational — Hair, Laser, Hammam. A category is how the menu is grouped for customers. A service can have both.') }}
            </p>

            <div class="two-up">
                <div>
                    <h3>{{ __('Departments') }}</h3>
                    <ul>
                        @forelse ($departments as $department)
                            <li>{{ $department->name?->get() }}</li>
                        @empty
                            <li class="sub">{{ __('None yet.') }}</li>
                        @endforelse
                    </ul>
                </div>
                <div>
                    <h3>{{ __('Menu categories') }}</h3>
                    <ul>
                        @forelse ($categories as $category)
                            <li>
                                {{ $category->name?->get() }}
                                @unless ($category->is_public)
                                    <span class="tag">{{ __('Hidden') }}</span>
                                @endunless
                            </li>
                        @empty
                            <li class="sub">{{ __('None yet.') }}</li>
                        @endforelse
                    </ul>
                </div>
            </div>

            <form wire:submit="saveGroup">
                <label for="groupKind">{{ __('Add') }}</label>
                <select id="groupKind" wire:model="groupKind">
                    <option value="department">{{ __('Department') }}</option>
                    <option value="category">{{ __('Menu category') }}</option>
                </select>

                @foreach ($locales as $locale)
                    <label for="group-{{ $locale }}">{{ __('Name') }} ({{ $locale }})</label>
                    <input id="group-{{ $locale }}" type="text" wire:model="groupName.{{ $locale }}">
                @endforeach
                @error('groupName') <p class="error">{{ $message }}</p> @enderror

                <button type="submit" class="btn">{{ __('Add') }}</button>
            </form>
        </div>
    @endif

    @if ($canManageCatalog)
        <h2>{{ $editingService ? __('Edit service') : __('Add a service') }}</h2>

        <form wire:submit="saveService" class="card">
            @foreach ($locales as $locale)
                <label for="sname-{{ $locale }}">{{ __('Name') }} ({{ $locale }})</label>
                <input id="sname-{{ $locale }}" type="text" wire:model="serviceName.{{ $locale }}">
            @endforeach
            @error('serviceName') <p class="error">{{ $message }}</p> @enderror

            @foreach ($locales as $locale)
                <label for="sdesc-{{ $locale }}">{{ __('Short description') }} ({{ $locale }})</label>
                <input id="sdesc-{{ $locale }}" type="text" wire:model="serviceDescription.{{ $locale }}">
            @endforeach

            <div class="two-up">
                <div>
                    <label for="duration">{{ __('Duration (minutes)') }}</label>
                    <input id="duration" type="number" min="1" max="1440" wire:model="duration">
                    @error('duration') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="price">{{ __('Price') }} ({{ $currency->value }})</label>
                    <input id="price" type="text" inputmode="decimal" wire:model="price">
                    @error('price') <p class="error">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="two-up">
                <div>
                    <label for="departmentUuid">{{ __('Department') }}</label>
                    <select id="departmentUuid" wire:model="departmentUuid">
                        <option value="">{{ __('None') }}</option>
                        @foreach ($departments as $department)
                            <option value="{{ $department->uuid }}">{{ $department->name?->get() }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="categoryUuid">{{ __('Menu category') }}</label>
                    <select id="categoryUuid" wire:model="categoryUuid">
                        <option value="">{{ __('None') }}</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->uuid }}">{{ $category->name?->get() }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <label class="inline">
                <input type="checkbox" wire:model="serviceActive"> {{ __('Active') }}
            </label>
            <label class="inline">
                <input type="checkbox" wire:model="servicePublic"> {{ __('Show on the public menu') }}
            </label>
            <label class="inline">
                <input type="checkbox" wire:model.live="allBranches"> {{ __('Available at every branch') }}
            </label>

            @unless ($allBranches)
                <label>{{ __('Available at') }}</label>
                @foreach ($branches as $branch)
                    <label class="inline">
                        <input type="checkbox" value="{{ $branch->uuid }}" wire:model="branchUuids">
                        {{ $branch->name?->get() }}
                    </label>
                @endforeach
            @endunless

            <h3>{{ __('Who may perform this') }}</h3>
            <p class="sub">{{ __('Eligibility only. Schedules and availability come with booking.') }}</p>
            @forelse ($employees as $employee)
                <label class="inline">
                    <input type="checkbox" value="{{ $employee->uuid }}" wire:model="employeeUuids">
                    {{ $employee->name?->get() }}
                </label>
            @empty
                <p class="sub">{{ __('No employees yet.') }}</p>
            @endforelse

            <h3>{{ __('Variations') }}</h3>
            <p class="sub">
                {{ __('Leave a price blank to follow the service price. It will keep following it when the service price changes.') }}
            </p>

            @foreach ($variations as $index => $variation)
                <div class="row">
                    @foreach ($locales as $locale)
                        <input type="text" wire:model="variations.{{ $index }}.name.{{ $locale }}"
                            placeholder="{{ __('Name') }} ({{ $locale }})"
                            aria-label="{{ __('Variation name') }} ({{ $locale }})">
                    @endforeach
                    <input type="text" inputmode="decimal" wire:model="variations.{{ $index }}.price"
                        placeholder="{{ __('Price') }}" aria-label="{{ __('Variation price') }}">
                    <input type="number" wire:model="variations.{{ $index }}.duration"
                        placeholder="{{ __('Minutes') }}" aria-label="{{ __('Variation duration') }}">
                    <button type="button" wire:click="removeVariation({{ $index }})">{{ __('Remove') }}</button>
                </div>
            @endforeach

            <p>
                <button type="button" wire:click="addVariation">{{ __('Add a variation') }}</button>
            </p>

            <button type="submit" class="btn">{{ __('Save service') }}</button>
        </form>
    @endif
</div>
