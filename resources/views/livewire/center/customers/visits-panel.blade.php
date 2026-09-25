{{--
    A customer's visits — what actually happened, newest first. Rows come from
    VisitsPanel::row(); times are on each visit's branch clock.
--}}
<div>
    <x-ui.card :title="__('manager_customers.visits.title')" flush>
        @if($visits === [])
            <x-ui.empty-state compact icon="journey" :title="__('manager_customers.visits.none')" />
        @else
            <ol class="timeline crm-timeline">
                @foreach($visits as $visit)
                    <li class="timeline__item" wire:key="visit-{{ $visit['uuid'] }}">
                        <span class="timeline__dot" data-tone="{{ $visit['tone'] }}" aria-hidden="true"><x-ui.icon :name="$visit['walk_in'] ? 'user' : 'calendar'" /></span>
                        <div class="timeline__body">
                            <div class="crm-timeline__head">
                                <strong>{{ $visit['arrived'] }}</strong>
                                <x-ui.status :value="$visit['status']" :label="$visit['status_label']" />
                                <span class="chip">{{ $visit['walk_in'] ? __('manager_customers.visits.walk_in') : __('manager_customers.visits.booked') }}</span>
                            </div>
                            <p class="timeline__meta">
                                @if($visit['branch']){{ $visit['branch'] }}@endif
                                @if($visit['reference']) · <span dir="ltr" class="mono">{{ $visit['reference'] }}</span>@endif
                                @if($visit['finished']) · {{ __('manager_customers.visits.finished', ['time' => $visit['finished']]) }}@endif
                            </p>
                            @if($visit['stages'] !== [])
                                <ul class="crm-stages">
                                    @foreach($visit['stages'] as $stage)
                                        <li>
                                            <span>{{ $stage['service'] }}</span>
                                            @if($stage['employee'])<span class="muted">{{ __('manager_customers.bookings.with', ['name' => $stage['employee']]) }}</span>@endif
                                            <x-ui.status :value="$stage['status']" :label="$stage['status_label']" :dot="false" />
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                            @if($visit['reason'])<p class="muted">{{ $visit['reason'] }}</p>@endif
                        </div>
                    </li>
                @endforeach
            </ol>
        @endif
    </x-ui.card>
</div>
