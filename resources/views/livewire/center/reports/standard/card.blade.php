{{-- One card: a heading (title, definition, figure or currency) and the shared chart component its data calls for. --}}
<section @class(['chart-card', 'std-card', 'std-card--wide' => $card['wide'], 'chart-card--flush' => $card['type'] === 'table'])
         aria-labelledby="std-{{ $card['type'] }}-{{ $card['id'] }}" wire:key="std-card-{{ $card['type'] }}-{{ $card['id'] }}">
    <header class="chart-card__head">
        <div class="std-card__title">
            <h4 id="std-{{ $card['type'] }}-{{ $card['id'] }}">{{ $card['title'] }}</h4>
            @if($card['help'])<span class="info-tip" role="img" tabindex="0" title="{{ $card['help'] }}" aria-label="{{ $card['help'] }}"><x-ui.icon name="info" size="14" /></span>@endif
        </div>
        @if($card['figure'])
            <span class="chart-card__figure"><strong dir="ltr">{{ $card['figure'] }}</strong></span>
        @elseif($card['badge'])
            <span class="badge">{{ $card['badge'] }}</span>
        @elseif($card['type'] === 'table')
            <span class="chart-card__figure">{{ $card['props']['caption'] }}</span>
        @endif
    </header>
    @switch($card['type'])
        @case('line')
            <x-chart.line :label="$card['title']" :buckets="$card['props']['buckets']" :series="$card['props']['series']" :previous="$card['props']['previous']"
                :format="$card['props']['format']" :currency="$card['props']['currency']" :area="$card['props']['area']" />
            @break
        @case('stacked')
            <x-chart.stacked :label="$card['title']" :buckets="$card['props']['buckets']" :series="$card['props']['series']" :mode="$card['props']['mode']" />
            @break
        @case('donut')
            <x-chart.donut :label="$card['title']" :items="$card['props']['items']" :mode="$card['props']['mode']" :format="$card['props']['format']"
                :currency="$card['props']['currency']" :keep="$card['props']['keep']" :total="$card['props']['total']" />
            @break
        @case('radial')
            <x-chart.radial :label="$card['title']" :value="$card['props']['value']" :previous="$card['props']['previous']" :tone="$card['props']['tone']" :caption="$card['props']['caption']" />
            @break
        @case('ranked')
            <x-chart.ranked :label="$card['title']" :items="$card['props']['items']" :format="$card['props']['format']" :currency="$card['props']['currency']"
                :limit="$card['props']['limit']" :share="$card['props']['share']" :sort="$card['props']['sort']"
                :higher-is-better="$card['props']['higher_is_better']" :previous-label="$card['props']['previous_label']" />
            @break
        @case('grouped')
            <x-chart.grouped :label="$card['title']" :groups="$card['props']['groups']" :series="$card['props']['series']" :format="$card['props']['format']" :currency="$card['props']['currency']" />
            @break
        @case('columns')
            <x-chart.columns :label="$card['title']" :buckets="$card['props']['buckets']" :series="$card['props']['series']" />
            @break
        @case('heatmap')
            <x-chart.heatmap :label="$card['title']" :rows="$card['props']['rows']" :columns="$card['props']['columns']" :values="$card['props']['values']"
                :row-header="$card['props']['row_header']" :column-header="$card['props']['column_header']" />
            @break
        @case('table')
            @include('livewire.center.reports.standard.table', ['table' => $card['props'], 'id' => $card['id'], 'title' => $card['title']])
            @break
    @endswitch
</section>
