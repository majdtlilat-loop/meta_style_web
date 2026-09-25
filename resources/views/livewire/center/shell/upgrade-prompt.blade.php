{{--
    The upgrade dialog behind every locked sidebar item. Opening it never
    navigates; "View plans" and "Contact Meta Style" are the only links, plus
    the read-only history of a feature whose records stay readable.
--}}
<div>
    @if($offer)
        <div class="modal-backdrop upgrade-prompt" wire:click.self="close" x-data x-on:keydown.escape.window="$wire.close()" wire:key="upgrade-{{ $offer['key'] }}">
            <div class="modal modal--lg upgrade-prompt__dialog" role="dialog" aria-modal="true" aria-labelledby="feature-lock-{{ $offer['key'] }}" x-trap.noscroll="true">
                <button class="icon-button upgrade-prompt__close" type="button" wire:click="close" aria-label="{{ __('manager_features.ui.close') }}" title="{{ __('manager_features.ui.close') }}"><x-ui.icon name="close" /></button>
                <x-manager.feature-locked :offer="$offer">
                    @if($askManager)
                        <p class="upgrade-prompt__ask"><x-ui.icon name="info" size="16" />{{ __('manager_shell.upgrade.ask_manager') }}</p>
                    @endif
                    @if($history)
                        <a class="button button--ghost upgrade-prompt__history" href="{{ $history }}" wire:navigate><x-ui.icon name="history" size="18" />{{ __('manager_shell.upgrade.open_history') }}</a>
                    @endif
                </x-manager.feature-locked>
            </div>
        </div>
    @endif
</div>
