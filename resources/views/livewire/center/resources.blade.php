{{--
    Resource management: types, physical resources, what each service needs,
    and when people are unavailable.

    Functional only. Logical CSS properties throughout, so the same markup
    works in Arabic and Kurdish right-to-left without a second stylesheet
    (docs/07-LOCALIZATION.md).
--}}
<div>
    <h1>{{ __('Resources') }}</h1>
    <p class="sub">{{ __('Chairs, rooms and devices — and the times your team is not available.') }}</p>

    @if ($error)
        <p class="error" role="alert">{{ $error }}</p>
    @endif

    @if ($saved)
        <p class="notice" role="status">{{ $saved }}</p>
    @endif

    <nav class="tabs">
        <button type="button" wire:click="$set('tab', 'resources')" @class(['btn', 'is-active' => $tab === 'resources'])>
            {{ __('Resources') }}
        </button>
        <button type="button" wire:click="$set('tab', 'types')" @class(['btn', 'is-active' => $tab === 'types'])>
            {{ __('Types') }}
        </button>
        <button type="button" wire:click="$set('tab', 'requirements')" @class(['btn', 'is-active' => $tab === 'requirements'])>
            {{ __('Service requirements') }}
        </button>
        <button type="button" wire:click="$set('tab', 'blocks')" @class(['btn', 'is-active' => $tab === 'blocks'])>
            {{ __('Availability blocks') }}
        </button>
    </nav>

    {{-- ------------------------------------------------------------ types --}}
    @if ($tab === 'types')
        <div class="card">
            <h2>{{ __('Resource types') }}</h2>
            <p class="sub">
                {{ __('A classification, not a thing: "Laser Machine", "Treatment Room". Services are defined against these.') }}
            </p>

            @if ($canManage)
                <form wire:submit="saveType" class="row">
                    @foreach ($locales as $locale)
                        <div class="field">
                            <label for="type-{{ $locale }}">{{ __('Name') }} ({{ $locale }})</label>
                            <input id="type-{{ $locale }}" type="text" wire:model="typeName.{{ $locale }}">
                        </div>
                    @endforeach
                    <button type="submit" class="btn">{{ __('Add type') }}</button>
                </form>
            @endif

            <table>
                <thead>
                <tr>
                    <th>{{ __('Name') }}</th>
                    <th>{{ __('Status') }}</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($types as $type)
                    <tr>
                        <td>{{ $type->name->get() }}</td>
                        <td>{{ $type->isArchived() ? __('Archived') : ($type->is_active ? __('Active') : __('Inactive')) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="2">{{ __('No resource types yet.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    @endif

    {{-- -------------------------------------------------------- resources --}}
    @if ($tab === 'resources')
        <div class="card">
            <h2>{{ $resourceUuid === '' ? __('Add a resource') : __('Edit resource') }}</h2>

            @if ($canManage)
                <form wire:submit="saveResource">
                    @foreach ($locales as $locale)
                        <div class="field">
                            <label for="res-{{ $locale }}">{{ __('Name') }} ({{ $locale }})</label>
                            <input id="res-{{ $locale }}" type="text" wire:model="resourceName.{{ $locale }}">
                        </div>
                    @endforeach

                    <div class="field">
                        <label for="res-type">{{ __('Type') }}</label>
                        <select id="res-type" wire:model="resourceType">
                            <option value="">{{ __('Choose…') }}</option>
                            @foreach ($types as $type)
                                <option value="{{ $type->uuid }}">{{ $type->name->get() }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="field">
                        <label for="res-branch">{{ __('Branch') }}</label>
                        <select id="res-branch" wire:model="resourceBranch">
                            <option value="">{{ __('Choose…') }}</option>
                            @foreach ($branches as $branch)
                                <option value="{{ $branch->uuid }}">{{ $branch->name->get() }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="field">
                        <label for="res-dept">{{ __('Department') }}</label>
                        <select id="res-dept" wire:model="resourceDepartment">
                            <option value="">{{ __('None') }}</option>
                            @foreach ($departments as $department)
                                <option value="{{ $department->uuid }}">{{ $department->name->get() }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="field">
                        <label for="res-capacity">{{ __('Capacity') }}</label>
                        <input id="res-capacity" type="number" min="1" max="500" wire:model="capacity">
                        <p class="sub">
                            {{ __('How many people can use it at once. A private room is 1; a shared hammam might be 4.') }}
                        </p>
                    </div>

                    <button type="submit" class="btn">{{ __('Save resource') }}</button>
                </form>
            @endif

            <table>
                <thead>
                <tr>
                    <th>{{ __('Name') }}</th>
                    <th>{{ __('Type') }}</th>
                    <th>{{ __('Branch') }}</th>
                    <th>{{ __('Department') }}</th>
                    <th>{{ __('Capacity') }}</th>
                    <th>{{ __('Status') }}</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @forelse ($resources as $resource)
                    <tr>
                        <td>{{ $resource->name->get() }}</td>
                        <td>{{ $resource->type?->name?->get() }}</td>
                        <td>{{ $resource->branch?->name?->get() }}</td>
                        <td>{{ $resource->department?->name?->get() ?? '—' }}</td>
                        <td>{{ $resource->capacity }}</td>
                        <td>{{ $resource->isArchived() ? __('Archived') : ($resource->is_active ? __('Active') : __('Inactive')) }}</td>
                        <td>
                            @if ($canManage && ! $resource->isArchived())
                                <button type="button" class="btn" wire:click="editResource('{{ $resource->uuid }}')">
                                    {{ __('Edit') }}
                                </button>
                                <button type="button" class="btn" wire:click="archiveResource('{{ $resource->uuid }}')">
                                    {{ __('Archive') }}
                                </button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7">{{ __('No resources yet.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    @endif

    {{-- ----------------------------------------------------- requirements --}}
    @if ($tab === 'requirements')
        <div class="card">
            <h2>{{ __('What a service needs') }}</h2>
            <p class="sub">
                {{ __('Defined against a TYPE, never one particular machine — booking picks a free one. Changing this never alters bookings already made.') }}
            </p>

            <div class="field">
                <label for="req-service">{{ __('Service') }}</label>
                <select id="req-service" wire:model="requirementService" wire:change="loadRequirements">
                    <option value="">{{ __('Choose…') }}</option>
                    @foreach ($services as $service)
                        <option value="{{ $service->uuid }}">{{ $service->name->get() }}</option>
                    @endforeach
                </select>
            </div>

            @if ($requirementService !== '')
                <form wire:submit="saveRequirements">
                    @foreach ($requirements as $index => $requirement)
                        <div class="row">
                            <select wire:model="requirements.{{ $index }}.type">
                                <option value="">{{ __('Choose a type…') }}</option>
                                @foreach ($types as $type)
                                    <option value="{{ $type->uuid }}">{{ $type->name->get() }}</option>
                                @endforeach
                            </select>
                            <input type="number" min="1" max="255" wire:model="requirements.{{ $index }}.quantity">
                            <button type="button" class="btn" wire:click="removeRequirement({{ $index }})">
                                {{ __('Remove') }}
                            </button>
                        </div>
                    @endforeach

                    @if ($canManage)
                        <button type="button" class="btn" wire:click="addRequirement">{{ __('Add requirement') }}</button>
                        <button type="submit" class="btn">{{ __('Save requirements') }}</button>
                    @endif
                </form>
            @endif
        </div>
    @endif

    {{-- ----------------------------------------------------------- blocks --}}
    @if ($tab === 'blocks')
        <div class="card">
            <h2>{{ __('Availability blocks') }}</h2>
            <p class="sub">
                {{ __('Breaks, training, personal time. This stops NEW bookings; it never cancels or moves an appointment already made.') }}
            </p>

            @if ($canBlock)
                <form wire:submit="saveBlock">
                    <div class="field">
                        <label for="blk-employee">{{ __('Team member') }}</label>
                        <select id="blk-employee" wire:model="blockEmployee">
                            <option value="">{{ __('Choose…') }}</option>
                            @foreach ($employees as $employee)
                                <option value="{{ $employee->uuid }}">{{ $employee->name->get() }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="field">
                        <label for="blk-branch">{{ __('Branch') }}</label>
                        <select id="blk-branch" wire:model="blockBranch">
                            <option value="">{{ __('Choose…') }}</option>
                            @foreach ($branches as $branch)
                                <option value="{{ $branch->uuid }}">{{ $branch->name->get() }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="field">
                        <label for="blk-from">{{ __('From') }}</label>
                        <input id="blk-from" type="datetime-local" wire:model="blockStartsAt">
                    </div>

                    <div class="field">
                        <label for="blk-to">{{ __('To') }}</label>
                        <input id="blk-to" type="datetime-local" wire:model="blockEndsAt">
                    </div>

                    <div class="field">
                        <label for="blk-type">{{ __('Reason') }}</label>
                        <select id="blk-type" wire:model="blockType">
                            @foreach ($blockTypes as $type)
                                <option value="{{ $type->value }}">{{ $type->label() }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="field">
                        <label for="blk-note">{{ __('Internal note') }}</label>
                        <textarea id="blk-note" wire:model="blockNote" rows="2"></textarea>
                        {{-- The same advisory every note field carries (§29). --}}
                        <p class="sub">{{ $noteAdvisory }}</p>
                    </div>

                    <button type="submit" class="btn">{{ __('Save block') }}</button>
                </form>
            @endif

            @if ($affected !== [])
                <div class="card">
                    <h3>{{ __('Already booked inside a block') }}</h3>
                    <p class="sub">{{ __('These were NOT changed. Move or cancel them yourself if you need to.') }}</p>
                    <ul>
                        @foreach ($affected as $appointment)
                            <li>{{ $appointment['at'] }} — {{ $appointment['customer'] }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <table>
                <thead>
                <tr>
                    <th>{{ __('Team member') }}</th>
                    <th>{{ __('From') }}</th>
                    <th>{{ __('To') }}</th>
                    <th>{{ __('Reason') }}</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @forelse ($blocks as $block)
                    <tr>
                        <td>{{ $block->employee?->name?->get() }}</td>
                        <td>{{ $block->starts_at->format('Y-m-d H:i') }}</td>
                        <td>{{ $block->ends_at->format('Y-m-d H:i') }}</td>
                        <td>{{ $block->type->label() }}</td>
                        <td>
                            @if ($canBlock)
                                <button type="button" class="btn" wire:click="deleteBlock('{{ $block->uuid }}')">
                                    {{ __('Remove') }}
                                </button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5">{{ __('No upcoming blocks.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    @endif
</div>
