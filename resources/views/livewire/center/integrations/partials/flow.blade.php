{{-- What the booking channel actually does (only registered RAYAN tools are listed), and the languages it answers in. --}}
<div class="stack">
    <x-ui.card :title="__('manager_whatsapp.flow.title')" class="wa-flow">
        <div class="wa-flow__groups">
            <div class="wa-flow__group">
                <h3>{{ __('manager_whatsapp.flow.team_title') }}</h3>
                <ul role="list">
                    @foreach($flow['team'] as $line)
                        <li><x-ui.icon name="check" size="16" /><span>{{ $line }}</span></li>
                    @endforeach
                </ul>
            </div>
            <div class="wa-flow__group" @unless($assistantOwned) data-locked @endunless>
                <h3>
                    {{ __('manager_whatsapp.flow.assistant_title') }}
                    @unless($assistantOwned)<span class="badge" data-tone="neutral"><x-ui.icon name="lock" size="12" />{{ __('manager_whatsapp.flow.locked') }}</span>@endunless
                </h3>
                <ul role="list">
                    @foreach($flow['assistant'] as $line)
                        <li><x-ui.icon name="sparkles" size="16" /><span>{{ $line }}</span></li>
                    @endforeach
                </ul>
            </div>
        </div>
        @if($conversationsHref)
            <x-slot:footer>
                <a class="button button--ghost button--sm" href="{{ $conversationsHref }}" wire:navigate><x-ui.icon name="conversations" size="16" />{{ __('manager_whatsapp.actions.open_conversations') }}</a>
            </x-slot:footer>
        @endif
    </x-ui.card>

    <x-ui.card :title="__('manager_whatsapp.languages.title')" class="wa-languages">
        @if($languagesHref)
            <x-slot:actions>
                <a class="button button--ghost button--sm" href="{{ $languagesHref }}" wire:navigate>{{ __('manager_whatsapp.languages.change') }}</a>
            </x-slot:actions>
        @endif
        <ul class="wa-languages__list" role="list">
            @foreach($languages as $language)
                <li wire:key="wa-lang-{{ $language['code'] }}">
                    <strong>{{ $language['short'] }}</strong>
                    <span dir="{{ $language['dir'] }}">{{ $language['native'] }}</span>
                    @if($language['primary'])<x-ui.status tone="info" :dot="false" :label="__('manager_whatsapp.languages.primary')" />@endif
                </li>
            @endforeach
        </ul>
    </x-ui.card>
</div>
