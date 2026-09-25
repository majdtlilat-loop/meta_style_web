{{--
    Guest booking confirmations on WhatsApp (docs/25-WHATSAPP.md §22). Values are
    prepared by App\Livewire\Center\Integrations\BookingConfirmations: the switch is
    the center's choice, the status is what the sender would actually do.
--}}
<div>
    <x-ui.card :title="__('manager_whatsapp.confirmations.title')" class="wa-confirmations">
        <x-slot:actions>
            <x-ui.status :tone="$stateTone" :label="$stateLabel" />
        </x-slot:actions>

        <div class="stack stack--sm">
            <x-ui.notice :message="$notice" :tone="$noticeTone" dismiss="dismissNotice" />

            <div class="wa-confirmations__switch">
                <label class="choice choice--switch">
                    <input type="checkbox" class="switch" role="switch" wire:model.live="enabled" wire:loading.attr="disabled" wire:target="enabled" @disabled(! $canManage)>
                    <span>{{ __('manager_whatsapp.confirmations.guest_toggle') }}</span>
                </label>
                <span class="info-tip" role="img" tabindex="0" title="{{ __('manager_whatsapp.confirmations.guest_tip') }}" aria-label="{{ __('manager_whatsapp.confirmations.guest_tip') }}"><x-ui.icon name="info" size="16" /></span>
            </div>

            @if($enabled && $blocker)
                <div class="notice" data-tone="warning" role="status"><x-ui.icon name="alert-triangle" /><p>{{ __('manager_whatsapp.confirmations.not_sending', ['reason' => $blocker]) }}</p></div>
            @endif

            <dl class="summary-list wa-summary">
                <div>
                    <dt>{{ __('manager_whatsapp.confirmations.template') }}</dt>
                    <dd>
                        @if($template)
                            <span class="mono" dir="ltr">{{ $template }}</span>
                        @else
                            <x-ui.status tone="warning" :dot="false" :label="__('manager_whatsapp.confirmations.template_missing')" />
                        @endif
                    </dd>
                </div>
                <div>
                    <dt>{{ __('manager_whatsapp.confirmations.language') }}</dt>
                    <dd>
                        <span class="wa-inline">
                            @if($language)
                                <span>{{ $language }}</span>
                            @else
                                <x-ui.status tone="warning" :dot="false" :label="__('manager_whatsapp.confirmations.language_none')" />
                            @endif
                            {{-- Each enabled language: sendable, or not available on WhatsApp (no Meta template language mapped). --}}
                            @foreach($languages as $lang)
                                @if($lang['sendable'])
                                    <span class="badge" data-tone="success" data-language="{{ $lang['locale'] }}" data-sendable="1" wire:key="wa-confirm-lang-{{ $lang['locale'] }}">{{ $lang['label'] }}</span>
                                @else
                                    <span class="badge" data-tone="neutral" data-language="{{ $lang['locale'] }}" data-sendable="0" title="{{ __('manager_whatsapp.confirmations.language_unavailable') }}" wire:key="wa-confirm-lang-{{ $lang['locale'] }}"><s>{{ $lang['label'] }}</s><span class="sr-only"> — {{ __('manager_whatsapp.confirmations.language_unavailable') }}</span></span>
                                @endif
                            @endforeach
                        </span>
                    </dd>
                </div>
                @if($counts !== null && $total > 0)
                    <div class="wa-summary__stacked">
                        <dt>{{ __('manager_whatsapp.confirmations.recent', ['days' => $days]) }}</dt>
                        <dd>
                            <ul class="wa-confirmations__counts" role="list">
                                @foreach($counts as $count)
                                    <li data-kind="{{ $count['key'] }}" wire:key="wa-confirm-count-{{ $count['key'] }}">
                                        <strong class="tabular">{{ $count['count'] }}</strong>
                                        <span>{{ $count['label'] }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </dd>
                    </div>
                @endif
                @if($issue)
                    <div>
                        <dt>{{ __('manager_whatsapp.confirmations.last_issue') }}</dt>
                        <dd>
                            <span class="wa-inline">
                                <x-ui.status tone="danger" :dot="false" :label="$issue['label']" />
                                @if($issue['reason'])
                                    @if($issue['reason']['code'])
                                        <span class="mono" dir="ltr">{{ $issue['reason']['text'] }}</span>
                                    @else
                                        <span>{{ $issue['reason']['text'] }}</span>
                                    @endif
                                @endif
                                <span class="cell-sub">{{ $issue['time'] }}</span>
                            </span>
                        </dd>
                    </div>
                @endif
            </dl>

            @unless($canManage)
                <p class="field-help"><x-ui.icon name="lock" size="14" /> {{ __('manager_whatsapp.confirmations.read_only') }}</p>
            @endunless
        </div>
    </x-ui.card>
</div>
