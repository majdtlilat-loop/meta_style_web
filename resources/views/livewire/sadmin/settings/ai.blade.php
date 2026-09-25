@php
    $date = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->translatedFormat('j M Y, H:i') : '—';
    $catalogState = fn (?bool $found) => match ($found) {
        true => ['tone' => 'success', 'label' => __('platform_settings.ai.catalog.found')],
        false => ['tone' => 'warning', 'label' => __('platform_settings.ai.catalog.not_found')],
        default => ['tone' => 'neutral', 'label' => __('platform_settings.ai.catalog.unknown')],
    };
@endphp

<div class="stack">
    <x-ui.page-header :title="__('platform_settings.title')" />

    <x-ui.flash />
    <x-ui.flash key="notice-error" tone="danger" />

    <div class="settings-layout">
        <x-sadmin.settings-nav current="ai" />

        <div class="settings-panel stack">
            <x-ui.card :title="__('platform_settings.ai.title')">
                <dl class="summary-list">
                    <div>
                        <dt>{{ __('platform_settings.ai.key') }}</dt>
                        <dd>
                            @if($status['source'] === 'platform')
                                <x-ui.status value="enabled" tone="success" :label="__('platform_settings.ai.configured')" />
                                <span class="mono" dir="ltr">••••{{ $status['hint'] }}</span>
                                <span class="cell-sub">{{ __('platform_settings.ai.set_by', ['name' => $status['configured_by'], 'date' => $date($status['configured_at'])]) }}</span>
                            @elseif($status['source'] === 'environment')
                                <x-ui.status value="enabled" tone="info" :label="__('platform_settings.ai.from_environment')" />
                            @else
                                <x-ui.status value="disabled" tone="warning" :label="__('platform_settings.ai.not_configured')" />
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt>{{ __('platform_settings.ai.connection') }}</dt>
                        <dd>
                            @if($status['tested_at'])
                                <x-ui.status :value="$status['test_ok'] ? 'enabled' : 'failed'" :tone="$status['test_ok'] ? 'success' : 'danger'" :label="__('platform_settings.ai.results.'.(in_array($status['test_message'], ['ok', 'rejected', 'unreachable', 'not_configured'], true) ? $status['test_message'] : 'error'))" />
                                <span class="cell-sub">{{ $date($status['tested_at']) }}</span>
                            @else
                                <span class="muted">{{ __('platform_settings.ai.never_tested') }}</span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt>{{ __('platform_settings.ai.current_model') }}</dt>
                        <dd><span class="mono" dir="ltr">{{ $saved['model'] }}</span></dd>
                    </div>
                </dl>
                <div class="cluster action-row">
                    @if($canSecurity)
                        <button class="button button--sm" type="button" wire:click="openPanel('key')"><x-ui.icon name="key" size="16" />{{ $status['source'] === 'platform' ? __('platform_settings.ai.replace') : __('platform_settings.ai.set') }}</button>
                        @if($status['source'] === 'platform')
                            <button class="button button--ghost button--sm button--danger-text" type="button" wire:click="openPanel('remove')">{{ __('platform_settings.ai.remove') }}</button>
                        @endif
                    @else
                        <span class="field-help">{{ __('platform_settings.ai.key_needs_permission') }}</span>
                    @endif
                    <button class="button button--secondary button--sm" type="button" wire:click="testConnection" wire:loading.attr="data-loading" wire:target="testConnection" @disabled($status['source'] === null)><x-ui.icon name="zap" size="16" />{{ __('platform_settings.ai.test') }}</button>
                </div>
            </x-ui.card>

            <form class="card card--flush" wire:submit="save">
                <header class="card__header">
                    <div>
                        <h2>{{ __('platform_settings.ai.usage') }}</h2>
                        <p>{{ __('platform_settings.ai.models_help') }}</p>
                    </div>
                </header>
                <div class="card__body stack">
                    <label class="choice choice--switch">
                        <input type="checkbox" role="switch" wire:model.live="enabled">
                        <span>{{ __('platform_settings.ai.enabled') }}<small class="field-help">{{ __('platform_settings.ai.enabled_help') }}</small></span>
                    </label>

                    <datalist id="ai-model-options">
                        @foreach($catalog['models'] as $option)<option value="{{ $option['id'] }}"></option>@endforeach
                    </datalist>

                    <div class="form-grid">
                        @php $chat = $catalogState($inCatalog['model']); @endphp
                        <x-ui.field :label="__('platform_settings.ai.chat_model')" for="ai-chat-model" name="model" required :help="__('platform_settings.ai.chat_model_help')">
                            <input id="ai-chat-model" type="text" class="mono" dir="ltr" list="ai-model-options" wire:model.live.debounce.400ms="model" maxlength="128" autocomplete="off" spellcheck="false" required>
                            <span class="cluster"><x-ui.status :value="$chat['tone']" :tone="$chat['tone']" :label="$chat['label']" /></span>
                        </x-ui.field>
                        <x-ui.field :label="__('platform_settings.ai.report_model')" for="ai-report-model" name="reportModel" :help="__('platform_settings.ai.report_model_help')">
                            <input id="ai-report-model" type="text" class="mono" dir="ltr" list="ai-model-options" wire:model.live.debounce.400ms="reportModel" maxlength="128" autocomplete="off" spellcheck="false" placeholder="{{ __('platform_settings.ai.same_as_chat') }}">
                            @if($reportModel !== '')
                                @php $report = $catalogState($inCatalog['reportModel']); @endphp
                                <span class="cluster"><x-ui.status :value="$report['tone']" :tone="$report['tone']" :label="$report['label']" /></span>
                            @endif
                        </x-ui.field>
                    </div>
                    <p class="field-help">{{ __('platform_settings.ai.no_prices') }}</p>
                </div>
                <footer class="card__footer">
                    @if($dirty)<span class="field-help">{{ __('platform_settings.ai.unsaved') }}</span>@endif
                    <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="save">{{ __('platform_settings.save') }}</button>
                </footer>
            </form>

            <section class="card card--flush" aria-labelledby="ai-catalog">
                <header class="card__header">
                    <div>
                        <h2 id="ai-catalog">{{ __('platform_settings.ai.catalog.title') }}</h2>
                        <p>
                            @if($catalog['fetched_at'])
                                {{ __('platform_settings.ai.catalog.fetched', ['date' => $date($catalog['fetched_at']), 'count' => count($catalog['models'])]) }}
                            @else
                                {{ __('platform_settings.ai.catalog.never') }}
                            @endif
                        </p>
                    </div>
                    <button class="button button--secondary button--sm" type="button" wire:click="refreshModels" wire:loading.attr="data-loading" wire:target="refreshModels" @disabled($status['source'] === null)>
                        <x-ui.icon name="refresh" size="16" />{{ __('platform_settings.ai.catalog.refresh') }}
                    </button>
                </header>
                @if($catalog['models'] !== [])
                    <div class="card__body">
                        <div class="field">
                            <label for="ai-catalog-search" class="sr-only">{{ __('platform_settings.ai.catalog.search') }}</label>
                            <div class="search-input">
                                <x-ui.icon name="search" />
                                <input id="ai-catalog-search" type="search" dir="ltr" wire:model.live.debounce.250ms="search" placeholder="{{ __('platform_settings.ai.catalog.search') }}" autocomplete="off">
                            </div>
                        </div>
                    </div>
                    <div class="table-shell table-shell--stack">
                        <table>
                            <thead><tr>
                                <th scope="col">{{ __('platform_settings.ai.catalog.model') }}</th>
                                <th scope="col">{{ __('platform_settings.ai.catalog.owner') }}</th>
                                <th scope="col">{{ __('platform_settings.ai.catalog.created') }}</th>
                                <th scope="col" class="actions"><span class="sr-only">{{ __('ui.table.actions') }}</span></th>
                            </tr></thead>
                            <tbody>
                                @forelse($models as $option)
                                    <tr wire:key="model-{{ $option['id'] }}">
                                        <td data-label="{{ __('platform_settings.ai.catalog.model') }}" data-primary>
                                            <span class="cell-title mono" dir="ltr">{{ $option['id'] }}</span>
                                            @if($option['id'] === $saved['model'])<x-ui.status tone="success" :dot="false" :label="__('platform_settings.ai.catalog.in_use_chat')" />@endif
                                            @if($option['id'] === $saved['report_model'])<x-ui.status tone="info" :dot="false" :label="__('platform_settings.ai.catalog.in_use_reports')" />@endif
                                        </td>
                                        <td data-label="{{ __('platform_settings.ai.catalog.owner') }}"><span dir="ltr">{{ $option['owned_by'] ?? '—' }}</span></td>
                                        <td data-label="{{ __('platform_settings.ai.catalog.created') }}">{{ $option['created'] ? \Illuminate\Support\Carbon::createFromTimestamp($option['created'])->translatedFormat('j M Y') : '—' }}</td>
                                        <td class="actions">
                                            <button class="button button--ghost button--sm" type="button" wire:click="useModel(@js($option['id']), 'chat')">{{ __('platform_settings.ai.catalog.use_chat') }}</button>
                                            <button class="button button--ghost button--sm" type="button" wire:click="useModel(@js($option['id']), 'reports')">{{ __('platform_settings.ai.catalog.use_reports') }}</button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="muted center">{{ __('platform_settings.ai.catalog.no_match') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    @if($matches > count($models))
                        <footer class="card__footer"><span class="field-help">{{ __('platform_settings.ai.catalog.more', ['shown' => count($models), 'total' => $matches]) }}</span></footer>
                    @endif
                @else
                    <div class="card__body"><p class="muted">{{ __('platform_settings.ai.catalog.empty') }}</p></div>
                @endif
            </section>
        </div>
    </div>

    @if($panel === 'key')
        <x-ui.modal :title="__('platform_settings.ai.key_title')" :description="__('platform_settings.ai.key_body')" icon="key" submit="saveApiKey">
            <x-ui.field :label="__('platform_settings.ai.key')" for="api-key" name="apiKey" required>
                <input id="api-key" type="password" dir="ltr" class="mono" wire:model="apiKey" autocomplete="off" spellcheck="false" required>
            </x-ui.field>
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="closePanel">{{ __('ui.actions.cancel') }}</button>
                <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="saveApiKey">{{ __('platform_settings.ai.save_key') }}</button>
            </x-slot:footer>
        </x-ui.modal>
    @elseif($panel === 'remove')
        <x-ui.modal :title="__('platform_settings.ai.remove_title')" :description="__('platform_settings.ai.remove_body')" icon="key" tone="danger" submit="removeApiKey">
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="closePanel">{{ __('ui.actions.cancel') }}</button>
                <button class="button button--danger" type="submit" wire:loading.attr="data-loading" wire:target="removeApiKey">{{ __('platform_settings.ai.remove') }}</button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
