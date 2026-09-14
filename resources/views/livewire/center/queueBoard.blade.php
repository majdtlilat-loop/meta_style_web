{{--
    The reception queue.

    docs/17-QUEUE.md §21.

    Logical CSS properties throughout (`margin-inline-start`, not `margin-left`),
    so the same markup is correct in Arabic and Kurdish without a second
    stylesheet (docs/07-LOCALIZATION.md §10).

    Polls rather than pushes. The decision and its trade-offs are in §15; the
    short version is that a queue has to stay correct when delivery fails, so
    the database is the source of truth and this screen re-reads it.
--}}
<div wire:poll.5s>
    <x-center-nav />

    <h1>{{ __('Queue') }}</h1>

    @if ($error !== '')
        <p role="alert" class="error">{{ $error }}</p>
    @endif

    @if ($saved !== '')
        <p role="status">{{ $saved }}</p>
    @endif

    <form onsubmit="return false" class="filters">
        <label>
            {{ __('Date') }}
            <input type="date" wire:model.live="date">
        </label>

        <label>
            {{ __('Branch') }}
            <select wire:model.live="branch">
                <option value="">{{ __('All branches') }}</option>
                @foreach ($branches as $option)
                    <option value="{{ $option->uuid }}">{{ $option->name }}</option>
                @endforeach
            </select>
        </label>

        {{-- Department, never category: department is how the center is
             organised and category is a price-list heading (ADR-037). --}}
        <label>
            {{ __('Department') }}
            <select wire:model.live="department">
                <option value="">{{ __('All departments') }}</option>
                @foreach ($departments as $option)
                    <option value="{{ $option->uuid }}">{{ $option->name }}</option>
                @endforeach
            </select>
        </label>

        <label>
            {{ __('Destination') }}
            <select wire:model.live="servicePoint">
                <option value="">{{ __('Anywhere') }}</option>
                @foreach ($servicePoints as $option)
                    <option value="{{ $option->uuid }}">{{ $option->display_code }} — {{ $option->name }}</option>
                @endforeach
            </select>
        </label>

        <button type="button" wire:click="refresh">{{ __('Refresh') }}</button>

        @if ($canWalkIn)
            <button type="button" wire:click="openWalkIn">{{ __('New walk-in') }}</button>
        @endif
    </form>

    @if ($walkInOpen)
        {{-- Short on purpose: reception is standing in front of somebody (§22). --}}
        <form wire:submit="createWalkIn" class="walk-in">
            <h2>{{ __('New walk-in') }}</h2>

            <label>
                {{ __('Name') }}
                <input type="text" wire:model="walkInName" maxlength="190">
            </label>

            <label>
                {{ __('Phone (optional)') }}
                <input type="tel" wire:model="walkInPhone" maxlength="32">
            </label>

            <fieldset>
                <legend>{{ __('Services') }}</legend>

                @foreach ($services as $service)
                    <label>
                        <input type="checkbox" value="{{ $service->uuid }}" wire:model="walkInServices">
                        {{ $service->name }} — {{ $service->duration_minutes }} {{ __('min') }}
                    </label>
                @endforeach
            </fieldset>

            <button type="submit">{{ __('Create visit and issue ticket') }}</button>
            <button type="button" wire:click="$set('walkInOpen', false)">{{ __('Cancel') }}</button>
        </form>
    @endif

    @if ($printTicket !== '' && $canPrint)
        <p>
            <a href="{{ route('center.queue.ticket', ['uuid' => $printTicket]) }}" target="_blank" rel="noopener">
                {{ __('Print ticket') }}
            </a>
        </p>
    @endif

    <div class="queue-columns">
        @php
            $columns = [
                'waiting' => __('Waiting'),
                'called' => __('Called'),
                'serving' => __('Serving'),
                'held' => __('On hold'),
                'closed' => __('Recently completed'),
            ];
        @endphp

        @foreach ($columns as $key => $label)
            <section>
                <h2>{{ $label }} ({{ count($grouped[$key]) }})</h2>

                @forelse ($grouped[$key] as $ticket)
                    <article>
                        <h3>{{ $ticket->display_number }}</h3>

                        <p>
                            {{-- Staff surface, so the customer may be named. The
                                 public display never is (§14). --}}
                            {{ $ticket->journey?->customer?->name
                                ?? $ticket->journey?->appointment?->customer?->name
                                ?? __('Customer') }}
                        </p>

                        @if ($ticket->servicePoint !== null)
                            <p>{{ __('Go to') }}: {{ $ticket->servicePoint->display_code }}</p>
                        @endif

                        @if ($ticket->call_count > 0)
                            <p>{{ __('Called') }} ×{{ $ticket->call_count }}</p>
                        @endif

                        @if ($ticket->priority > 0)
                            <p>{{ __('Priority') }}: {{ $ticket->priority }}</p>
                        @endif

                        <div class="actions">
                            @if ($canCall && in_array($key, ['waiting', 'called', 'held'], true))
                                <button type="button" wire:click="call('{{ $ticket->uuid }}')">
                                    {{ $key === 'called' ? __('Recall') : __('Call') }}
                                </button>
                            @endif

                            @if ($canCall && $key === 'called')
                                <button type="button" wire:click="hold('{{ $ticket->uuid }}', true)">
                                    {{ __('No answer') }}
                                </button>
                            @endif

                            @if ($canManage && in_array($key, ['waiting', 'called'], true))
                                <button type="button" wire:click="hold('{{ $ticket->uuid }}', false)">
                                    {{ __('Hold') }}
                                </button>
                            @endif

                            @if ($canManage && $key === 'held')
                                <button type="button" wire:click="resume('{{ $ticket->uuid }}')">
                                    {{ __('Resume') }}
                                </button>
                            @endif

                            {{-- Start and finish go through Journey. There is no
                                 queue-side write of either (correction 3). --}}
                            @if (in_array($key, ['waiting', 'called'], true))
                                <button type="button" wire:click="start('{{ $ticket->uuid }}')">
                                    {{ __('Start service') }}
                                </button>
                            @endif

                            @if ($key === 'serving')
                                <button type="button" wire:click="complete('{{ $ticket->uuid }}')">
                                    {{ __('Finish service') }}
                                </button>
                            @endif

                            @if ($canManage && $key !== 'closed')
                                <button type="button" wire:click="abandon('{{ $ticket->uuid }}')">
                                    {{ __('Customer left') }}
                                </button>
                            @endif

                            @if ($canPrint)
                                <a href="{{ route('center.queue.ticket', ['uuid' => $ticket->uuid]) }}"
                                   target="_blank" rel="noopener">{{ __('Print') }}</a>
                            @endif
                        </div>
                    </article>
                @empty
                    <p>{{ __('Nobody here.') }}</p>
                @endforelse
            </section>
        @endforeach
    </div>
</div>
