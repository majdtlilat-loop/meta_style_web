{{-- What the center owns today, by category. --}}
<x-ui.card :title="__('manager_plan.features.title')">
    @if($features === [])
        <x-ui.empty-state compact icon="entitlements" :title="__('manager_plan.features.none')" />
    @else
        <div class="plan-features">
            @foreach($features as $group)
                <section class="plan-features__group" wire:key="features-{{ $loop->index }}">
                    <h3>{{ $group['category'] }}</h3>
                    <ul>
                        @foreach($group['items'] as $item)
                            <li wire:key="feature-{{ $item['key'] }}">
                                <x-ui.icon name="check-circle" size="16" />
                                <span>{{ $item['name'] }}</span>
                                @if($item['added'])<span class="badge" data-tone="primary" title="{{ __('manager_plan.features.added_hint') }}">{{ __('manager_plan.features.added') }}</span>@endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
        </div>
    @endif
</x-ui.card>
