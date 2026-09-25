<div class="auth-flow">
    <header class="auth-heading">
        <p class="eyebrow">{{ __('ui.auth_story.center_eyebrow') }}</p>
        <h1>{{ __('center_auth.registration.title') }}</h1>
        <p>{{ __('center_auth.registration.subtitle') }}</p>
    </header>

    <form wire:submit="submit" novalidate>
        <div class="form-grid">
            <x-ui.input name="centerName" id="centerName" type="text" wire:model="centerName" :label="__('center_auth.fields.center_name')" autocomplete="organization" required />
            <x-ui.input name="ownerName" id="ownerName" type="text" wire:model="ownerName" :label="__('center_auth.fields.your_name')" autocomplete="name" required />
        </div>

        <div class="field">
            <label for="centerSlug">{{ __('center_auth.fields.center_address') }}<span class="required" aria-hidden="true">*</span></label>
            <div class="input-suffix" dir="ltr">
                <input id="centerSlug" type="text" wire:model="centerSlug" autocomplete="off" dir="ltr" required aria-describedby="centerSlugHelp" @error('centerSlug') aria-invalid="true" @enderror>
                <span>.{{ $baseHost }}</span>
            </div>
            <p id="centerSlugHelp" class="field-help">{{ __('center_auth.registration.slug_help') }}</p>
            @error('centerSlug')<p class="error" role="alert"><x-ui.icon name="alert-circle" size="14" />{{ $message }}</p>@enderror
        </div>

        <div class="form-grid">
            <x-ui.input name="email" id="email" type="email" wire:model="email" :label="__('center_auth.fields.email')" autocomplete="email" required />
            <x-ui.phone number="phone" country="phoneCountry" id="owner-phone" :label="__('phone_field.label_required')" :help="__('phone_field.help_owner')" required />
            <x-ui.password name="password" id="password" wire:model="password" :label="__('center_auth.fields.password')" autocomplete="new-password" required />
        </div>

        <x-ui.field :label="__('center_auth.fields.language')" for="locale" name="locale">
            <select id="locale" wire:model="locale">
                @foreach (config('localization.languages') as $code => $language)
                    <option value="{{ $code }}">{{ $language['name_native'] }}</option>
                @endforeach
            </select>
        </x-ui.field>

        @if ($plans->isNotEmpty())
            @php $bothCycles = $plans->contains(fn ($plan) => $plan->offers('monthly')) && $plans->contains(fn ($plan) => $plan->offers('yearly')); @endphp
            <fieldset class="field">
                <legend class="field-label">{{ __('center_auth.registration.choose_plan') }}</legend>
                @if ($bothCycles)
                    <div class="segmented" role="radiogroup" aria-label="{{ __('center_auth.registration.billing_cycle') }}">
                        <label @class(['is-active' => $billingCycle === 'monthly'])><input class="sr-only" type="radio" wire:model.live="billingCycle" value="monthly">{{ __('center_auth.registration.cycle_monthly') }}</label>
                        <label @class(['is-active' => $billingCycle === 'yearly'])><input class="sr-only" type="radio" wire:model.live="billingCycle" value="yearly">{{ __('center_auth.registration.cycle_yearly') }}</label>
                    </div>
                @endif
                <div class="choice-grid">
                    @foreach ($plans as $plan)
                        @php $shownCycle = $plan->offers($billingCycle) ? $billingCycle : ($plan->cycles()[0] ?? null); @endphp
                        <label class="choice-card">
                            <input type="radio" wire:model="planId" value="{{ $plan->id }}">
                            <span class="stack--sm">
                                <strong>{{ $plan->name->get() }}</strong>
                                @if ($shownCycle)
                                    <span class="muted"><x-ui.money :minor="$plan->priceFor($shownCycle)" :currency="$plan->currency" /> / {{ $shownCycle === 'yearly' ? __('center_auth.billing_periods.yearly') : __('center_auth.billing_periods.monthly') }}</span>
                                @endif
                            </span>
                        </label>
                    @endforeach
                </div>
                @error('planId')<p class="error" role="alert"><x-ui.icon name="alert-circle" size="14" />{{ $message }}</p>@enderror
            </fieldset>
        @endif

        <x-ui.button type="submit" wire:loading.attr="data-loading" wire:target="submit">{{ __('center_auth.actions.create_account') }}</x-ui.button>
    </form>

    <p class="auth-alt">{{ __('center_auth.registration.existing_center') }}</p>
</div>
