@php
    use App\View\Label;

    $format = fn (?int $minor, string $currency) => $minor === null ? null : $money->format($minor, $currency, app()->getLocale());
    $limitText = function ($plan, string $resource) use ($planLimits, $usageCatalog): string {
        $row = ($planLimits[$plan->id] ?? collect())->get($resource);
        if ($row === null) {
            $default = $usageCatalog->systemDefault($resource);

            return $default === null ? __('sadmin_plans.limits.unlimited') : number_format($default).' · '.__('sadmin_plans.limits.default_short');
        }

        return $row->allowance === null ? __('sadmin_plans.limits.unlimited') : number_format($row->allowance);
    };
@endphp

<div class="stack">
    <x-ui.page-header :title="__('sadmin_plans.title')">
        <x-slot:actions>
            <button class="button" type="button" wire:click="create"><x-ui.icon name="plus" size="16" />{{ __('sadmin_plans.create') }}</button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.flash />

    <div class="segmented" role="group" aria-label="{{ __('sadmin_plans.title') }}">
        <button type="button" wire:click="$set('view', 'plans')" aria-pressed="{{ $view === 'plans' ? 'true' : 'false' }}"><x-ui.icon name="grid" size="16" />{{ __('sadmin_plans.views.plans') }}</button>
        <button type="button" wire:click="$set('view', 'compare')" aria-pressed="{{ $view === 'compare' ? 'true' : 'false' }}"><x-ui.icon name="list" size="16" />{{ __('sadmin_plans.views.compare') }}</button>
    </div>

    @if($plans->isEmpty())
        <x-ui.card>
            <x-ui.empty-state icon="plans" :title="__('sadmin_plans.empty')">
                <button class="button" type="button" wire:click="create">{{ __('sadmin_plans.create') }}</button>
            </x-ui.empty-state>
        </x-ui.card>
    @elseif($view === 'compare')
        <div class="table-shell table-scroll">
            <table class="matrix">
                <caption class="sr-only">{{ __('sadmin_plans.views.compare') }}</caption>
                <thead>
                    <tr>
                        <th scope="col">{{ __('sadmin_plans.compare.feature') }}</th>
                        @foreach($plans as $plan)
                            <th scope="col" class="center">
                                {{ $plan->name->get() }}
                                @unless($plan->is_active)<span class="cell-sub">{{ __('sadmin_plans.archived_status') }}</span>@endunless
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    <tr class="matrix__group"><th scope="rowgroup" colspan="{{ $plans->count() + 1 }}">{{ __('sadmin_plans.compare.pricing') }}</th></tr>
                    @foreach(['monthly', 'yearly'] as $cycle)
                        <tr>
                            <th scope="row">{{ Label::for('billing_period', $cycle) }}</th>
                            @foreach($plans as $plan)
                                <td class="center" dir="ltr">{{ $format($plan->priceFor($cycle), $plan->currency) ?? '—' }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                    <tr>
                        <th scope="row">{{ __('sadmin_plans.fields.trial_days') }}</th>
                        @foreach($plans as $plan)<td class="center">{{ $plan->trial_days ?? '—' }}</td>@endforeach
                    </tr>
                    @if($enforced !== [])
                        <tr class="matrix__group"><th scope="rowgroup" colspan="{{ $plans->count() + 1 }}">{{ __('sadmin_plans.sections.limits') }}</th></tr>
                        @foreach($enforced as $resource)
                            <tr>
                                <th scope="row">{{ Label::for('usage_resource', $resource) }}</th>
                                @foreach($plans as $plan)<td class="center">{{ $limitText($plan, $resource) }}</td>@endforeach
                            </tr>
                        @endforeach
                    @endif
                    @foreach($grouped as $category => $keys)
                        <tr class="matrix__group"><th scope="rowgroup" colspan="{{ $plans->count() + 1 }}">{{ __('platform_labels.entitlement_category.'.$category) }}</th></tr>
                        @foreach($keys as $key)
                            <tr>
                                <th scope="row">{{ __('platform_labels.entitlement.'.$key) }}</th>
                                @foreach($plans as $plan)
                                    <td class="center">
                                        @if($plan->entitlements->contains('entitlement', $key))
                                            <x-ui.icon name="check" class="text-success" /><span class="sr-only">{{ __('sadmin_plans.compare.included') }}</span>
                                        @else
                                            <span class="muted" aria-hidden="true">—</span><span class="sr-only">{{ __('sadmin_plans.compare.not_included') }}</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <div class="plan-grid">
            @foreach($plans as $plan)
                @php
                    $codes = $plan->entitlements->pluck('entitlement')->all();
                    $live = (int) ($subscribers[$plan->id] ?? 0);
                    $saving = $plan->yearlySavingPercent();
                @endphp
                <article class="plan-card" wire:key="plan-{{ $plan->id }}" @if(! $plan->is_active) data-archived @endif @if($plan->is_featured) data-featured @endif>
                    <header class="plan-card__head">
                        <div>
                            <h2>{{ $plan->name->get() }}</h2>
                            <span class="cell-sub" dir="ltr">{{ $plan->code }} · {{ $plan->currency }}</span>
                        </div>
                        <div class="cluster cluster--tight">
                            @if($plan->is_featured)<span class="badge" data-tone="primary">{{ __('sadmin_plans.featured') }}</span>@endif
                            @if($defaultPlan === $plan->code)<x-ui.status value="info" :label="__('sadmin_plans.default')" :dot="false" />@endif
                            <x-ui.status :value="$plan->is_active ? 'active' : 'archived'" :label="$plan->is_active ? __('sadmin_plans.active') : __('sadmin_plans.archived_status')" />
                        </div>
                    </header>

                    <dl class="plan-card__prices">
                        @foreach(['monthly', 'yearly'] as $cycle)
                            <div @unless($plan->offers($cycle)) class="muted" @endunless>
                                <dt>{{ Label::for('billing_period', $cycle) }}</dt>
                                <dd dir="ltr" class="tabular">{{ $format($plan->priceFor($cycle), $plan->currency) ?? __('sadmin_plans.not_offered') }}</dd>
                            </div>
                        @endforeach
                    </dl>
                    @if($saving)<p class="plan-card__saving"><x-ui.icon name="percent" size="16" />{{ __('sadmin_plans.saving', ['percent' => $saving]) }}</p>@endif
                    @if($plan->description?->get())
                        <p class="plan-card__description">{{ $plan->description->get() }}</p>
                    @endif

                    <ul class="plan-card__facts">
                        <li><x-ui.icon name="users" size="16" />{{ trans_choice('sadmin_plans.subscribers', $live, ['count' => number_format($live)]) }}
                            @if($live > 0)<span class="muted">({{ collect($cycles[$plan->id] ?? [])->map(fn ($count, $cycle) => Label::for('billing_period', (string) $cycle).' '.$count)->join(' · ') }})</span>@endif
                        </li>
                        <li><x-ui.icon name="clock" size="16" />{{ trans_choice('sadmin_plans.trial', (int) $plan->trial_days, ['count' => (int) $plan->trial_days]) }}</li>
                        <li><x-ui.icon :name="$plan->is_public ? 'eye' : 'eye-off'" size="16" />{{ $plan->is_public ? __('sadmin_plans.public') : __('sadmin_plans.hidden') }}</li>
                    </ul>

                    <div class="plan-card__capabilities">
                        <span class="cell-sub">{{ trans_choice('sadmin_plans.capabilities', count($codes), ['count' => count($codes)]) }}</span>
                        <div class="chip-list">
                            @foreach(array_slice($codes, 0, 6) as $code)
                                <span class="chip">{{ __('platform_labels.entitlement.'.$code) }}</span>
                            @endforeach
                            @if(count($codes) > 6)<span class="chip chip--muted">{{ __('sadmin_plans.more', ['count' => count($codes) - 6]) }}</span>@endif
                        </div>
                    </div>

                    <footer class="plan-card__foot">
                        <button class="button button--secondary button--sm" type="button" wire:click="edit({{ $plan->id }})"><x-ui.icon name="edit" size="16" />{{ __('ui.actions.edit') }}</button>
                        <button class="button button--ghost button--sm" type="button" wire:click="duplicate({{ $plan->id }})"><x-ui.icon name="copy" size="16" />{{ __('sadmin_plans.duplicate') }}</button>
                    </footer>
                </article>
            @endforeach
        </div>
    @endif

    @if($editorOpen)
        <x-ui.drawer size="lg" close="closeEditor" submit="save" :title="$editing ? __('sadmin_plans.edit_title', ['name' => $editing->name->get()]) : __('sadmin_plans.create_title')">
            <section class="drawer-section">
                <h3>{{ __('sadmin_plans.sections.identity') }}</h3>
                <x-ui.field :label="__('sadmin_plans.fields.code')" for="plan-code" name="code" required :help="$editing ? null : __('sadmin_plans.fields.code_help')">
                    <input id="plan-code" type="text" dir="ltr" class="mono" wire:model="code" maxlength="64" autocomplete="off" @readonly($editing) required>
                </x-ui.field>
                <x-ui.lang-tabs id="plan-text" :fields="[
                    ['name' => 'name', 'label' => __('sadmin_plans.fields.name'), 'max' => 190, 'required' => true],
                    ['name' => 'description', 'label' => __('sadmin_plans.fields.description'), 'type' => 'textarea', 'rows' => 3, 'max' => 1000, 'counter' => true],
                ]" :values="['name' => $name, 'description' => $description]" primary="en" />
            </section>

            <section class="drawer-section">
                <h3>{{ __('sadmin_plans.sections.pricing') }}</h3>
                <x-ui.field :label="__('sadmin_plans.fields.currency')" for="plan-currency" name="currency" required :help="$editing ? __('sadmin_plans.fields.currency_help') : null">
                    <select id="plan-currency" wire:model.live="currency" required>
                        @foreach(array_unique([...$currencies, $currency]) as $code)<option value="{{ $code }}">{{ $code }}</option>@endforeach
                    </select>
                </x-ui.field>
                <div class="price-cycles">
                    @foreach(['monthly' => 'offersMonthly', 'yearly' => 'offersYearly'] as $cycle => $toggle)
                        <div class="price-cycle" wire:key="cycle-{{ $cycle }}">
                            <label class="choice choice--switch">
                                <input type="checkbox" role="switch" wire:model.live="{{ $toggle }}">
                                <span>{{ $cycle === 'monthly' ? __('sadmin_plans.fields.offer_monthly') : __('sadmin_plans.fields.offer_yearly') }}</span>
                            </label>
                            @if($this->{$toggle})
                                <x-ui.field :label="__('sadmin_plans.fields.'.$cycle.'_price')" for="price-{{ $cycle }}" name="prices.{{ $cycle }}" required>
                                    <div class="input-affix">
                                        <input id="price-{{ $cycle }}" type="text" dir="ltr" inputmode="decimal" wire:model.live.debounce.400ms="prices.{{ $cycle }}" autocomplete="off" required>
                                        <span class="input-affix__suffix" dir="ltr">{{ $currency }}</span>
                                    </div>
                                </x-ui.field>
                                @error('minor.'.$cycle)<p class="field-error" role="alert">{{ $message }}</p>@enderror
                            @endif
                        </div>
                    @endforeach
                </div>
                @php
                    $m = $offersMonthly ? $minor['monthly'] : null;
                    $y = $offersYearly ? $minor['yearly'] : null;
                    $full = $m !== null ? $m * 12 : null;
                @endphp
                @if($m !== null && $y !== null && $full > 0)
                    <p class="field-help">
                        @if($y < $full)
                            {{ __('sadmin_plans.saving_preview', ['full' => $format($full, $currency), 'percent' => intdiv(($full - $y) * 100, $full)]) }}
                        @else
                            {{ __('sadmin_plans.no_saving_preview', ['full' => $format($full, $currency)]) }}
                        @endif
                    </p>
                @endif
                <p class="field-help">{{ __('sadmin_plans.fields.price_help') }}</p>
            </section>

            <section class="drawer-section">
                <h3>{{ __('sadmin_plans.sections.offer') }}</h3>
                <div class="form-grid">
                    <x-ui.field :label="__('sadmin_plans.fields.trial_days')" for="plan-trial" name="trialDays" :help="__('sadmin_plans.fields.trial_help')">
                        <input id="plan-trial" type="number" min="0" max="365" inputmode="numeric" wire:model="trialDays">
                    </x-ui.field>
                    <x-ui.field :label="__('sadmin_plans.fields.sort_order')" for="plan-order" name="sortOrder" required>
                        <input id="plan-order" type="number" min="0" wire:model="sortOrder" required>
                    </x-ui.field>
                </div>
                <label class="choice choice--switch"><input type="checkbox" role="switch" wire:model="isPublic"><span>{{ __('sadmin_plans.fields.public') }}</span></label>
                <label class="choice choice--switch"><input type="checkbox" role="switch" wire:model="isFeatured"><span>{{ __('sadmin_plans.fields.featured') }}</span></label>
            </section>

            <section class="drawer-section">
                <h3>{{ __('sadmin_plans.sections.capabilities') }}</h3>
                @foreach($grouped as $category => $keys)
                    <fieldset class="field" wire:key="plan-cap-{{ $category }}">
                        <legend>{{ __('platform_labels.entitlement_category.'.$category) }}</legend>
                        <div class="check-list check-list--columns">
                            @foreach($keys as $key)
                                <label class="choice" wire:key="plan-cap-key-{{ $key }}">
                                    <input type="checkbox" value="{{ $key }}" wire:model="selectedEntitlements">
                                    <span>{{ __('platform_labels.entitlement.'.$key) }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                @endforeach
            </section>

            @if($enforced !== [])
                <section class="drawer-section">
                    <h3>{{ __('sadmin_plans.sections.limits') }}</h3>
                    @foreach($enforced as $resource)
                        <x-ui.field :label="Label::for('usage_resource', $resource)" for="limit-{{ $resource }}" name="limits.{{ $resource }}" :help="__('sadmin_plans.limits.help', ['default' => $usageCatalog->systemDefault($resource) === null ? __('sadmin_plans.limits.unlimited') : number_format($usageCatalog->systemDefault($resource))])">
                            <input id="limit-{{ $resource }}" type="text" dir="ltr" inputmode="numeric" list="limit-options" wire:model="limits.{{ $resource }}" placeholder="{{ __('sadmin_plans.limits.default_placeholder') }}" autocomplete="off">
                        </x-ui.field>
                    @endforeach
                    <datalist id="limit-options"><option value="unlimited"></datalist>
                </section>
            @endif

            <section class="drawer-section">
                <h3>{{ __('sadmin_plans.sections.change') }}</h3>
                <x-ui.field :label="__('sadmin_plans.fields.reason')" for="plan-reason" name="reason" required>
                    <textarea id="plan-reason" wire:model="reason" rows="2" required></textarea>
                </x-ui.field>
            </section>

            @if($editing)
                <section class="danger-zone">
                    <div>
                        <h3>{{ $editing->is_active ? __('sadmin_plans.archive') : __('sadmin_plans.restore') }}</h3>
                        <p>{{ $editing->is_active ? __('sadmin_plans.archive_confirm') : __('sadmin_plans.restore_hint') }}</p>
                    </div>
                    <button class="button {{ $editing->is_active ? 'button--danger-soft' : 'button--secondary' }}" type="button" wire:click="setActive({{ $editing->is_active ? 'false' : 'true' }})"
                        @if($editing->is_active) wire:confirm="{{ __('sadmin_plans.archive_confirm') }}" data-confirm-title="{{ __('sadmin_plans.archive') }}" data-confirm-tone="danger" @endif>
                        {{ $editing->is_active ? __('sadmin_plans.archive') : __('sadmin_plans.restore') }}
                    </button>
                </section>
            @endif

            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="closeEditor">{{ __('ui.actions.cancel') }}</button>
                <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="save">{{ __('sadmin_plans.save') }}</button>
            </x-slot:footer>
        </x-ui.drawer>
    @endif
</div>
