{{-- Period charts, one row at a time. Every chart keeps its data table (x-chart.columns) or its labelled rows (x-chart.bars). --}}
@foreach($rows as $row)
    <div class="dash-row" wire:key="dash-row-{{ $row[0]['id'] }}">
        @foreach($row as $chart)
            <section class="chart-card dash-span-{{ $chart['span'] }}" wire:key="dash-chart-{{ $chart['id'] }}" aria-labelledby="dash-chart-{{ $chart['id'] }}">
                <header class="chart-card__head">
                    <div class="cluster cluster--tight">
                        <h2 id="dash-chart-{{ $chart['id'] }}">{{ $chart['title'] }}</h2>
                        @if($chart['note'])<span class="info-tip" role="img" tabindex="0" title="{{ $chart['note'] }}" aria-label="{{ $chart['note'] }}"><x-ui.icon name="info" size="16" /></span>@endif
                    </div>
                    @if($chart['figure'])
                        <span class="chart-card__figure"><strong dir="auto">{{ $chart['figure'] }}</strong></span>
                    @elseif($chart['currency'])
                        <span class="badge">{{ $chart['currency'] }}</span>
                    @endif
                </header>
                @if($chart['type'] === 'columns')
                    @if($chart['empty'])
                        <p class="chart__empty">{{ __('ui.chart.no_data') }}</p>
                    @else
                        <x-chart.columns :label="$chart['title']" :buckets="$chart['buckets']" :series="$chart['series']" :currency="$chart['currency']" />
                    @endif
                @else
                    <x-chart.bars :label="$chart['title']" :items="$chart['items']" :currency="$chart['currency']" />
                @endif
            </section>
        @endforeach
    </div>
@endforeach
