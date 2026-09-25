@php
    $date = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->translatedFormat('j M Y') : '—';
@endphp

<div class="stack">
    <x-ui.page-header :title="__('sadmin_entitlements.title')">
        <x-slot:actions>
            <button class="button" type="button" wire:click="openPanel('create')"><x-ui.icon name="plus" size="16" />{{ __('sadmin_entitlements.add') }}</button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.flash />

    <div class="segmented" role="group" aria-label="{{ __('sadmin_entitlements.title') }}">
        <button type="button" wire:click="$set('view', 'overrides')" aria-pressed="{{ $view === 'overrides' ? 'true' : 'false' }}">{{ __('sadmin_entitlements.views.overrides') }}</button>
        <button type="button" wire:click="$set('view', 'catalog')" aria-pressed="{{ $view === 'catalog' ? 'true' : 'false' }}">{{ __('sadmin_entitlements.views.catalog') }}</button>
    </div>

    @if($view === 'catalog')
        <div class="table-shell table-scroll">
            <table class="matrix">
                <caption class="sr-only">{{ __('sadmin_entitlements.views.catalog') }}</caption>
                <thead><tr>
                    <th scope="col">{{ __('sadmin_entitlements.fields.capability') }}</th>
                    @foreach($plans as $plan)<th scope="col" class="center">{{ $plan->name->get() }}@unless($plan->is_active)<span class="cell-sub">{{ __('sadmin_entitlements.archived_plan') }}</span>@endunless</th>@endforeach
                    <th scope="col" class="numeric">{{ __('sadmin_entitlements.granted') }}</th>
                    <th scope="col" class="numeric">{{ __('sadmin_entitlements.revoked') }}</th>
                </tr></thead>
                <tbody>
                    @foreach($catalog as $category => $keys)
                        <tr class="matrix__group"><th scope="rowgroup" colspan="{{ $plans->count() + 3 }}">{{ __('platform_labels.entitlement_category.'.$category) }}</th></tr>
                        @foreach($keys as $key)
                            <tr wire:key="catalog-{{ $key }}">
                                <th scope="row">{{ __('platform_labels.entitlement.'.$key) }}</th>
                                @foreach($plans as $plan)
                                    <td class="center">
                                        @if(in_array($plan->id, $inPlans[$key] ?? [], true))
                                            <x-ui.icon name="check" class="text-success" /><span class="sr-only">{{ __('sadmin_entitlements.included') }}</span>
                                        @else
                                            <span class="muted" aria-hidden="true">—</span><span class="sr-only">{{ __('sadmin_entitlements.not_included') }}</span>
                                        @endif
                                    </td>
                                @endforeach
                                <td class="numeric"><button class="text-button" type="button" wire:click="$set('capability', '{{ $key }}'); $set('mode', 'grant'); $set('view', 'overrides')">{{ $overrideCounts[$key]['grant'] ?? 0 }}</button></td>
                                <td class="numeric"><button class="text-button" type="button" wire:click="$set('capability', '{{ $key }}'); $set('mode', 'revoke'); $set('view', 'overrides')">{{ $overrideCounts[$key]['revoke'] ?? 0 }}</button></td>
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <form class="filter-bar" role="search" x-on:submit.prevent>
            <div class="field">
                <label for="ent-center">{{ __('sadmin_entitlements.fields.center') }}</label>
                <select id="ent-center" wire:model.live="center">
                    <option value="">{{ __('sadmin_entitlements.any_center') }}</option>
                    @foreach($centers as $option)<option value="{{ $option->id }}">{{ $option->name }}</option>@endforeach
                </select>
            </div>
            <div class="field">
                <label for="ent-capability">{{ __('sadmin_entitlements.fields.capability') }}</label>
                <select id="ent-capability" wire:model.live="capability">
                    <option value="">{{ __('sadmin_entitlements.any_capability') }}</option>
                    @foreach($keys as $key)<option value="{{ $key }}">{{ __('platform_labels.entitlement.'.$key) }}</option>@endforeach
                </select>
            </div>
            <div class="field">
                <label for="ent-mode">{{ __('sadmin_entitlements.fields.mode') }}</label>
                <select id="ent-mode" wire:model.live="mode">
                    <option value="">{{ __('sadmin_entitlements.any_mode') }}</option>
                    <option value="grant">{{ __('sadmin_entitlements.granted') }}</option>
                    <option value="revoke">{{ __('sadmin_entitlements.revoked') }}</option>
                </select>
            </div>
        </form>

        <div class="table-shell table-shell--stack" wire:loading.class="is-refreshing">
            @if($overrides->isEmpty())
                <x-ui.empty-state icon="entitlements" :title="__('sadmin_entitlements.empty')" />
            @else
                <table>
                    <caption class="sr-only">{{ __('sadmin_entitlements.views.overrides') }}</caption>
                    <thead><tr>
                        <th scope="col">{{ __('sadmin_entitlements.fields.center') }}</th>
                        <th scope="col">{{ __('sadmin_entitlements.fields.capability') }}</th>
                        <th scope="col">{{ __('sadmin_entitlements.fields.mode') }}</th>
                        <th scope="col">{{ __('sadmin_entitlements.fields.expires') }}</th>
                        <th scope="col">{{ __('sadmin_entitlements.fields.reason') }}</th>
                        <th scope="col" class="actions"><span class="sr-only">{{ __('sadmin_entitlements.actions') }}</span></th>
                    </tr></thead>
                    <tbody>
                        @foreach($overrides as $override)
                            @php $expired = $override->expires_at?->isPast(); @endphp
                            <tr wire:key="override-{{ $override->id }}">
                                <td data-label="{{ __('sadmin_entitlements.fields.center') }}" data-primary>
                                    <a class="cell-title" href="{{ route('superadmin.centers.show', ['tenant' => $override->tenant_id, 'tab' => 'entitlements']) }}" wire:navigate>{{ $centerNames[$override->tenant_id] ?? '—' }}</a>
                                    <span class="cell-sub">{{ $override->updated_at?->diffForHumans() }}</span>
                                </td>
                                <td data-label="{{ __('sadmin_entitlements.fields.capability') }}">{{ __('platform_labels.entitlement.'.$override->entitlement) }}</td>
                                <td data-label="{{ __('sadmin_entitlements.fields.mode') }}">
                                    <x-ui.status :value="$override->mode->value === 'grant' ? 'active' : 'suspended'" :tone="$override->mode->value === 'grant' ? 'success' : 'danger'" :label="$override->mode->value === 'grant' ? __('sadmin_entitlements.granted') : __('sadmin_entitlements.revoked')" :dot="false" />
                                </td>
                                <td data-label="{{ __('sadmin_entitlements.fields.expires') }}">
                                    {{ $override->expires_at ? $date($override->expires_at) : __('sadmin_entitlements.no_expiry') }}
                                    @if($expired)<span class="cell-sub text-danger">{{ __('sadmin_entitlements.expired') }}</span>@endif
                                </td>
                                <td data-label="{{ __('sadmin_entitlements.fields.reason') }}"><span class="clamp-2">{{ $override->reason }}</span></td>
                                <td class="actions">
                                    <button class="button button--ghost button--sm" type="button" wire:click="openPanel('reset:{{ $override->id }}')">{{ __('sadmin_entitlements.reset') }}</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
        @if($overrides->hasPages())<div class="table-footer table-footer--bare">{{ $overrides->links() }}</div>@endif
    @endif

    @if($panel === 'create')
        <x-ui.modal :title="__('sadmin_entitlements.add')" icon="entitlements" submit="save">
            <x-ui.field :label="__('sadmin_entitlements.fields.center')" for="ov-center" name="tenantId" required>
                <select id="ov-center" wire:model="tenantId" required>
                    <option value="">{{ __('sadmin_entitlements.choose_center') }}</option>
                    @foreach($centers as $option)<option value="{{ $option->id }}">{{ $option->name }}</option>@endforeach
                </select>
            </x-ui.field>
            <x-ui.field :label="__('sadmin_entitlements.fields.capability')" for="ov-capability" name="entitlement" required>
                <select id="ov-capability" wire:model="entitlement" required>
                    <option value="">{{ __('sadmin_entitlements.choose_capability') }}</option>
                    @foreach($catalog as $category => $keys)
                        <optgroup label="{{ __('platform_labels.entitlement_category.'.$category) }}">
                            @foreach($keys as $key)<option value="{{ $key }}">{{ __('platform_labels.entitlement.'.$key) }}</option>@endforeach
                        </optgroup>
                    @endforeach
                </select>
            </x-ui.field>
            <fieldset class="field">
                <legend>{{ __('sadmin_entitlements.fields.mode') }}</legend>
                <div class="segmented" role="radiogroup">
                    @foreach(['grant', 'revoke'] as $option)
                        <label @class(['is-active' => $overrideMode === $option])>
                            <input class="sr-only" type="radio" name="ov-mode" value="{{ $option }}" wire:model.live="overrideMode">{{ $option === 'grant' ? __('sadmin_entitlements.grant') : __('sadmin_entitlements.revoke') }}
                        </label>
                    @endforeach
                </div>
            </fieldset>
            <x-ui.field :label="__('sadmin_entitlements.fields.expires')" for="ov-expiry" name="expiresAt">
                <input id="ov-expiry" type="datetime-local" wire:model="expiresAt" min="{{ now()->format('Y-m-d\TH:i') }}">
            </x-ui.field>
            <x-ui.field :label="__('sadmin_entitlements.fields.reason')" for="ov-reason" name="reason" required>
                <textarea id="ov-reason" wire:model="reason" rows="2" required></textarea>
            </x-ui.field>
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="closePanel">{{ __('ui.actions.cancel') }}</button>
                <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="save">{{ __('ui.actions.save') }}</button>
            </x-slot:footer>
        </x-ui.modal>
    @elseif($target)
        <x-ui.modal :title="__('sadmin_entitlements.reset_title', ['capability' => __('platform_labels.entitlement.'.$target->entitlement)])" :description="__('sadmin_entitlements.reset_body')" icon="reset" tone="warning" submit="clearOverride">
            <x-ui.field :label="__('sadmin_entitlements.fields.reason')" for="reset-reason" name="reason" required>
                <textarea id="reset-reason" wire:model="reason" rows="2" required></textarea>
            </x-ui.field>
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="closePanel">{{ __('ui.actions.cancel') }}</button>
                <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="clearOverride">{{ __('sadmin_entitlements.reset') }}</button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
