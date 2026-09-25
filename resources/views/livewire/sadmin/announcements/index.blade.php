<div class="stack">
    <x-ui.page-header :title="__('sadmin_notifications.title')">
        <x-slot:actions>
            <button class="button" type="button" wire:click="openPanel('compose')"><x-ui.icon name="megaphone" size="16" />{{ __('sadmin_notifications.compose') }}</button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.flash />

    <div class="table-shell table-shell--stack">
        @if($announcements->isEmpty())
            <x-ui.empty-state icon="megaphone" :title="__('sadmin_notifications.empty')">
                <button class="button button--sm" type="button" wire:click="openPanel('compose')">{{ __('sadmin_notifications.compose') }}</button>
            </x-ui.empty-state>
        @else
            <table>
                <caption class="sr-only">{{ __('sadmin_notifications.title') }}</caption>
                <thead><tr>
                    <th scope="col">{{ __('sadmin_notifications.fields.title') }}</th>
                    <th scope="col">{{ __('sadmin_notifications.fields.severity') }}</th>
                    <th scope="col">{{ __('sadmin_notifications.fields.audience') }}</th>
                    <th scope="col" class="numeric">{{ __('sadmin_notifications.fields.centers') }}</th>
                    <th scope="col">{{ __('sadmin_notifications.fields.sent') }}</th>
                    <th scope="col" class="actions"><span class="sr-only">{{ __('ui.actions.view') }}</span></th>
                </tr></thead>
                <tbody>
                    @foreach($announcements as $announcement)
                        <tr wire:key="ann-{{ $announcement->uuid }}">
                            <td data-label="{{ __('sadmin_notifications.fields.title') }}" data-primary>
                                <span class="cell-title">{{ $announcement->title->get() }}</span>
                                <span class="cell-sub clamp-2">{{ $announcement->body->get() }}</span>
                            </td>
                            <td data-label="{{ __('sadmin_notifications.fields.severity') }}">
                                <x-ui.status :value="$announcement->severity" :tone="$announcement->severity === 'important' ? 'warning' : 'info'" :label="__('sadmin_notifications.severity.'.$announcement->severity)" :dot="false" />
                            </td>
                            <td data-label="{{ __('sadmin_notifications.fields.audience') }}">{{ __('sadmin_notifications.audience.'.$announcement->audience) }}</td>
                            <td data-label="{{ __('sadmin_notifications.fields.centers') }}" class="numeric">{{ number_format($announcement->centers_count) }}</td>
                            <td data-label="{{ __('sadmin_notifications.fields.sent') }}">
                                <time datetime="{{ $announcement->sent_at?->toIso8601String() }}">{{ $announcement->sent_at?->translatedFormat('j M Y, H:i') }}</time>
                                <span class="cell-sub">{{ $announcement->created_by_label }}</span>
                            </td>
                            <td class="actions"><button class="button button--ghost button--sm" type="button" wire:click="openPanel('view:{{ $announcement->uuid }}')">{{ __('ui.actions.view') }}</button></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
    @if($announcements->hasPages())<div class="table-footer table-footer--bare">{{ $announcements->links() }}</div>@endif

    @if($panel === 'compose')
        <x-ui.drawer :title="__('sadmin_notifications.compose')" submit="review" size="lg">
            <x-ui.lang-tabs id="announcement" :fields="[
                ['name' => 'title', 'label' => __('sadmin_notifications.fields.title'), 'max' => 140, 'required' => true, 'counter' => true],
                ['name' => 'body', 'label' => __('sadmin_notifications.fields.body'), 'type' => 'textarea', 'rows' => 5, 'max' => 1000, 'required' => true, 'counter' => true],
            ]" :values="['title' => $title, 'body' => $body]" primary="en" />

            <fieldset class="field">
                <legend>{{ __('sadmin_notifications.fields.severity') }}</legend>
                <div class="segmented" role="radiogroup">
                    @foreach(['info', 'important'] as $option)
                        <label @class(['is-active' => $severity === $option])>
                            <input class="sr-only" type="radio" name="severity" value="{{ $option }}" wire:model.live="severity">{{ __('sadmin_notifications.severity.'.$option) }}
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <fieldset class="field">
                <legend>{{ __('sadmin_notifications.fields.audience') }}</legend>
                <div class="segmented" role="radiogroup">
                    @foreach(['all', 'selected', 'filtered'] as $option)
                        <label @class(['is-active' => $audience === $option])>
                            <input class="sr-only" type="radio" name="audience" value="{{ $option }}" wire:model.live="audience">{{ __('sadmin_notifications.audience.'.$option) }}
                        </label>
                    @endforeach
                </div>
                @error('audience')<p class="field-error" role="alert">{{ $message }}</p>@enderror
            </fieldset>

            @if($audience === 'selected')
                <div class="field">
                    <label for="ann-center-search">{{ __('sadmin_notifications.find_center') }}</label>
                    <div class="search-input"><x-ui.icon name="search" /><input id="ann-center-search" type="search" wire:model.live.debounce.300ms="centerSearch" autocomplete="off"></div>
                </div>
                <div class="check-list check-list--scroll" role="group" aria-label="{{ __('sadmin_notifications.fields.centers') }}">
                    @forelse($centers as $center)
                        <label class="choice" wire:key="ann-center-{{ $center->id }}">
                            <input type="checkbox" value="{{ $center->id }}" wire:model.live="tenantIds">
                            <span>{{ $center->name }}@if($center->status !== 'active')<small class="muted">{{ \App\View\Label::for('tenant_status', $center->status) }}</small>@endif</span>
                        </label>
                    @empty
                        <p class="muted">{{ __('sadmin_notifications.no_match') }}</p>
                    @endforelse
                </div>
                @error('tenantIds')<p class="field-error" role="alert">{{ $message }}</p>@enderror
            @elseif($audience === 'filtered')
                <div class="form-grid">
                    <x-ui.field :label="__('sadmin_notifications.fields.status')" for="ann-status" name="status">
                        <select id="ann-status" wire:model.live="status">
                            <option value="">{{ __('sadmin_notifications.any_status') }}</option>
                            <option value="active">{{ \App\View\Label::for('tenant_status', 'active') }}</option>
                            <option value="suspended">{{ \App\View\Label::for('tenant_status', 'suspended') }}</option>
                        </select>
                    </x-ui.field>
                    <x-ui.field :label="__('sadmin_notifications.fields.plan')" for="ann-plan" name="planId">
                        <select id="ann-plan" wire:model.live="planId">
                            <option value="">{{ __('sadmin_notifications.any_plan') }}</option>
                            @foreach($plans as $plan)<option value="{{ $plan->id }}">{{ $plan->name->get() }}</option>@endforeach
                        </select>
                    </x-ui.field>
                </div>
            @endif

            <p class="recipient-count" aria-live="polite"><x-ui.icon name="centers" size="16" />{{ trans_choice('sadmin_notifications.reaches', $recipients, ['count' => $recipients]) }}</p>

            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="closePanel">{{ __('ui.actions.cancel') }}</button>
                <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="review" @disabled($recipients === 0)>{{ __('sadmin_notifications.review') }}</button>
            </x-slot:footer>
        </x-ui.drawer>
    @elseif($panel === 'confirm')
        <x-ui.modal :title="__('sadmin_notifications.confirm_title')" :description="trans_choice('sadmin_notifications.confirm_body', $recipients, ['count' => $recipients])" icon="megaphone" submit="send" close="openPanel('compose')">
            <div class="announcement-preview" data-severity="{{ $severity }}">
                <strong>{{ $title[app()->getLocale()] ?: $title['en'] }}</strong>
                <p class="prewrap">{{ $body[app()->getLocale()] ?: $body['en'] }}</p>
            </div>
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="openPanel('compose')">{{ __('sadmin_notifications.back_to_edit') }}</button>
                <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="send"><x-ui.icon name="send" size="16" />{{ __('sadmin_notifications.send') }}</button>
            </x-slot:footer>
        </x-ui.modal>
    @elseif($viewing)
        <x-ui.modal :title="$viewing->title->get()" icon="megaphone">
            <p class="prewrap">{{ $viewing->body->get() }}</p>
            <dl class="summary-list">
                <div><dt>{{ __('sadmin_notifications.fields.audience') }}</dt><dd>{{ __('sadmin_notifications.audience.'.$viewing->audience) }}</dd></div>
                <div><dt>{{ __('sadmin_notifications.fields.centers') }}</dt><dd>{{ number_format($viewing->centers_count) }}</dd></div>
                <div><dt>{{ __('sadmin_notifications.fields.sent') }}</dt><dd>{{ $viewing->sent_at?->translatedFormat('j M Y, H:i') }} · {{ $viewing->created_by_label }}</dd></div>
            </dl>
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="closePanel">{{ __('ui.actions.close') }}</button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
