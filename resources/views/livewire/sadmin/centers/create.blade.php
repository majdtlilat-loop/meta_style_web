@php
    use App\View\Label;

    $price = fn ($plan, string $cycle) => $plan->priceFor($cycle) === null ? null : $money->format($plan->priceFor($cycle), $plan->currency, app()->getLocale());
@endphp

<div class="stack">
    <nav aria-label="{{ __('sadmin_centers.detail.breadcrumb') }}">
        <ol class="breadcrumbs">
            <li><a href="{{ route('superadmin.centers.index') }}" wire:navigate>{{ __('sadmin_centers.title') }}</a></li>
            <li><span aria-current="page">{{ __('sadmin_centers.create.title') }}</span></li>
        </ol>
    </nav>

    <x-ui.page-header :title="__('sadmin_centers.create.title')" />

    <form class="form-page" wire:submit="create" novalidate>
        <div class="form-page__main stack">
            {{-- Center --}}
            <x-ui.card :title="__('sadmin_centers.form.center')">
                <div class="stack stack--sm">
                    <x-ui.field :label="__('sadmin_centers.form.name')" for="center-name" name="name" required>
                        <input id="center-name" type="text" wire:model.live.debounce.400ms="name" maxlength="190" autocomplete="off" required>
                    </x-ui.field>
                    <x-ui.field :label="__('sadmin_centers.form.slug')" for="center-slug" name="slug" required>
                        <input id="center-slug" type="text" dir="ltr" wire:model.blur="slug" maxlength="63" autocomplete="off" required aria-describedby="center-host">
                    </x-ui.field>
                    <p id="center-host" class="host-preview" aria-live="polite">
                        @if($host)
                            <x-ui.icon name="globe" size="16" /><span dir="ltr">{{ $host }}</span>
                            @if($slugAvailable === true)
                                <x-ui.status value="available" tone="success" :label="__('sadmin_centers.create.available')" />
                            @elseif($slugAvailable === false)
                                <x-ui.status value="taken" tone="danger" :label="__('sadmin_centers.create.taken')" />
                            @endif
                        @elseif($slug !== '')
                            <span class="text-danger">{{ __('sadmin_centers.create.invalid_slug') }}</span>
                        @else
                            <x-ui.icon name="globe" size="16" /><span>{{ __('sadmin_centers.create.host_hint') }}</span><span class="muted" dir="ltr">…{{ '.'.$baseHost }}</span>
                        @endif
                    </p>
                    <div class="form-grid">
                        <x-ui.field :label="__('sadmin_centers.form.currency')" for="center-currency" name="currency" required :help="__('sadmin_centers.form.currency_help')">
                            <select id="center-currency" wire:model="currency" required>
                                @foreach($currencies as $code)
                                    <option value="{{ $code }}">{{ $code }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>
                        <x-ui.field :label="__('sadmin_centers.form.timezone')" for="center-timezone" name="timezone" required>
                            <select id="center-timezone" wire:model="timezone" required>
                                @foreach($timezones as $zone)
                                    <option value="{{ $zone }}">{{ $zone }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>
                    </div>
                    <fieldset class="field">
                        <legend>{{ __('sadmin_centers.form.languages') }}</legend>
                        <div class="chip-list" role="group">
                            @foreach($languages as $code => $label)
                                <label class="chip-toggle">
                                    <input type="checkbox" value="{{ $code }}" wire:model.live="locales">
                                    <span>{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                        @error('locales')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                    </fieldset>
                    <x-ui.field :label="__('sadmin_centers.form.primary_language')" for="center-primary" name="primaryLocale" required>
                        <select id="center-primary" wire:model="primaryLocale" required>
                            @foreach($locales as $code)
                                <option value="{{ $code }}">{{ $languages[$code] ?? $code }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                </div>
            </x-ui.card>

            {{-- Owner --}}
            <x-ui.card :title="__('sadmin_centers.form.owner')">
                <div class="stack stack--sm">
                    <x-ui.field :label="__('sadmin_centers.form.owner_name')" for="owner-name" name="ownerName" required>
                        <input id="owner-name" type="text" wire:model="ownerName" maxlength="190" autocomplete="off" required>
                    </x-ui.field>
                    <div class="form-grid">
                        <x-ui.field :label="__('sadmin_centers.form.owner_email')" for="owner-email" name="ownerEmail" required>
                            <input id="owner-email" type="email" dir="ltr" wire:model="ownerEmail" maxlength="190" autocomplete="off" required>
                        </x-ui.field>
                        <x-ui.phone number="ownerPhone" country="ownerPhoneCountry" id="owner-phone" :label="__('sadmin_centers.form.owner_phone')" :help="__('phone_field.help_owner')" required />
                    </div>
                    <p class="note"><x-ui.icon name="mail" size="16" />{{ __('sadmin_centers.create.owner_note') }}</p>
                </div>
            </x-ui.card>

            {{-- Commercial --}}
            <x-ui.card :title="__('sadmin_centers.form.commercial')">
                <div class="stack stack--sm">
                    <fieldset class="field">
                        <legend>{{ __('sadmin_centers.form.plan') }}</legend>
                        <div class="choice-cards">
                            @foreach($plans as $option)
                                <label class="choice-card" wire:key="create-plan-{{ $option->id }}" @class(['is-selected' => (int) $planId === $option->id])>
                                    <input class="sr-only" type="radio" name="create-plan" value="{{ $option->id }}" wire:model.live="planId">
                                    <span class="choice-card__title">{{ $option->name->get() }}@if($option->is_featured)<span class="badge" data-tone="primary">{{ __('sadmin_plans.featured') }}</span>@endif</span>
                                    <span class="choice-card__meta" dir="ltr">
                                        @if($price($option, 'monthly')){{ $price($option, 'monthly') }} / {{ __('sadmin_centers.plan.per_month') }}@endif
                                        @if($price($option, 'monthly') && $price($option, 'yearly'))<br>@endif
                                        @if($price($option, 'yearly')){{ $price($option, 'yearly') }} / {{ __('sadmin_centers.plan.per_year') }}@endif
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        @error('planId')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                    </fieldset>
                    <fieldset class="field">
                        <legend>{{ __('sadmin_centers.plan.cycle') }}</legend>
                        <div class="segmented" role="radiogroup">
                            @foreach(['monthly', 'yearly'] as $option)
                                <label @class(['is-active' => $cycle === $option, 'is-disabled' => $plan && ! $plan->offers($option)])>
                                    <input class="sr-only" type="radio" name="create-cycle" value="{{ $option }}" wire:model.live="cycle" @disabled($plan && ! $plan->offers($option))>{{ Label::for('billing_period', $option) }}
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                    <label class="choice choice--switch">
                        <input type="checkbox" role="switch" wire:model.live="trial">
                        <span>{{ __('sadmin_centers.form.start_trial') }}</span>
                    </label>
                    <div class="form-grid">
                        @if($trial)
                            <x-ui.field :label="__('sadmin_centers.form.trial_days')" for="trial-days" name="trialDays" :help="__('sadmin_centers.form.trial_days_help', ['days' => $defaultTrialDays])">
                                <input id="trial-days" type="number" min="1" max="365" inputmode="numeric" wire:model="trialDays" placeholder="{{ $defaultTrialDays }}">
                            </x-ui.field>
                        @else
                            <x-ui.field :label="__('sadmin_centers.form.starts_at')" for="starts-at" name="startsAt" required :help="__('sadmin_centers.form.starts_help')">
                                <input id="starts-at" type="date" wire:model="startsAt" required>
                            </x-ui.field>
                        @endif
                    </div>
                </div>
            </x-ui.card>

            {{-- Features --}}
            <x-ui.card :title="__('sadmin_centers.form.features')">
                <div class="stack stack--sm">
                    @foreach($catalog as $category => $keys)
                        <fieldset class="field" wire:key="feature-group-{{ $category }}">
                            <legend>{{ __('platform_labels.entitlement_category.'.$category) }}</legend>
                            <div class="check-list check-list--columns">
                                @foreach($keys as $key)
                                    @php
                                        $inPlan = in_array($key, $planFeatures, true);
                                        $chosen = in_array($key, $features, true);
                                    @endphp
                                    <label class="choice" wire:key="feature-{{ $key }}">
                                        <input type="checkbox" value="{{ $key }}" wire:model.live="features">
                                        <span>{{ __('platform_labels.entitlement.'.$key) }}
                                            @if($inPlan && ! $chosen)<small class="text-danger">{{ __('sadmin_centers.form.feature_removed') }}</small>
                                            @elseif(! $inPlan && $chosen)<small class="text-success">{{ __('sadmin_centers.form.feature_added') }}</small>
                                            @elseif($inPlan)<small class="muted">{{ __('sadmin_centers.form.feature_in_plan') }}</small>@endif
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>
                    @endforeach
                </div>
            </x-ui.card>
        </div>

        <aside class="form-page__aside">
            <div class="card summary-card">
                <h2>{{ __('sadmin_centers.create.summary') }}</h2>
                <dl class="summary-list">
                    <div><dt>{{ __('sadmin_centers.form.name') }}</dt><dd>{{ $name !== '' ? $name : '—' }}</dd></div>
                    <div><dt>{{ __('sadmin_centers.form.slug') }}</dt><dd dir="ltr">{{ $host ?? '—' }}</dd></div>
                    <div><dt>{{ __('sadmin_centers.form.plan') }}</dt><dd>{{ $plan?->name->get() ?? '—' }}</dd></div>
                    <div><dt>{{ __('sadmin_centers.plan.cycle') }}</dt><dd>{{ Label::for('billing_period', $cycle) }}</dd></div>
                    <div><dt>{{ __('sadmin_centers.detail.commercial.price') }}</dt><dd dir="ltr">{{ $plan ? ($price($plan, $cycle) ?? '—') : '—' }}</dd></div>
                    <div><dt>{{ __('sadmin_centers.form.start') }}</dt><dd>{{ $trial ? trans_choice('sadmin_centers.create.trial_for', (int) ($trialDays !== '' ? $trialDays : $defaultTrialDays), ['count' => (int) ($trialDays !== '' ? $trialDays : $defaultTrialDays)]) : __('sadmin_centers.create.paid_from', ['date' => $startsAt]) }}</dd></div>
                    <div><dt>{{ __('sadmin_centers.form.currency') }}</dt><dd dir="ltr">{{ $currency }}</dd></div>
                </dl>
                <div class="stack stack--sm">
                    <button class="button button--block" type="submit" wire:loading.attr="data-loading" wire:target="create"><x-ui.icon name="plus" size="16" />{{ __('sadmin_centers.create.submit') }}</button>
                    <a class="button button--secondary button--block" href="{{ route('superadmin.centers.index') }}" wire:navigate>{{ __('ui.actions.cancel') }}</a>
                </div>
            </div>
        </aside>
    </form>
</div>
