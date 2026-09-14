{{--
    The operational board. NOT the queue display: no ticket numbers, no calling,
    no TV output — those are Phase 8 (docs/13-ROADMAP.md Phase 7 §§33, 44).

    Refreshed by polling rather than pushed. Real-time infrastructure is a
    production dependency nobody has asked for yet (§38).
--}}
<div wire:poll.30s>
    <h1>{{ __("Today's visits") }}</h1>
    <p class="sub">{{ __('Who is expected, who is here, and who is being served.') }}</p>

    @if ($error)
        <p class="error" role="alert">{{ $error }}</p>
    @endif

    @if ($saved)
        <p class="notice" role="status">{{ $saved }}</p>
    @endif

    <form class="row" wire:submit.prevent>
        <div class="field">
            <label for="brd-date">{{ __('Day') }}</label>
            <input id="brd-date" type="date" wire:model.live="date">
        </div>

        <div class="field">
            <label for="brd-branch">{{ __('Branch') }}</label>
            <select id="brd-branch" wire:model.live="branch">
                <option value="">{{ __('All branches') }}</option>
                @foreach ($branches as $b)
                    <option value="{{ $b->uuid }}">{{ $b->name->get() }}</option>
                @endforeach
            </select>
        </div>

        <div class="field">
            <label for="brd-dept">{{ __('Department') }}</label>
            <select id="brd-dept" wire:model.live="department">
                <option value="">{{ __('All departments') }}</option>
                @foreach ($departments as $d)
                    <option value="{{ $d->uuid }}">{{ $d->name->get() }}</option>
                @endforeach
            </select>
        </div>

        <div class="field">
            <label for="brd-emp">{{ __('Team member') }}</label>
            <select id="brd-emp" wire:model.live="employee">
                <option value="">{{ __('Everyone') }}</option>
                @foreach ($employees as $e)
                    <option value="{{ $e->uuid }}">{{ $e->name->get() }}</option>
                @endforeach
            </select>
        </div>
    </form>

    @php
        $columns = [
            'not_arrived' => __('Not arrived'),
            'waiting' => __('Arrived / waiting'),
            'in_service' => __('In service'),
            'completed' => __('Completed'),
            'abandoned' => __('Abandoned'),
        ];
    @endphp

    <div class="board">
        @foreach ($columns as $key => $heading)
            <section class="card">
                <h2>{{ $heading }} ({{ count($grouped[$key]) }})</h2>

                @forelse ($grouped[$key] as $row)
                    <article class="board-row">
                        <strong>{{ $row->appointment->localStart()->format('H:i') }}</strong>
                        — {{ $row->appointment->customer?->name }}

                        @if ($row->journey === null)
                            @if ($canManage)
                                <button type="button" class="btn"
                                        wire:click="checkIn('{{ $row->appointment->uuid }}')">
                                    {{ __('Check in') }}
                                </button>
                            @endif
                        @else
                            <button type="button" class="btn"
                                    wire:click="$set('openJourney', '{{ $row->journey->uuid }}')">
                                {{ __('Open') }}
                            </button>

                            @if ($stage = $row->currentStage())
                                <p class="sub">
                                    {{ __('Now') }}: {{ $stage->item?->service_name?->get() }}
                                    @if ($stage->employee)
                                        — {{ $stage->employee->name->get() }}
                                    @endif
                                </p>
                            @endif
                        @endif
                    </article>
                @empty
                    <p class="sub">{{ __('Nobody here.') }}</p>
                @endforelse
            </section>
        @endforeach
    </div>

    {{-- ----------------------------------------------------- detail panel --}}
    @if ($openRow && $openRow->journey)
        @php($journey = $openRow->journey)
        <div class="card">
            <h2>
                {{ $openRow->appointment->customer?->name }}
                — {{ __('arrived') }} {{ $journey->arrived_at?->format('H:i') }}
            </h2>

            <table>
                <thead>
                <tr>
                    <th>{{ __('Service') }}</th>
                    <th>{{ __('Department') }}</th>
                    <th>{{ __('Booked with') }}</th>
                    <th>{{ __('Actually with') }}</th>
                    <th>{{ __('Planned') }}</th>
                    <th>{{ __('Actual') }}</th>
                    <th>{{ __('Resources') }}</th>
                    <th>{{ __('Status') }}</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @foreach ($journey->stages as $stage)
                    <tr>
                        <td>{{ $stage->item?->service_name?->get() }}</td>
                        <td>{{ $stage->department?->name?->get() ?? '—' }}</td>

                        {{-- PLANNED beside ACTUAL. A host should be able to see
                             that the customer is not with the stylist they
                             asked for (§18). --}}
                        <td>{{ $stage->item?->employee?->name?->get() ?? __('Anyone') }}</td>
                        <td>{{ $stage->employee?->name?->get() ?? '—' }}</td>

                        <td>{{ $stage->item?->starts_at?->format('H:i') }}</td>
                        <td>
                            {{ $stage->service_started_at?->format('H:i') ?? '—' }}
                            @if ($stage->service_completed_at)
                                → {{ $stage->service_completed_at->format('H:i') }}
                            @endif
                        </td>

                        <td>
                            @forelse ($stage->resources as $usage)
                                <span>
                                    {{ $usage->resource?->name?->get() }}
                                    {{ $usage->assigned_at->format('H:i') }}–{{ $usage->released_at?->format('H:i') ?? '…' }}
                                </span><br>
                            @empty
                                —
                            @endforelse
                        </td>

                        <td>{{ $stage->status->label() }}</td>

                        <td>
                            @if ($canStart && $stage->status === \App\Modules\ServiceJourney\Domain\Enums\StageStatus::Waiting)
                                <button type="button" class="btn" wire:click="startStage('{{ $stage->uuid }}')">
                                    {{ __('Start') }}
                                </button>
                            @endif

                            @if ($canComplete && $stage->status === \App\Modules\ServiceJourney\Domain\Enums\StageStatus::InService)
                                <button type="button" class="btn" wire:click="completeStage('{{ $stage->uuid }}')">
                                    {{ __('Finish') }}
                                </button>
                                <button type="button" class="btn" wire:click="handoff('{{ $stage->uuid }}')">
                                    {{ __('Hand on') }}
                                </button>
                            @endif

                            @if ($canReassign && ! $stage->isTerminal())
                                <button type="button" class="btn"
                                        wire:click="$set('reassignStage', '{{ $stage->uuid }}')">
                                    {{ __('Reassign') }}
                                </button>
                                <button type="button" class="btn"
                                        wire:click="$set('swapStage', '{{ $stage->uuid }}')">
                                    {{ __('Swap resource') }}
                                </button>
                            @endif

                            @if ($canNote)
                                <button type="button" class="btn"
                                        wire:click="$set('noteStage', '{{ $stage->uuid }}')">
                                    {{ __('Note') }}
                                </button>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>

            @if ($reassignStage !== '')
                <form wire:submit="reassign" class="row">
                    <select wire:model="reassignEmployee">
                        <option value="">{{ __('Choose…') }}</option>
                        @foreach ($employees as $e)
                            <option value="{{ $e->uuid }}">{{ $e->name->get() }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="btn">{{ __('Reassign') }}</button>
                </form>
            @endif

            @if ($swapStage !== '')
                <form wire:submit="swapResource" class="row">
                    <input type="text" wire:model="swapFrom" placeholder="{{ __('Current resource') }}">
                    <input type="text" wire:model="swapTo" placeholder="{{ __('New resource') }}">
                    <button type="submit" class="btn">{{ __('Swap') }}</button>
                </form>
            @endif

            @if ($noteStage !== '')
                <form wire:submit="addNote">
                    <div class="field">
                        <label for="jb-note">{{ __('Internal note') }}</label>
                        <textarea id="jb-note" wire:model="noteBody" rows="2"></textarea>
                        <p class="sub">{{ $noteAdvisory }}</p>
                    </div>
                    <button type="submit" class="btn">{{ __('Add note') }}</button>
                </form>
            @endif

            @if ($canManage)
                <div class="row">
                    <button type="button" class="btn" wire:click="completeJourney('{{ $journey->uuid }}')">
                        {{ __('Complete visit') }}
                    </button>

                    <input type="text" wire:model="abortReason" placeholder="{{ __('Why?') }}">
                    <button type="button" class="btn" wire:click="abortJourney('{{ $journey->uuid }}')">
                        {{ __('Customer left') }}
                    </button>
                </div>
                <p class="sub">
                    {{ __('Marking a visit abandoned does not cancel the appointment — cancel it from the calendar if that is what you mean.') }}
                </p>
            @endif

            {{-- Phase 9. A LINK to the till, not a call: the board imports nothing
                 from Sales, and completing a visit never creates a sale. The till
                 opens (or reuses) the visit's draft (docs/18-SALES.md §33). --}}
            @if ($canCheckout)
                <div class="row">
                    <a class="btn" href="{{ route('center.pos', ['journey' => $journey->uuid]) }}" wire:navigate>
                        {{ __('Checkout') }}
                    </a>
                </div>
            @endif
        </div>
    @endif
</div>
