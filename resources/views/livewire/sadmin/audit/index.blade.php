@php
    use App\View\AuditAction;
    use Illuminate\Support\Facades\Lang;

    $tenantNames = $tenants->pluck('name', 'id');
    $severityTone = ['info' => 'neutral', 'notice' => 'info', 'warning' => 'warning', 'critical' => 'danger'];
    $translated = fn (string $group, ?string $value) => $value !== null && Lang::has('sadmin_audit.'.$group.'.'.$value) ? __('sadmin_audit.'.$group.'.'.$value) : \Illuminate\Support\Str::headline((string) $value);
    $dateTime = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->translatedFormat('j M Y, H:i:s') : '—';
    $scalar = function (mixed $value): string {
        if ($value === null || $value === '') {
            return __('sadmin_audit.detail.empty_value');
        }
        if (is_bool($value)) {
            return $value ? __('ui.states.yes') : __('ui.states.no');
        }
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }

        return (string) $value;
    };
@endphp

<div class="stack">
    <x-ui.page-header :title="__('sadmin_audit.title')">
        <x-slot:meta>
            <span class="result-count">{{ trans_choice('sadmin_audit.results', $entries->total(), ['count' => number_format($entries->total())]) }}</span>
        </x-slot:meta>
    </x-ui.page-header>

    <form class="filter-bar" role="search" aria-label="{{ __('sadmin_audit.search') }}" x-on:submit.prevent data-collapsible x-data="{ open: false }" :class="{ 'is-open': open }">
        <div class="field filter-bar__search">
            <label for="audit-search">{{ __('sadmin_audit.search') }}</label>
            <div class="search-input">
                <x-ui.icon name="search" />
                <input id="audit-search" type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('sadmin_audit.search_help') }}" autocomplete="off">
            </div>
        </div>
        <button class="button button--secondary filter-bar__toggle" type="button" x-on:click="open = ! open" :aria-expanded="open ? 'true' : 'false'" aria-controls="audit-category">
            <x-ui.icon name="filter" size="16" />{{ __('ui.actions.filters') }}
        </button>
        <div class="field">
            <label for="audit-category">{{ __('sadmin_audit.fields.category') }}</label>
            <select id="audit-category" wire:model.live="category">
                <option value="">{{ __('sadmin_audit.all_categories') }}</option>
                @foreach(['tenancy', 'security', 'config', 'system', 'finance'] as $value)
                    <option value="{{ $value }}">{{ __('sadmin_audit.categories.'.$value) }}</option>
                @endforeach
            </select>
        </div>
        <div class="field">
            <label for="audit-severity">{{ __('sadmin_audit.fields.severity') }}</label>
            <select id="audit-severity" wire:model.live="severity">
                <option value="">{{ __('sadmin_audit.all_severities') }}</option>
                @foreach(['info', 'notice', 'warning', 'critical'] as $value)
                    <option value="{{ $value }}">{{ __('sadmin_audit.severities.'.$value) }}</option>
                @endforeach
            </select>
        </div>
        <div class="field">
            <label for="audit-center">{{ __('sadmin_audit.fields.center') }}</label>
            <select id="audit-center" wire:model.live="tenantId">
                <option value="">{{ __('sadmin_audit.all_centers') }}</option>
                @foreach($tenants as $tenant)
                    <option value="{{ $tenant->id }}">{{ $tenant->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="field">
            <label for="audit-actor">{{ __('sadmin_audit.fields.actor') }}</label>
            <input id="audit-actor" type="search" wire:model.live.debounce.400ms="actor" placeholder="{{ __('sadmin_audit.actor_help') }}">
        </div>
        <div class="field">
            <label for="audit-target">{{ __('sadmin_audit.fields.target') }}</label>
            <input id="audit-target" type="search" wire:model.live.debounce.400ms="target" placeholder="{{ __('sadmin_audit.target_help') }}">
        </div>
        <div class="field">
            <label for="audit-from">{{ __('sadmin_audit.fields.from') }}</label>
            <input id="audit-from" type="date" wire:model.live="dateFrom" max="{{ $dateTo ?: now()->toDateString() }}">
        </div>
        <div class="field">
            <label for="audit-to">{{ __('sadmin_audit.fields.to') }}</label>
            <input id="audit-to" type="date" wire:model.live="dateTo" min="{{ $dateFrom }}" max="{{ now()->toDateString() }}">
        </div>
        @if($hasFilters)
            <div class="filter-bar__actions">
                <button class="button button--ghost button--sm" type="button" wire:click="clearFilters"><x-ui.icon name="close" size="16" />{{ __('sadmin_audit.clear') }}</button>
            </div>
        @endif
    </form>

    <div class="table-shell table-shell--stack" wire:loading.class="is-refreshing" wire:target="search,category,severity,tenantId,actor,target,dateFrom,dateTo,clearFilters,gotoPage,nextPage,previousPage">
        @if($entries->isEmpty())
            <x-ui.empty-state :icon="$hasFilters ? 'filter' : 'audit'" :title="$hasFilters ? __('sadmin_audit.empty') : __('sadmin_audit.empty_none')">
                @if($hasFilters)<button class="button button--secondary button--sm" type="button" wire:click="clearFilters">{{ __('sadmin_audit.clear') }}</button>@endif
            </x-ui.empty-state>
        @else
            <table>
                <caption class="sr-only">{{ __('sadmin_audit.title') }}</caption>
                <thead>
                    <tr>
                        <th scope="col">{{ __('sadmin_audit.fields.action') }}</th>
                        <th scope="col">{{ __('sadmin_audit.fields.actor') }}</th>
                        <th scope="col">{{ __('sadmin_audit.fields.center') }}</th>
                        <th scope="col">{{ __('sadmin_audit.fields.severity') }}</th>
                        <th scope="col">{{ __('sadmin_audit.fields.when') }}</th>
                        <th scope="col" class="actions"><span class="sr-only">{{ __('sadmin_audit.view_details') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($entries as $entry)
                        <tr wire:key="audit-{{ $entry->uuid }}" @if($selectedUuid === $entry->uuid) aria-selected="true" @endif>
                            <td data-label="{{ __('sadmin_audit.fields.action') }}" data-primary>
                                <span class="cell-title">{{ AuditAction::label($entry->action) }}</span>
                                <span class="cell-sub"><span class="mono" dir="ltr">{{ $entry->action }}</span> · {{ $translated('categories', $entry->category) }}</span>
                            </td>
                            <td data-label="{{ __('sadmin_audit.fields.actor') }}">
                                <span class="cell-title">{{ AuditAction::actor($entry->actor_label, $entry->actor_type) ?: $translated('actor_types', $entry->actor_type) }}</span>
                                <span class="cell-sub">{{ $translated('actor_types', $entry->actor_type) }}</span>
                            </td>
                            <td data-label="{{ __('sadmin_audit.fields.center') }}">{{ $entry->tenant_id ? ($tenantNames[$entry->tenant_id] ?? '—') : __('sadmin_audit.platform_wide') }}</td>
                            <td data-label="{{ __('sadmin_audit.fields.severity') }}"><x-ui.status :tone="$severityTone[$entry->severity] ?? 'neutral'" :label="$translated('severities', $entry->severity)" /></td>
                            <td data-label="{{ __('sadmin_audit.fields.when') }}" class="nowrap"><time datetime="{{ $entry->occurred_at?->toIso8601String() }}" title="{{ $dateTime($entry->occurred_at) }}">{{ $entry->occurred_at?->diffForHumans() }}</time></td>
                            <td class="actions"><button class="button button--ghost button--sm" type="button" wire:click="showDetails('{{ $entry->uuid }}')">{{ __('sadmin_audit.view_details') }}</button></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{ $entries->links() }}

    @if($selectedEntry)
        @php
            $before = is_array($selectedEntry->before) ? $selectedEntry->before : [];
            $after = is_array($selectedEntry->after) ? $selectedEntry->after : [];
            $fields = array_values(array_unique(array_merge(array_keys($before), array_keys($after))));
            $meta = is_array($selectedEntry->meta) ? $selectedEntry->meta : [];
        @endphp
        <x-ui.drawer :title="AuditAction::label($selectedEntry->action)" :description="$dateTime($selectedEntry->occurred_at)" close="closeDetails">
            <div class="stack">
                <section class="drawer-section">
                    <h3>{{ __('sadmin_audit.detail.summary') }}</h3>
                    <dl class="summary-list">
                        <div><dt>{{ __('sadmin_audit.fields.actor') }}</dt><dd>{{ AuditAction::actor($selectedEntry->actor_label, $selectedEntry->actor_type) ?: $translated('actor_types', $selectedEntry->actor_type) }}</dd></div>
                        <div><dt>{{ __('sadmin_audit.fields.center') }}</dt><dd>{{ $selectedEntry->tenant_id ? ($tenantNames[$selectedEntry->tenant_id] ?? '—') : __('sadmin_audit.platform_wide') }}</dd></div>
                        @if($selectedEntry->target_label)
                            <div><dt>{{ __('sadmin_audit.fields.target') }}</dt><dd>{{ AuditAction::target($selectedEntry->target_type, $selectedEntry->target_label) }}</dd></div>
                        @endif
                        <div><dt>{{ __('sadmin_audit.fields.category') }}</dt><dd>{{ $translated('categories', $selectedEntry->category) }}</dd></div>
                        <div><dt>{{ __('sadmin_audit.fields.severity') }}</dt><dd><x-ui.status :tone="$severityTone[$selectedEntry->severity] ?? 'neutral'" :label="$translated('severities', $selectedEntry->severity)" /></dd></div>
                    </dl>
                    @if($selectedEntry->reason)
                        <div class="quote"><span class="muted">{{ __('sadmin_audit.fields.reason') }}</span><p class="prewrap">{{ $selectedEntry->reason }}</p></div>
                    @endif
                </section>

                <section class="drawer-section">
                    <h3>{{ __('sadmin_audit.detail.changes') }}</h3>
                    @if($fields === [])
                        <p class="muted">{{ __('sadmin_audit.detail.no_changes') }}</p>
                    @else
                        <div class="table-shell">
                            <table class="diff-table">
                                <thead><tr><th scope="col">{{ __('sadmin_audit.detail.field') }}</th><th scope="col">{{ __('sadmin_audit.detail.before') }}</th><th scope="col">{{ __('sadmin_audit.detail.after') }}</th></tr></thead>
                                <tbody>
                                    @foreach($fields as $field)
                                        @php
                                            $old = $before[$field] ?? null;
                                            $new = $after[$field] ?? null;
                                        @endphp
                                        <tr @if($old !== $new) data-changed @endif>
                                            <th scope="row">{{ \Illuminate\Support\Str::headline((string) $field) }}</th>
                                            <td><span class="diff-old" dir="auto">{{ $scalar($old) }}</span></td>
                                            <td><span class="diff-new" dir="auto">{{ $scalar($new) }}</span></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </section>

                @if($meta !== [])
                    <section class="drawer-section">
                        <h3>{{ __('sadmin_audit.detail.context') }}</h3>
                        <dl class="summary-list">
                            @foreach($meta as $key => $value)
                                <div><dt>{{ \Illuminate\Support\Str::headline((string) $key) }}</dt><dd dir="auto">{{ $scalar($value) }}</dd></div>
                            @endforeach
                        </dl>
                    </section>
                @endif

                <details class="disclosure">
                    <summary>{{ __('sadmin_audit.detail.developer') }}</summary>
                    <div class="disclosure__body stack stack--sm">
                        <dl class="summary-list">
                            <div><dt>{{ __('sadmin_audit.detail.source') }}</dt><dd class="mono" dir="ltr">{{ $selectedEntry->source ?: '—' }}</dd></div>
                            <div><dt>{{ __('sadmin_audit.detail.correlation') }}</dt><dd class="mono" dir="ltr">{{ $selectedEntry->correlation_id ?: '—' }}</dd></div>
                            @if($selectedEntry->target_id)<div><dt>{{ __('sadmin_audit.fields.target') }}</dt><dd class="mono" dir="ltr">{{ class_basename((string) $selectedEntry->target_type) }} · {{ $selectedEntry->target_id }}</dd></div>@endif
                            @if($selectedEntry->ip)<div><dt>{{ __('sadmin_audit.detail.ip') }}</dt><dd class="mono" dir="ltr">{{ $selectedEntry->ip }}</dd></div>@endif
                        </dl>
                        @foreach(['raw_before' => $selectedEntry->before, 'raw_after' => $selectedEntry->after, 'raw_meta' => $selectedEntry->meta] as $label => $raw)
                            @if(! empty($raw))
                                <p class="field-help">{{ __('sadmin_audit.detail.'.$label) }}</p>
                                <pre class="code-block" dir="ltr">{{ json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                            @endif
                        @endforeach
                    </div>
                </details>
            </div>
        </x-ui.drawer>
    @endif
</div>
