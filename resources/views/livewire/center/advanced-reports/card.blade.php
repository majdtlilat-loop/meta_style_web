{{-- One chart card; `$card` is shaped by App\View\AdvancedReports\Cards. --}}
<article @class(['chart-card', 'adv-card', 'adv-span-'.$card['span']]) wire:key="adv-card-{{ md5($card['type'].$card['title']) }}">
    <header class="chart-card__head">
        <h3>{{ $card['title'] }}</h3>
        @if($card['badge'])<span class="badge">{{ $card['badge'] }}</span>@endif
    </header>
    @switch($card['type'])
        @case('line')
            <x-chart.line :label="$card['title']" :buckets="$card['buckets']" :series="$card['series']" :previous="$card['previous'] ?? null" :format="$card['format']" :currency="$card['currency'] ?? null" area />
            @break
        @case('columns')
            <x-chart.columns :label="$card['title']" :buckets="$card['buckets']" :series="$card['series']" :format="$card['format']" :currency="$card['currency']" />
            @break
        @case('stacked')
            <x-chart.stacked :label="$card['title']" :buckets="$card['buckets']" :series="$card['series']" :mode="$card['mode']" :format="$card['format']" :currency="$card['currency']" />
            @break
        @case('donut')
            <x-chart.donut :label="$card['title']" :items="$card['items']" :mode="$card['mode']" :format="$card['format']" :currency="$card['currency']" :keep="$card['keep']" />
            @break
        @case('ranked')
            <x-chart.ranked :label="$card['title']" :items="$card['items']" :format="$card['format']" :currency="$card['currency']" :share="$card['share']" :higher-is-better="$card['higher']" :limit="$card['limit']" :sort="$card['sort']" :previous-label="$card['previous_label']" />
            @break
        @case('grouped')
            <x-chart.grouped :label="$card['title']" :groups="$card['groups']" :series="$card['series']" :format="$card['format']" :currency="$card['currency']" />
            @break
        @case('heatmap')
            <x-chart.heatmap :label="$card['title']" :rows="$card['rows']" :columns="$card['columns']" :values="$card['values']" :row-header="$card['row_header']" :column-header="$card['column_header']" />
            @break
        @case('multiline')
            <x-chart.multiline :label="$card['title']" :buckets="$card['buckets']" :series="$card['series']" :format="$card['format']" :currency="$card['currency']" />
            @break
        @case('radials')
            <div class="adv-radials">
                @foreach($card['items'] as $radial)
                    <div class="adv-radial">
                        <x-chart.radial :label="$radial['label']" :value="$radial['value']" :max="$radial['max']" :format="$radial['format']" :previous="$radial['previous']" :tone="$radial['tone']" :display="$radial['display']" size="6.5rem" />
                        <p class="adv-radial__label">{{ $radial['label'] }}</p>
                        @if($radial['caption'])<span class="kpi__delta" data-tone="{{ $radial['change_tone'] }}" dir="ltr">{{ $radial['caption'] }}</span>@endif
                    </div>
                @endforeach
            </div>
            @break
        @case('stats')
            <div class="adv-stats">
                @foreach($card['items'] as $item)
                    <x-chart.kpi class="adv-stat" :label="$item['label']" :current="$item['current']" :previous="$item['previous']" :format="$item['format']" :currency="$item['currency']" :higher-is-better="$item['higher']" />
                @endforeach
            </div>
            @break
        @case('table')
            @include('livewire.center.advanced-reports.table', ['table' => $card])
            @break
    @endswitch
</article>
