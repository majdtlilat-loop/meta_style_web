@php
    use App\View\Label;

    $checkTone = ['ok' => 'success', 'warning' => 'warning', 'failure' => 'danger'];
    $checkIcon = ['ok' => 'check-circle', 'warning' => 'alert-triangle', 'failure' => 'x-circle'];
    $healthTone = ['healthy' => 'success', 'attention' => 'warning', 'critical' => 'danger'];
    $dateTime = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->translatedFormat('j M Y, H:i') : '—';
    $duration = function ($start, $end): string {
        if (! $start || ! $end) {
            return '—';
        }
        $seconds = max(0, \Illuminate\Support\Carbon::parse($start)->diffInSeconds(\Illuminate\Support\Carbon::parse($end)));

        return $seconds < 60 ? $seconds.'s' : intdiv((int) $seconds, 60).'m '.((int) $seconds % 60).'s';
    };
@endphp

<div class="stack">
    <x-ui.page-header :title="__('sadmin_operations.title')">
        <x-slot:meta>
            <x-ui.status tone="neutral" :label="__('sadmin_operations.environment', ['env' => $environment])" :dot="false" />
        </x-slot:meta>
        @if($canManage)
            <x-slot:actions>
                <button class="button button--secondary" type="button" wire:click="refresh" wire:loading.attr="data-loading" wire:target="refresh"><x-ui.icon name="refresh" size="16" />{{ __('sadmin_operations.refresh') }}</button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    <x-ui.flash />

    <x-ui.card :title="__('sadmin_operations.readiness.title')" flush>
        <x-slot:actions>
            @foreach(['failure', 'warning', 'ok'] as $status)
                @if(($checkCounts[$status] ?? 0) > 0)
                    <x-ui.status :tone="$checkTone[$status]" :label="($checkCounts[$status]).' · '.__('sadmin_operations.statuses.'.$status)" :dot="false" />
                @endif
            @endforeach
        </x-slot:actions>
        <ul class="check-list">
            @foreach($checks as $check)
                @php $key = \Illuminate\Support\Str::slug($check->name, '_'); @endphp
                <li class="check-list__item" data-tone="{{ $checkTone[$check->status] ?? 'neutral' }}">
                    <span class="check-list__icon" aria-hidden="true"><x-ui.icon :name="$checkIcon[$check->status] ?? 'info'" /></span>
                    <div class="check-list__body">
                        <div class="check-list__head">
                            <strong>{{ \Illuminate\Support\Facades\Lang::has('sadmin_operations.readiness.checks.'.$key.'.name') ? __('sadmin_operations.readiness.checks.'.$key.'.name') : \Illuminate\Support\Str::headline($check->name) }}</strong>
                            <x-ui.status :tone="$checkTone[$check->status] ?? 'neutral'" :label="__('sadmin_operations.statuses.'.$check->status)" />
                        </div>
                        @if(\Illuminate\Support\Facades\Lang::has('sadmin_operations.readiness.checks.'.$key.'.detail'))
                            <p>{{ __('sadmin_operations.readiness.checks.'.$key.'.detail') }}</p>
                        @endif
                        @if(! $check->isOk())
                            <details class="disclosure">
                                <summary>{{ __('sadmin_operations.readiness.technical_details') }}</summary>
                                <p class="mono" dir="ltr">{{ $check->detail }}</p>
                                @if($check->remedy)
                                    <p><strong>{{ __('sadmin_operations.readiness.recommended_action') }}</strong></p>
                                    <p class="mono" dir="ltr">{{ $check->remedy }}</p>
                                @endif
                            </details>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
        <x-slot:footer><span class="field-help">{{ __('sadmin_operations.readiness.note') }}</span></x-slot:footer>
    </x-ui.card>

    <x-ui.card :title="__('sadmin_operations.projections.title')" flush>
        @if($projections->isEmpty())
            <x-ui.empty-state compact icon="operations" :title="__('sadmin_operations.projections.empty')">
                @if($canManage)<button class="button button--secondary button--sm" type="button" wire:click="refresh">{{ __('sadmin_operations.refresh') }}</button>@endif
            </x-ui.empty-state>
        @else
            <div class="table-shell table-shell--stack">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('sadmin_operations.projections.center') }}</th>
                            <th scope="col">{{ __('sadmin_operations.projections.health') }}</th>
                            <th scope="col">{{ __('sadmin_operations.projections.provisioning') }}</th>
                            <th scope="col">{{ __('sadmin_operations.projections.migration') }}</th>
                            <th scope="col" class="numeric">{{ __('sadmin_operations.projections.support') }}</th>
                            <th scope="col" class="numeric">{{ __('sadmin_operations.projections.alerts') }}</th>
                            <th scope="col">{{ __('sadmin_operations.projections.projected') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($projections as $projection)
                            <tr wire:key="projection-{{ $projection->id }}">
                                <td data-label="{{ __('sadmin_operations.projections.center') }}" data-primary>
                                    <a class="cell-title" href="{{ route('superadmin.centers.show', $projection->tenant_id) }}" wire:navigate>{{ $centerNames[$projection->tenant_id] ?? __('sadmin_operations.projections.unknown_center') }}</a>
                                </td>
                                <td data-label="{{ __('sadmin_operations.projections.health') }}"><x-ui.status :tone="$healthTone[$projection->health] ?? 'neutral'" :label="__('sadmin_operations.statuses.'.$projection->health)" /></td>
                                <td data-label="{{ __('sadmin_operations.projections.provisioning') }}"><x-ui.status :value="$projection->provisioning_status" :label="Label::for('provisioning_status', $projection->provisioning_status)" /></td>
                                <td data-label="{{ __('sadmin_operations.projections.migration') }}"><x-ui.status :value="$projection->migration_status" :label="Label::for('provisioning_status', $projection->migration_status)" /></td>
                                <td data-label="{{ __('sadmin_operations.projections.support') }}" class="numeric">{{ number_format((int) $projection->open_support_tickets) }}</td>
                                <td data-label="{{ __('sadmin_operations.projections.alerts') }}" class="numeric">{{ number_format((int) $projection->active_alerts) }}</td>
                                <td data-label="{{ __('sadmin_operations.projections.projected') }}"><time datetime="{{ $projection->projected_at?->toIso8601String() }}" title="{{ $dateTime($projection->projected_at) }}">{{ $projection->projected_at?->diffForHumans() }}</time></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>
    {{ $projections->links() }}

    <x-ui.card :title="__('sadmin_operations.operations.title')" flush>
        @if($operations->isEmpty())
            <x-ui.empty-state compact icon="server" :title="__('sadmin_operations.operations.empty')" />
        @else
            <div class="table-shell table-shell--stack">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('sadmin_operations.operations.center') }}</th>
                            <th scope="col">{{ __('sadmin_operations.operations.type') }}</th>
                            <th scope="col">{{ __('sadmin_operations.operations.status') }}</th>
                            <th scope="col" class="numeric">{{ __('sadmin_operations.operations.attempt') }}</th>
                            <th scope="col">{{ __('sadmin_operations.operations.started') }}</th>
                            <th scope="col" class="numeric">{{ __('sadmin_operations.operations.duration') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($operations as $operation)
                            <tr>
                                <td data-label="{{ __('sadmin_operations.operations.center') }}" data-primary><span class="cell-title">{{ $centerNames[$operation->tenant_id] ?? __('sadmin_operations.projections.unknown_center') }}</span></td>
                                <td data-label="{{ __('sadmin_operations.operations.type') }}">{{ \Illuminate\Support\Facades\Lang::has('sadmin_operations.types.'.$operation->type) ? __('sadmin_operations.types.'.$operation->type) : \Illuminate\Support\Str::headline($operation->type) }}</td>
                                <td data-label="{{ __('sadmin_operations.operations.status') }}"><x-ui.status :value="$operation->status" :label="\Illuminate\Support\Facades\Lang::has('sadmin_operations.statuses.'.$operation->status) ? __('sadmin_operations.statuses.'.$operation->status) : \Illuminate\Support\Str::headline($operation->status)" /></td>
                                <td data-label="{{ __('sadmin_operations.operations.attempt') }}" class="numeric">{{ $operation->attempt }}</td>
                                <td data-label="{{ __('sadmin_operations.operations.started') }}">{{ $dateTime($operation->started_at) }}</td>
                                <td data-label="{{ __('sadmin_operations.operations.duration') }}" class="numeric" dir="ltr">{{ $duration($operation->started_at, $operation->finished_at) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>
</div>
