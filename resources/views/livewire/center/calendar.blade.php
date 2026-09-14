{{--
    The staff calendar and booking desk.

    A TABLE, not a calendar library. Day and week are grids Blade can draw, and
    every position uses logical CSS (`start`/`end`, not `left`/`right`) so the
    layout mirrors itself in Arabic and Kurdish without a second stylesheet
    (docs/07-LOCALIZATION.md §10, Phase 6 §23).

    Month is deliberately absent. A month grid of a salon's book is unreadable
    at any realistic volume, and building one well is visual work this phase was
    told not to spend itself on.

    Nothing here decides anything: every appointment arrives already presented
    and already masked by AppointmentPresenter (ADR-042).
--}}
<div>
    <x-center-nav />

    <h1>{{ __('Calendar') }}</h1>
    <p class="sub">{{ __('Bookings for this center.') }}</p>

    @if ($notice)
        <p class="notice">{{ $notice }}</p>
    @endif

    @if ($error)
        <p class="error">{{ $error }}</p>
    @endif

    {{-- Controls --}}
    <div class="row">
        <button type="button" wire:click="move(-1)">&larr; {{ __('Previous') }}</button>
        <button type="button" wire:click="today">{{ __('Today') }}</button>
        <button type="button" wire:click="move(1)">{{ __('Next') }} &rarr;</button>

        <input type="text" wire:model.live="date" style="max-width:10rem" aria-label="{{ __('Date') }}">

        <select wire:model.live="view" aria-label="{{ __('View') }}">
            <option value="day">{{ __('Day') }}</option>
            <option value="week">{{ __('Week') }}</option>
        </select>

        <select wire:model.live="branchUuid" aria-label="{{ __('Branch') }}">
            @foreach ($branches as $branch)
                <option value="{{ $branch->uuid }}">{{ $branch->name->get() }}</option>
            @endforeach
        </select>

        <select wire:model.live="employeeUuid" aria-label="{{ __('Team member') }}">
            <option value="">{{ __('Everyone') }}</option>
            @foreach ($employees as $employee)
                <option value="{{ $employee->uuid }}">{{ $employee->name->get() }}</option>
            @endforeach
        </select>

        <select wire:model.live="statusFilter" aria-label="{{ __('Status') }}">
            <option value="">{{ __('All statuses') }}</option>
            @foreach (['booked', 'confirmed', 'completed', 'cancelled', 'no_show'] as $status)
                <option value="{{ $status }}">{{ __($status) }}</option>
            @endforeach
        </select>

        <label class="inline">
            <input type="checkbox" wire:model.live="affected">
            {{-- The operational answer to "we deactivated Ahmed — who was
                 booked with him?" Nothing is reassigned automatically (§21). --}}
            {{ __('Needs a new team member') }}
        </label>

        @if ($canBook)
            <button type="button" wire:click="startBooking">+ {{ __('New booking') }}</button>
        @endif
    </div>

    {{-- ------------------------------------------------------------ booking --}}
    @if ($booking)
        <div class="card stack">
            <h2>{{ __('New booking') }}</h2>

            <div class="two-up">
                <div>
                    <h3>{{ __('Customer') }}</h3>

                    <div class="field">
                        <label for="cal-search">{{ __('Search existing customers') }}</label>
                        <input id="cal-search" type="text" wire:model.live.debounce.400ms="customerSearch">
                    </div>

                    @foreach ($customers as $candidate)
                        <p>
                            <button type="button" wire:click="chooseCustomer('{{ $candidate['uuid'] }}')">
                                {{ $candidate['name'] }}
                                @if ($customerUuid === $candidate['uuid'])
                                    <span class="tag">{{ __('selected') }}</span>
                                @endif
                            </button>
                        </p>
                    @endforeach

                    <fieldset>
                        <legend>{{ __('Or a new customer') }}</legend>

                        <div class="field">
                            <label for="cal-name">{{ __('Name') }}</label>
                            {{-- Required, and it is a privacy rule: without a
                                 name the phone number lands in a field that is
                                 never masked (ADR-042). --}}
                            <input id="cal-name" type="text" wire:model="newCustomerName">
                        </div>

                        <div class="field">
                            <label for="cal-phone">{{ __('Phone') }}</label>
                            <input id="cal-phone" type="text" wire:model="newCustomerPhone">
                        </div>
                    </fieldset>
                </div>

                <div>
                    <h3>{{ __('Service') }}</h3>

                    <div class="field">
                        <label for="cal-service">{{ __('Service') }}</label>
                        <select id="cal-service" wire:model.live="serviceUuid">
                            <option value="">{{ __('Choose…') }}</option>
                            @foreach ($services as $service)
                                <option value="{{ $service->uuid }}">{{ $service->name->get() }}</option>
                            @endforeach
                        </select>
                    </div>

                    @php($chosen = $services->firstWhere('uuid', $serviceUuid))

                    @if ($chosen && $chosen->variations->isNotEmpty())
                        <div class="field">
                            <label for="cal-variation">{{ __('Option') }}</label>
                            <select id="cal-variation" wire:model.live="variationUuid">
                                <option value="">{{ __('Standard') }}</option>
                                @foreach ($chosen->variations as $variation)
                                    @if ($variation->is_active)
                                        <option value="{{ $variation->uuid }}">{{ $variation->name->get() }}</option>
                                    @endif
                                @endforeach
                            </select>
                        </div>
                    @endif

                    @if ($chosen && $chosen->addons->isNotEmpty())
                        <fieldset>
                            <legend>{{ __('Extras') }}</legend>
                            <div class="checks">
                                @foreach ($chosen->addons as $addon)
                                    @if ($addon->is_active)
                                        <label>
                                            <input type="checkbox" value="{{ $addon->uuid }}"
                                                   wire:model.live="addonUuids">
                                            {{ $addon->name->get() }}
                                        </label>
                                    @endif
                                @endforeach
                            </div>
                        </fieldset>
                    @endif

                    <div class="field">
                        <label for="cal-emp">{{ __('Team member') }}</label>
                        <select id="cal-emp" wire:model.live="bookingEmployeeUuid">
                            {{-- "Any available" is a real choice a customer
                                 makes, not a missing value (§5). --}}
                            <option value="">{{ __('Any available') }}</option>
                            @foreach ($employees as $employee)
                                <option value="{{ $employee->uuid }}">{{ $employee->name->get() }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="field">
                        <label for="cal-date">{{ __('Date') }}</label>
                        <input id="cal-date" type="text" wire:model="bookingDate">
                    </div>

                    <div class="field">
                        <label for="cal-note">{{ __('Booking note') }}</label>
                        <input id="cal-note" type="text" wire:model="bookingNote">
                    </div>

                    <p>
                        <button type="button" class="btn" wire:click="findSlots">{{ __('Find times') }}</button>
                        <button type="button" wire:click="cancelBooking">{{ __('Cancel') }}</button>
                    </p>
                </div>
            </div>

            @if ($slots)
                <h3>{{ __('Available times') }}</h3>
                <div class="row">
                    @foreach ($slots as $slot)
                        <button type="button" class="btn secondary"
                                wire:click="book('{{ $slot['starts_at'] }}')">{{ $slot['time'] }}</button>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    {{-- ----------------------------------------------------------- schedule --}}
    @if ($view === 'day')
        <h2>{{ $date }}</h2>

        <table class="list">
            <thead>
            <tr>
                <th>{{ __('Time') }}</th>
                <th>{{ __('Customer') }}</th>
                <th>{{ __('Services') }}</th>
                <th>{{ __('With') }}</th>
                <th>{{ __('Status') }}</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @forelse ($appointments as $appointment)
                <tr @class(['muted-row' => in_array($appointment['status'], ['cancelled', 'no_show'], true)])>
                    <td>{{ $appointment['local_start'] }}&ndash;{{ $appointment['local_end'] }}</td>
                    <td>{{ $appointment['customer']['name'] ?? '—' }}</td>
                    <td>
                        @foreach ($appointment['items'] as $item)
                            {{ $item['service'] }}@if (! $loop->last), @endif
                        @endforeach
                    </td>
                    <td>
                        @foreach ($appointment['items'] as $item)
                            {{ $item['employee']['name'] ?? __('unassigned') }}@if (! $loop->last), @endif
                        @endforeach
                    </td>
                    <td>{{ __($appointment['status']) }}</td>
                    <td class="actions">
                        <button type="button" wire:click="open('{{ $appointment['uuid'] }}')">{{ __('Open') }}</button>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">{{ __('Nothing booked.') }}</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    @else
        {{-- Week: one column per day, appointments grouped by their own
             branch-local date so a two-timezone center still lands each one on
             the day the customer was told (§7). --}}
        <h2>{{ $days[0] }} &ndash; {{ $days[count($days) - 1] }}</h2>

        <div style="overflow-x:auto">
            <table class="list">
                <thead>
                <tr>
                    @foreach ($days as $day)
                        <th>{{ \Carbon\CarbonImmutable::parse($day)->isoFormat('ddd D') }}</th>
                    @endforeach
                </tr>
                </thead>
                <tbody>
                <tr>
                    @foreach ($days as $day)
                        <td style="vertical-align:top; min-width:9rem">
                            @foreach ($appointments as $appointment)
                                @if ($appointment['local_date'] === $day)
                                    <p @class(['muted-row' => in_array($appointment['status'], ['cancelled', 'no_show'], true)])>
                                        <button type="button" wire:click="open('{{ $appointment['uuid'] }}')">
                                            {{ $appointment['local_start'] }}
                                            {{ $appointment['customer']['name'] ?? '—' }}
                                        </button>
                                    </p>
                                @endif
                            @endforeach
                        </td>
                    @endforeach
                </tr>
                </tbody>
            </table>
        </div>
    @endif

    {{-- -------------------------------------------------------- appointment --}}
    @if ($openAppointment)
        <div class="card stack">
            <div class="row">
                <h2 style="margin:0">{{ $openAppointment['customer']['name'] ?? __('Appointment') }}</h2>
                <span class="pill">{{ __($openAppointment['status']) }}</span>
                <button type="button" style="margin-inline-start:auto"
                        wire:click="close">{{ __('Close') }}</button>
            </div>

            <dl>
                <dt>{{ __('When') }}</dt>
                <dd>{{ $openAppointment['local_date'] }} {{ $openAppointment['local_start'] }}&ndash;{{ $openAppointment['local_end'] }}
                    <span class="tag">{{ $openAppointment['timezone'] }}</span></dd>

                <dt>{{ __('Phone') }}</dt>
                {{-- Already masked by the presenter for a viewer without
                     customer.contact.view. --}}
                <dd>{{ $openAppointment['customer']['phone'] ?? '—' }}</dd>

                <dt>{{ __('Total') }}</dt>
                <dd>{{ $openAppointment['total']['formatted'] }}</dd>

                @if ($openAppointment['customer_note'])
                    <dt>{{ __('Customer said') }}</dt>
                    <dd>{{ $openAppointment['customer_note'] }}</dd>
                @endif
            </dl>

            <table class="list">
                <tbody>
                @foreach ($openAppointment['items'] as $item)
                    <tr>
                        <td>{{ $item['service'] }}
                            @if ($item['variation'])
                                <span class="tag">{{ $item['variation'] }}</span>
                            @endif
                        </td>
                        <td>{{ $item['duration_minutes'] }} {{ __('min') }}</td>
                        <td>{{ $item['employee']['name'] ?? __('unassigned') }}</td>
                        <td>{{ $item['price']['formatted'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>

            <div class="row">
                <button type="button" wire:click="confirm">{{ __('Confirm') }}</button>
                <button type="button" wire:click="complete">{{ __('Completed') }}</button>
                <button type="button" wire:click="noShow">{{ __('No-show') }}</button>
                <button type="button" wire:click="startReschedule">{{ __('Move') }}</button>
            </div>

            <div class="row">
                <input type="text" wire:model="cancelReason" placeholder="{{ __('Reason (optional)') }}">
                <button type="button" wire:click="cancel">{{ __('Cancel booking') }}</button>
            </div>

            @if ($rescheduling && $slots)
                <h3>{{ __('Move to') }}</h3>
                <div class="row">
                    <input type="text" wire:model="bookingDate" style="max-width:10rem">
                    <button type="button" wire:click="startReschedule">{{ __('Find times') }}</button>
                </div>
                <div class="row">
                    @foreach ($slots as $slot)
                        <button type="button" class="btn secondary"
                                wire:click="rescheduleTo('{{ $slot['starts_at'] }}')">{{ $slot['time'] }}</button>
                    @endforeach
                </div>
            @endif

            <h3>{{ __('Internal notes') }}</h3>

            {{-- The Phase 5 follow-up: an explicit warning, not a filter. Nothing
                 inspects what is typed (Phase 6 §1). --}}
            <p class="notice">{{ \App\Kernel\Notes\NoteAdvisory::text() }}</p>

            @foreach ($openAppointment['notes'] as $note)
                <p>{{ $note['body'] }} <span class="tag">{{ __($note['visibility']) }}</span></p>
            @endforeach

            @if ($canNote)
                <div class="row">
                    <input type="text" wire:model="noteBody" placeholder="{{ __('Add a note') }}">
                    <select wire:model="noteVisibility">
                        <option value="internal">{{ __('All staff') }}</option>
                        <option value="manager_only">{{ __('Managers only') }}</option>
                    </select>
                    <button type="button" wire:click="addNote">{{ __('Save note') }}</button>
                </div>
            @endif
        </div>
    @endif
</div>
