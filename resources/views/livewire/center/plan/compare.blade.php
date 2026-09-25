{{--
    Read-only comparison: the current plan against the public plans, in their
    commercial order, prices in each plan's own currency. "Recommended" only
    where a plan is configured featured. The table scrolls inside its card.
--}}
<section class="card card--flush plan-compare" id="compare" aria-labelledby="plan-compare-title">
    <header class="card__header">
        <h2 id="plan-compare-title">{{ __('manager_plan.compare.title') }}</h2>
        @if($compare['has_yearly'])
            <div class="segmented" role="group" aria-label="{{ __('manager_plan.compare.cycle') }}">
                <button type="button" wire:click="setCycle('monthly')" aria-pressed="{{ $compare['cycle'] === 'monthly' ? 'true' : 'false' }}">{{ __('manager_plan.compare.monthly') }}</button>
                <button type="button" wire:click="setCycle('yearly')" aria-pressed="{{ $compare['cycle'] === 'yearly' ? 'true' : 'false' }}">{{ __('manager_plan.compare.yearly') }}</button>
            </div>
        @endif
    </header>

    @if($compare['highlight'])
        <div class="plan-compare__focus" role="status">
            <x-ui.icon :name="$compare['highlight']['owned'] ? 'check-circle' : 'sparkles'" size="18" />
            <p>
                @if($compare['highlight']['owned'])
                    {{ __('manager_plan.compare.focus_owned', ['feature' => $compare['highlight']['name']]) }}
                @elseif($compare['highlight']['offered'])
                    {{ __('manager_plan.compare.focus_offered', ['feature' => $compare['highlight']['name']]) }}
                @else
                    {{ __('manager_plan.compare.focus_contact', ['feature' => $compare['highlight']['name']]) }}
                @endif
            </p>
        </div>
    @endif

    @if($compare['columns'] === [])
        <x-ui.empty-state compact icon="plans" :title="__('manager_plan.compare.none')" />
    @else
        <div class="plan-compare__scroll" role="region" aria-labelledby="plan-compare-title" tabindex="0" wire:loading.class="is-refreshing" wire:target="setCycle">
            <table class="matrix plan-compare__table">
                <caption class="sr-only">{{ __('manager_plan.compare.title') }}</caption>
                <thead>
                    <tr>
                        <th scope="col" class="plan-compare__corner">{{ __('manager_plan.compare.feature') }}</th>
                        @foreach($compare['columns'] as $column)
                            <th scope="col" @class(['plan-compare__plan', 'is-current' => $column['current'], 'is-featured' => $column['featured']]) wire:key="plan-col-{{ $column['uuid'] }}">
                                <span class="plan-compare__name">{{ $column['name'] }}</span>
                                <span class="plan-compare__badges">
                                    @if($column['current'])
                                        <span class="badge" data-tone="success">{{ __('manager_plan.compare.current') }}</span>
                                    @elseif($column['featured'])
                                        <span class="badge" data-tone="primary">{{ __('manager_plan.compare.recommended') }}</span>
                                    @endif
                                </span>
                                <span class="plan-compare__price">
                                    @if($column['price'])
                                        <strong class="tabular">{{ $column['price'] }}</strong><small>{{ $column['per'] }}</small>
                                    @else
                                        <small>{{ __('manager_plan.compare.not_offered') }}</small>
                                    @endif
                                </span>
                                @if($column['saving'])<span class="plan-compare__saving">{{ __('manager_plan.compare.save', ['percent' => $column['saving']]) }}</span>@endif
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach($compare['features'] as $group)
                        <tr class="matrix__group" wire:key="compare-group-{{ $loop->index }}"><th scope="colgroup" colspan="{{ count($compare['columns']) + 1 }}">{{ $group['category'] }}</th></tr>
                        @foreach($group['rows'] as $row)
                            <tr @class(['is-highlight' => $row['highlight']]) @if($row['highlight']) data-plan-highlight @endif wire:key="compare-{{ $row['key'] }}">
                                <th scope="row">{{ $row['name'] }}</th>
                                @foreach($row['cells'] as $included)
                                    <td class="center">
                                        @if($included)
                                            <x-ui.icon name="check" size="18" class="plan-compare__yes" /><span class="sr-only">{{ __('manager_plan.compare.included') }}</span>
                                        @else
                                            <span class="plan-compare__no" aria-hidden="true">—</span><span class="sr-only">{{ __('manager_plan.compare.not_included') }}</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    @endforeach

                    @if($compare['limits'] !== [])
                        <tr class="matrix__group"><th scope="colgroup" colspan="{{ count($compare['columns']) + 1 }}">{{ __('manager_plan.compare.allowances') }}</th></tr>
                        @foreach($compare['limits'] as $limit)
                            <tr wire:key="compare-limit-{{ $loop->index }}">
                                <th scope="row">{{ $limit['label'] }}</th>
                                @foreach($limit['cells'] as $value)
                                    <td class="center tabular">{{ $value }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    @endif

                    <tr class="plan-compare__actions">
                        <th scope="row"><span class="sr-only">{{ __('manager_plan.compare.actions') }}</span></th>
                        @foreach($compare['columns'] as $column)
                            <td class="center">
                                @if($column['current'])
                                    <span class="muted">{{ __('manager_plan.compare.your_plan') }}</span>
                                @elseif($column['request'])
                                    <a class="button button--sm {{ $column['featured'] ? '' : 'button--secondary' }}" href="{{ $column['request'] }}" wire:navigate>{{ __('manager_plan.compare.request') }}</a>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="plan-compare__note">{{ __('manager_plan.compare.note') }}</p>
    @endif
</section>
