@php
    use App\View\Label;

    $tenantNames = $tenants->pluck('name', 'id');
    // The two AI products share words like "runs"; name which product it is.
    $product = fn (string $code) => \Illuminate\Support\Facades\Lang::has('sadmin_usage.resources.'.$code) ? __('sadmin_usage.resources.'.$code) : null;
    $resourceLabel = fn (string $code) => Label::for('usage_resource', $code).($product($code) ? ' · '.$product($code) : '');
    $date = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->translatedFormat('j M Y') : '—';
    $toneFor = fn (?string $status) => match ($status) { 'exhausted' => 'danger', 'high', 'warning' => 'warning', default => 'success' };
@endphp

<div class="stack">
    <x-ui.page-header :title="__('sadmin_usage.title')">
        <x-slot:actions>
            <button class="button" type="button" wire:click="openOverride"><x-ui.icon name="usage" size="16" />{{ __('sadmin_usage.set_override') }}</button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.flash />

    <form class="filter-bar" aria-label="{{ __('sadmin_usage.filters.label') }}" x-on:submit.prevent>
        <div class="field">
            <label for="usage-center">{{ __('sadmin_usage.filters.center') }}</label>
            <select id="usage-center" wire:model.live="center">
                <option value="">{{ __('sadmin_usage.filters.all_centers') }}</option>
                @foreach($tenants as $tenant)
                    <option value="{{ $tenant->id }}">{{ $tenant->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="field">
            <label for="usage-resource">{{ __('sadmin_usage.filters.resource') }}</label>
            <select id="usage-resource" wire:model.live="resourceFilter">
                <option value="">{{ __('sadmin_usage.filters.all_resources') }}</option>
                @foreach($resources as $code)
                    <option value="{{ $code }}">{{ $resourceLabel($code) }}</option>
                @endforeach
            </select>
        </div>
        <div class="field">
            <label for="usage-status">{{ __('sadmin_usage.filters.status') }}</label>
            <select id="usage-status" wire:model.live="statusFilter">
                <option value="">{{ __('sadmin_usage.filters.all_statuses') }}</option>
                @foreach(['normal', 'warning', 'high', 'exhausted'] as $value)
                    <option value="{{ $value }}">{{ Label::for('usage_status', $value) }}</option>
                @endforeach
            </select>
        </div>
        @if($hasFilters)
            <div class="filter-bar__actions">
                <button class="button button--ghost button--sm" type="button" wire:click="clearFilters"><x-ui.icon name="close" size="16" />{{ __('sadmin_usage.filters.clear') }}</button>
            </div>
        @endif
    </form>

    <div class="table-shell table-shell--stack" wire:loading.class="is-refreshing" wire:target="center,resourceFilter,statusFilter,clearFilters,gotoPage,nextPage,previousPage">
        @if($projections->isEmpty())
            <x-ui.empty-state icon="usage" :title="$hasFilters ? __('sadmin_usage.empty_filtered') : __('sadmin_usage.empty')" />
        @else
            <table>
                <caption class="sr-only">{{ __('sadmin_usage.title') }}</caption>
                <thead>
                    <tr>
                        <th scope="col">{{ __('sadmin_usage.table.center') }}</th>
                        <th scope="col">{{ __('sadmin_usage.table.resource') }}</th>
                        <th scope="col">{{ __('sadmin_usage.table.usage') }}</th>
                        <th scope="col">{{ __('sadmin_usage.table.status') }}</th>
                        <th scope="col">{{ __('sadmin_usage.table.updated') }}</th>
                        <th scope="col" class="actions"><span class="sr-only">{{ __('sadmin_centers.table.actions') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($projections as $projection)
                        @php
                            $percent = $projection->allowance === null || $projection->allowance === 0 ? null : min(100, max(0, (int) $projection->percent));
                        @endphp
                        <tr wire:key="projection-{{ $projection->tenant_id }}-{{ $projection->resource }}">
                            <td data-label="{{ __('sadmin_usage.table.center') }}" data-primary>
                                <a class="cell-title" href="{{ route('superadmin.centers.show', ['tenant' => $projection->tenant_id, 'tab' => 'usage']) }}" wire:navigate>{{ $tenantNames[$projection->tenant_id] ?? __('sadmin_usage.unknown_center') }}</a>
                            </td>
                            <td data-label="{{ __('sadmin_usage.table.resource') }}">
                                <span class="cell-title">{{ Label::for('usage_resource', $projection->resource) }}</span>
                                @if($product($projection->resource))<span class="cell-sub">{{ $product($projection->resource) }}</span>@endif
                            </td>
                            <td data-label="{{ __('sadmin_usage.table.usage') }}">
                                <div class="usage-progress">
                                    <span class="tabular"><strong>{{ number_format($projection->used) }}</strong>
                                        <span class="muted">/ {{ $projection->allowance === null ? ($enforced[$projection->resource] ?? false ? __('sadmin_usage.unlimited') : __('sadmin_usage.metered')) : number_format($projection->allowance) }}</span></span>
                                    @if($percent !== null)
                                        <div class="progress" data-tone="{{ $toneFor($projection->status->value) }}" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $percent }}" aria-label="{{ Label::for('usage_resource', $projection->resource) }}"><div class="progress__bar" style="width: {{ $percent }}%"></div></div>
                                    @endif
                                </div>
                            </td>
                            <td data-label="{{ __('sadmin_usage.table.status') }}">
                                <x-ui.status :value="$toneFor($projection->status->value) === 'success' ? 'active' : ($projection->status->value === 'exhausted' ? 'failed' : 'warning')" :label="Label::for('usage_status', $projection->status->value)" />
                            </td>
                            <td data-label="{{ __('sadmin_usage.table.updated') }}">
                                <time datetime="{{ $projection->projected_at->toIso8601String() }}" title="{{ $projection->projected_at->translatedFormat('j M Y, H:i') }}">{{ $projection->projected_at->diffForHumans() }}</time>
                                <span class="cell-sub">{{ __('sadmin_usage.table.period') }}: {{ $date($projection->period_end) }}</span>
                            </td>
                            <td class="actions">
                                @if($enforced[$projection->resource] ?? false)
                                    <button class="button button--secondary button--sm" type="button" wire:click="openOverride('{{ $projection->tenant_id }}', '{{ $projection->resource }}')">{{ __('sadmin_usage.change') }}</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
    @if($projections->isNotEmpty())<p class="field-help">{{ __('sadmin_usage.lag_note') }}</p>@endif

    {{ $projections->links() }}

    <x-ui.card :title="__('sadmin_usage.overrides_title')" flush>
        @if($overrides->isEmpty())
            <x-ui.empty-state compact icon="usage" :title="__('sadmin_usage.no_overrides')" />
        @else
            <div class="table-shell table-shell--stack">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('sadmin_usage.table.center') }}</th>
                            <th scope="col">{{ __('sadmin_usage.table.resource') }}</th>
                            <th scope="col">{{ __('sadmin_usage.table.allowance') }}</th>
                            <th scope="col">{{ __('sadmin_usage.table.applies') }}</th>
                            <th scope="col">{{ __('sadmin_usage.table.reason') }}</th>
                            <th scope="col" class="actions"><span class="sr-only">{{ __('sadmin_centers.table.actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($overrides as $override)
                            <tr wire:key="override-{{ $override->id }}">
                                <td data-label="{{ __('sadmin_usage.table.center') }}" data-primary><span class="cell-title">{{ $tenantNames[$override->tenant_id] ?? __('sadmin_usage.unknown_center') }}</span></td>
                                <td data-label="{{ __('sadmin_usage.table.resource') }}">
                                    <span class="cell-title">{{ Label::for('usage_resource', $override->resource) }}</span>
                                    @if($product($override->resource))<span class="cell-sub">{{ $product($override->resource) }}</span>@endif
                                </td>
                                <td data-label="{{ __('sadmin_usage.table.allowance') }}" class="tabular">{{ $override->allowance === null ? __('sadmin_usage.unlimited') : number_format($override->allowance) }}</td>
                                <td data-label="{{ __('sadmin_usage.table.applies') }}">{{ $override->enforce_immediately ? __('sadmin_usage.applies_now') : __('sadmin_usage.applies_next') }}</td>
                                <td data-label="{{ __('sadmin_usage.table.reason') }}"><span class="clamp-2">{{ $override->reason ?? '—' }}</span></td>
                                <td class="actions">
                                    <button class="button button--ghost button--sm" type="button" wire:click="openReset({{ $override->id }})">{{ __('sadmin_usage.clear') }}</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>

    @if($panel === 'override')
        <x-ui.modal :title="__('sadmin_usage.override_title')" :description="__('sadmin_usage.override_help')" icon="usage" submit="save">
            <x-ui.field :label="__('sadmin_usage.fields.center')" for="override-center" name="tenantId" required>
                <select id="override-center" wire:model="tenantId" required>
                    <option value="">{{ __('sadmin_usage.fields.choose_center') }}</option>
                    @foreach($tenants as $tenant)
                        <option value="{{ $tenant->id }}">{{ $tenant->name }}</option>
                    @endforeach
                </select>
            </x-ui.field>
            <x-ui.field :label="__('sadmin_usage.fields.resource')" for="override-resource" name="resource" required>
                <select id="override-resource" wire:model="resource" required>
                    <option value="">{{ __('sadmin_usage.fields.choose_resource') }}</option>
                    @foreach($resources as $code)
                        @if($enforced[$code])<option value="{{ $code }}">{{ $resourceLabel($code) }}</option>@endif
                    @endforeach
                </select>
            </x-ui.field>
            <label class="choice"><input type="checkbox" wire:model.live="unlimited"><span>{{ __('sadmin_usage.fields.unlimited') }}</span></label>
            @unless($unlimited)
                <x-ui.field :label="__('sadmin_usage.fields.allowance')" for="override-allowance" name="allowance" required>
                    <input id="override-allowance" type="number" min="0" step="1" inputmode="numeric" wire:model="allowance" required>
                </x-ui.field>
            @endunless
            <label class="choice"><input type="checkbox" wire:model="enforceImmediately"><span>{{ __('sadmin_usage.fields.enforce_immediately') }}<small class="field-help">{{ __('sadmin_usage.fields.enforce_help') }}</small></span></label>
            <x-ui.field :label="__('sadmin_usage.fields.reason')" for="override-reason" name="reason" required>
                <textarea id="override-reason" rows="3" wire:model="reason" required></textarea>
            </x-ui.field>
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="closePanel">{{ __('ui.actions.cancel') }}</button>
                <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="save">{{ __('sadmin_usage.save') }}</button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if($resetting)
        <x-ui.modal :title="__('sadmin_usage.clear_title')" :description="__('sadmin_usage.clear_body', ['resource' => Label::for('usage_resource', $resetting->resource), 'center' => $tenantNames[$resetting->tenant_id] ?? __('sadmin_usage.unknown_center')])" icon="reset" tone="warning" submit="clear({{ $resetting->id }})">
            <x-ui.field :label="__('sadmin_usage.fields.reason')" for="reset-reason" name="clearReasons.{{ $resetting->id }}" required>
                <textarea id="reset-reason" rows="3" wire:model="clearReasons.{{ $resetting->id }}" required></textarea>
            </x-ui.field>
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="closePanel">{{ __('ui.actions.cancel') }}</button>
                <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="clear">{{ __('sadmin_usage.clear') }}</button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
