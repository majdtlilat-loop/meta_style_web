@php
    $date = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->translatedFormat('j M Y, H:i') : '—';
@endphp

<div class="stack">
    <x-ui.page-header :title="__('platform_settings.title')" />

    <x-ui.flash />
    <x-ui.flash key="notice-error" tone="danger" />

    <div class="settings-layout">
        <x-sadmin.settings-nav :current="$tab" internal />

        <div class="settings-panel" wire:loading.class="is-refreshing" wire:target="showTab">
            @switch($tab)
                @case('commercial')
                    <form class="card card--flush" wire:submit="save" aria-labelledby="settings-registration">
                        <header class="card__header"><h2 id="settings-registration">{{ __('platform_settings.sections.registration') }}</h2></header>
                        <div class="card__body stack">
                            <div class="form-grid">
                                <x-ui.field :label="__('platform_settings.trial_days')" for="trial-days" name="trialDays" :help="__('platform_settings.trial_help')" required>
                                    <input id="trial-days" type="number" min="0" max="365" inputmode="numeric" wire:model.live.debounce.400ms="trialDays" required>
                                </x-ui.field>
                                <x-ui.field :label="__('platform_settings.default_plan')" for="default-plan" name="defaultPlanCode" required>
                                    <select id="default-plan" wire:model.live="defaultPlanCode" required>
                                        @foreach($plans as $plan)<option value="{{ $plan->code }}">{{ $plan->name->get() }}</option>@endforeach
                                    </select>
                                </x-ui.field>
                            </div>
                            @if($dirty)
                                <x-ui.field :label="__('platform_settings.reason')" for="settings-reason" name="reason" required>
                                    <textarea id="settings-reason" rows="2" wire:model="reason" required></textarea>
                                </x-ui.field>
                            @endif
                        </div>
                        @if($dirty)
                            <footer class="card__footer">
                                <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="save">{{ __('platform_settings.save') }}</button>
                            </footer>
                        @endif
                    </form>
                    <x-ui.card :title="__('platform_settings.commercial.more')">
                        <div class="cluster">
                            <a class="button button--secondary" href="{{ route('superadmin.plans.index') }}" wire:navigate><x-ui.icon name="plans" size="16" />{{ __('sadmin_shell.nav.plans') }}</a>
                            <a class="button button--secondary" href="{{ route('superadmin.currencies.index') }}" wire:navigate><x-ui.icon name="coins" size="16" />{{ __('sadmin_shell.nav.currencies') }}</a>
                        </div>
                    </x-ui.card>
                    @break

                @case('security')
                    <x-ui.card :title="__('platform_settings.security.title')">
                        <div class="setting-row">
                            <div>
                                <h3>{{ __('platform_settings.security.mfa') }}</h3>
                                <p class="muted">{{ trans_choice('platform_settings.security.enrolled', $mfaCounts['total'], ['count' => $mfaCounts['enrolled'], 'total' => $mfaCounts['total']]) }}</p>
                            </div>
                            <div class="cluster">
                                <x-ui.status :value="$mfaRequired ? 'enabled' : 'disabled'" :tone="$mfaRequired ? 'success' : 'warning'" :label="$mfaRequired ? __('platform_settings.security.required') : __('platform_settings.security.not_required')" />
                                @if($canSecurity)
                                    <button class="button {{ $mfaRequired ? 'button--danger-soft' : '' }} button--sm" type="button" wire:click="openPanel('{{ $mfaRequired ? 'mfa-off' : 'mfa-on' }}')">{{ $mfaRequired ? __('platform_settings.security.turn_off') : __('platform_settings.security.turn_on') }}</button>
                                @endif
                            </div>
                        </div>
                        @unless($mfaRequired)
                            <div class="notice" data-tone="warning"><x-ui.icon name="alert-triangle" /><p>{{ __('platform_settings.security.off_warning') }}</p></div>
                        @endunless
                        @unless($canSecurity)
                            <p class="field-help">{{ __('platform_settings.security.needs_permission') }}</p>
                        @endunless
                    </x-ui.card>
                    @break

                @case('email')
                    <x-ui.card :title="__('platform_settings.email.title')">
                        <dl class="summary-list">
                            <div><dt>{{ __('platform_settings.email.status') }}</dt><dd><x-ui.status :value="$mail['ready'] ? 'enabled' : 'disabled'" :tone="$mail['ready'] ? 'success' : 'warning'" :label="$mail['ready'] ? __('platform_settings.email.ready') : __('platform_settings.email.not_ready')" /></dd></div>
                            <div><dt>{{ __('platform_settings.email.transport') }}</dt><dd class="mono" dir="ltr">{{ $mail['mailer'] }}</dd></div>
                            <div><dt>{{ __('platform_settings.email.sender_name') }}</dt><dd>{{ $mail['from_name'] ?: '—' }}</dd></div>
                            <div><dt>{{ __('platform_settings.email.sender_address') }}</dt><dd dir="ltr">{{ $mail['from_address'] ?: '—' }}</dd></div>
                            <div><dt>{{ __('platform_settings.email.queue') }}</dt><dd class="mono" dir="ltr">{{ $mail['queue'] }}</dd></div>
                        </dl>
                        <p class="field-help">{{ __('platform_settings.email.note') }}</p>
                        <div class="cluster action-row">
                            <button class="button button--secondary button--sm" type="button" wire:click="sendTestEmail" wire:loading.attr="data-loading" wire:target="sendTestEmail"><x-ui.icon name="send" size="16" />{{ __('platform_settings.email.test') }}</button>
                        </div>
                    </x-ui.card>
                    @break

                @case('notifications')
                    <form class="card card--flush" wire:submit="saveNotifications">
                        <header class="card__header"><h2>{{ __('platform_settings.notifications.title') }}</h2></header>
                        <div class="table-shell table-shell--stack">
                            <table>
                                <thead><tr>
                                    <th scope="col">{{ __('platform_settings.notifications.event') }}</th>
                                    <th scope="col" class="center">{{ __('platform_settings.notifications.in_app') }}</th>
                                    <th scope="col" class="center">{{ __('platform_settings.notifications.email') }}</th>
                                </tr></thead>
                                <tbody>
                                    @foreach($events as $event)
                                        @php $key = str_replace('.', '_', $event); @endphp
                                        <tr wire:key="rule-{{ $key }}">
                                            <td data-label="{{ __('platform_settings.notifications.event') }}" data-primary>
                                                <span class="cell-title">{{ __('platform_settings.notifications.events.'.$key) }}</span>
                                                <span class="cell-sub">{{ __('platform_settings.notifications.who.'.$key) }}</span>
                                            </td>
                                            <td data-label="{{ __('platform_settings.notifications.in_app') }}" class="center">
                                                <label class="switch"><input type="checkbox" role="switch" wire:model="rules.{{ $event }}.in_app" aria-label="{{ __('platform_settings.notifications.in_app') }}: {{ __('platform_settings.notifications.events.'.$key) }}"><span></span></label>
                                            </td>
                                            <td data-label="{{ __('platform_settings.notifications.email') }}" class="center">
                                                <label class="switch"><input type="checkbox" role="switch" wire:model="rules.{{ $event }}.email" aria-label="{{ __('platform_settings.notifications.email') }}: {{ __('platform_settings.notifications.events.'.$key) }}"><span></span></label>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <footer class="card__footer"><button class="button" type="submit" wire:loading.attr="data-loading" wire:target="saveNotifications">{{ __('platform_settings.save') }}</button></footer>
                    </form>
                    @break

                @case('localization')
                    <form class="card card--flush" wire:submit="saveGeneral">
                        <header class="card__header"><h2>{{ __('platform_settings.localization.title') }}</h2></header>
                        <div class="card__body stack">
                            <x-ui.field :label="__('platform_settings.localization.default')" for="default-locale" name="general.default_locale" required :help="__('platform_settings.localization.default_help')">
                                <select id="default-locale" wire:model="general.default_locale" required>
                                    @foreach($languages as $language)<option value="{{ $language['locale'] }}">{{ $language['name'] }}</option>@endforeach
                                </select>
                            </x-ui.field>
                            <ul class="language-list">
                                @foreach($languages as $language)
                                    <li>
                                        <img class="language-flag" src="{{ asset('icons/languages/'.$language['icon'].'.svg') }}" alt="" width="24" height="16">
                                        <strong>{{ $language['code'] }}</strong>
                                        <span>{{ $language['name'] }}</span>
                                        <span class="muted">{{ $language['direction'] === 'rtl' ? __('platform_settings.languages.direction_rtl') : __('platform_settings.languages.direction_ltr') }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                        <footer class="card__footer"><button class="button" type="submit" wire:loading.attr="data-loading" wire:target="saveGeneral">{{ __('platform_settings.save') }}</button></footer>
                    </form>
                    @break

                @default
                    <form class="card card--flush" wire:submit="saveGeneral">
                        <header class="card__header"><h2>{{ __('platform_settings.general.title') }}</h2></header>
                        <div class="card__body stack">
                            <x-ui.field :label="__('platform_settings.general.name')" for="platform-name" name="general.platform_name" required>
                                <input id="platform-name" type="text" wire:model="general.platform_name" maxlength="80" required>
                            </x-ui.field>
                            <div class="form-grid">
                                <x-ui.field :label="__('platform_settings.general.support_email')" for="support-email" name="general.support_email">
                                    <input id="support-email" type="email" dir="ltr" wire:model="general.support_email" maxlength="190">
                                </x-ui.field>
                                <x-ui.field :label="__('platform_settings.general.support_phone')" for="support-phone" name="general.support_phone">
                                    <input id="support-phone" type="tel" dir="ltr" wire:model="general.support_phone" maxlength="32">
                                </x-ui.field>
                            </div>
                            <x-ui.field :label="__('platform_settings.general.timezone')" for="platform-timezone" name="general.default_timezone" required :help="__('platform_settings.general.timezone_help')">
                                <select id="platform-timezone" wire:model="general.default_timezone" required>
                                    @foreach($timezones as $zone)<option value="{{ $zone }}">{{ $zone }}</option>@endforeach
                                </select>
                            </x-ui.field>
                        </div>
                        <footer class="card__footer"><button class="button" type="submit" wire:loading.attr="data-loading" wire:target="saveGeneral">{{ __('platform_settings.save') }}</button></footer>
                    </form>

                    <x-ui.card :title="__('platform_settings.sections.domains')">
                        <dl class="summary-list">
                            <div><dt>{{ __('platform_settings.domains.corporate') }}</dt><dd dir="ltr" class="mono">{{ $hosts['scheme'] }}://{{ $hosts['corporate'] }}</dd></div>
                            <div><dt>{{ __('platform_settings.domains.superadmin') }}</dt><dd dir="ltr" class="mono">{{ $hosts['scheme'] }}://{{ $hosts['superadmin'] }}</dd></div>
                            <div><dt>{{ __('platform_settings.domains.center') }}</dt><dd dir="ltr" class="mono">{{ $hosts['scheme'] }}://{{ $hosts['center'] }}</dd></div>
                        </dl>
                        <x-slot:footer><span class="field-help">{{ __('platform_settings.domains.note') }}</span></x-slot:footer>
                    </x-ui.card>
            @endswitch
        </div>
    </div>

    @if($panel === 'mfa-off' || $panel === 'mfa-on')
        @php $off = $panel === 'mfa-off'; @endphp
        <x-ui.modal :title="$off ? __('platform_settings.security.off_title') : __('platform_settings.security.on_title')" :description="$off ? __('platform_settings.security.off_body') : __('platform_settings.security.on_body')" icon="shield" :tone="$off ? 'danger' : null" submit="setMfa">
            @if($off)
                <x-ui.password name="currentPassword" wire:model="currentPassword" :label="__('platform_settings.security.password')" autocomplete="current-password" required />
            @endif
            <x-ui.field :label="__('platform_settings.reason')" for="mfa-reason" name="reason" required>
                <textarea id="mfa-reason" rows="2" wire:model="reason" required></textarea>
            </x-ui.field>
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="closePanel">{{ __('ui.actions.cancel') }}</button>
                <button class="button {{ $off ? 'button--danger' : '' }}" type="submit" wire:loading.attr="data-loading" wire:target="setMfa">{{ $off ? __('platform_settings.security.turn_off') : __('platform_settings.security.turn_on') }}</button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
