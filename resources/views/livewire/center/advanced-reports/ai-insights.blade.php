<div class="adv-ai-host" wire:keydown.escape.window="closeRayan">
    @if($state === 'unavailable')
        <section class="adv-ai adv-ai--off" aria-labelledby="adv-ai-title">
            <span class="adv-ai__mark" aria-hidden="true"><x-ui.icon name="sparkles" /></span>
            <div>
                <h2 id="adv-ai-title">{{ __('manager_advanced.ai.title') }}</h2>
                <p class="adv-ai__status" role="status">{{ __('manager_advanced.ai.unavailable') }}</p>
            </div>
        </section>
    @elseif($state === 'ready')
        <section class="adv-ai" aria-labelledby="adv-ai-title">
            <header class="adv-ai__head">
                <span class="adv-ai__mark" aria-hidden="true"><x-ui.icon name="sparkles" /></span>
                <div class="adv-ai__title">
                    <h2 id="adv-ai-title">{{ __('manager_advanced.ai.title') }}</h2>
                    <p>{{ $context }}</p>
                </div>
                @if($allowance)
                    <div class="adv-ai__allowance" title="{{ __('manager_advanced.ai.allowance') }}">
                        <span>{{ $allowance['text'] }}</span>
                        @if($allowance['percent'] !== null)
                            <span class="adv-meter" role="meter" aria-label="{{ __('manager_advanced.ai.allowance') }}" aria-valuemin="0" aria-valuemax="{{ $allowance['allowance'] }}" aria-valuenow="{{ $allowance['used'] }}">
                                <span class="adv-meter__fill" style="inline-size: {{ $allowance['percent'] }}%" @if($allowance['percent'] >= 85) data-high @endif></span>
                            </span>
                        @endif
                    </div>
                @endif
            </header>

            <div class="adv-ai__controls">
                @if($choices !== [])
                    <label class="adv-ai__report">
                        <span>{{ __('manager_advanced.ai.analyse') }}</span>
                        <select wire:change="useReport($event.target.value)" aria-label="{{ __('manager_advanced.ai.analyse') }}">
                            @foreach($choices as $choice)
                                <option value="{{ $choice['code'] }}" @selected($choice['code'] === $code)>{{ $choice['label'] }}</option>
                            @endforeach
                        </select>
                    </label>
                @else
                    <p class="adv-ai__report"><span>{{ __('manager_advanced.ai.analyse') }}</span><strong>{{ $reportTitle }}</strong></p>
                @endif
                <div class="adv-ai__buttons">
                    <button type="button" class="button button--sm adv-ai__generate" wire:click="generate" wire:loading.attr="disabled" wire:target="generate">
                        <x-ui.icon name="sparkles" size="16" />
                        <span wire:loading.remove wire:target="generate">{{ $insightsFor === '' ? __('manager_advanced.ai.generate') : __('manager_advanced.ai.regenerate') }}</span>
                        <span wire:loading wire:target="generate">{{ __('manager_advanced.ai.working') }}</span>
                    </button>
                    <button type="button" class="button button--secondary button--sm" wire:click="openRayan" aria-haspopup="dialog" aria-expanded="{{ $rayanOpen ? 'true' : 'false' }}">
                        <x-ui.icon name="message" size="16" />{{ __('manager_advanced.ai.ask') }}
                    </button>
                </div>
            </div>

            @if($insightsError)
                <x-ui.notice tone="warning" :message="$insightsError" />
            @endif

            <div class="adv-ai__skeleton" wire:loading.flex wire:target="generate" aria-hidden="true">
                @foreach([92, 78, 85, 64] as $width)<span class="skeleton" style="inline-size: {{ $width }}%"></span>@endforeach
            </div>

            @if($sections !== [])
                <div class="adv-ai__insights" wire:loading.remove wire:target="generate" aria-live="polite">
                    @foreach($sections as $section)
                        <article class="adv-ai__block" data-section="{{ $section['key'] }}">
                            <h3>{{ $section['title'] }}</h3>
                            <ul>
                                @foreach($insights[$section['key']] as $point)
                                    <li>{{ $point }}</li>
                                @endforeach
                            </ul>
                        </article>
                    @endforeach
                </div>
                <p class="adv-ai__foot"><x-ui.icon name="shield" size="14" />{{ __('manager_advanced.ai.read_only') }}</p>
            @endif
            @if($nothing)
                <p class="adv-ai__nothing" wire:loading.remove wire:target="generate" role="status">{{ __('manager_advanced.ai.nothing') }}</p>
            @endif
        </section>

        @if($rayanOpen)
            <div class="adv-rayan-backdrop" wire:click.self="closeRayan">
                <aside class="adv-rayan" role="dialog" aria-modal="true" aria-labelledby="rayan-title" aria-describedby="rayan-context-description" x-data x-trap.noscroll="true">
                    <p id="rayan-context-description" class="sr-only">{{ __('manager_advanced.ai.description') }}</p>
                    <header class="adv-rayan__head">
                        <div class="adv-rayan__identity">
                            <span class="adv-ai__mark" aria-hidden="true"><x-ui.icon name="sparkles" /></span>
                            <div>
                                <h2 id="rayan-title">{{ __('manager_advanced.ai.ask') }}</h2>
                                <p>{{ $reportTitle }}</p>
                            </div>
                        </div>
                        <button type="button" class="icon-button" wire:click="closeRayan" aria-label="{{ __('manager_advanced.ai.close') }}"><x-ui.icon name="close" /></button>
                    </header>
                    <p class="adv-rayan__context">{{ $context }}</p>
                    <div class="adv-rayan__thread" aria-live="polite">
                        @if($analysisMessages === [])
                            <div class="adv-rayan__prompts" role="group" aria-label="{{ __('manager_advanced.ai.suggestions') }}">
                                @foreach($prompts as $prompt)
                                    <button type="button" wire:click="$set('question', @js($prompt))">{{ $prompt }}</button>
                                @endforeach
                            </div>
                        @endif
                        @foreach($analysisMessages as $message)
                            <div @class(['adv-rayan__message', 'adv-rayan__message--you' => $message['role'] === 'user', 'adv-rayan__message--rayan' => $message['role'] === 'assistant']) wire:key="adv-rayan-message-{{ $loop->index }}">
                                <strong>{{ $message['role'] === 'user' ? __('manager_advanced.ai.you') : __('manager_advanced.ai.name') }}</strong>
                                <p>{{ $message['text'] }}</p>
                            </div>
                        @endforeach
                        <div class="adv-rayan__message adv-rayan__message--rayan adv-rayan__typing" wire:loading.flex wire:target="ask" aria-hidden="true"><span></span><span></span><span></span></div>
                    </div>
                    <form class="adv-rayan__compose" wire:submit="ask">
                        @if($analysisError)<p class="adv-rayan__error" role="alert">{{ $analysisError }}</p>@endif
                        <label for="rayan-question">{{ __('manager_advanced.ai.question_label') }}</label>
                        <textarea id="rayan-question" wire:model="question" rows="3" maxlength="1000" placeholder="{{ __('manager_advanced.ai.placeholder') }}" autofocus required></textarea>
                        <div class="adv-rayan__foot">
                            <small>{{ __('manager_advanced.ai.read_only') }} · {{ __('manager_advanced.ai.allowance') }}@if($allowance) · {{ $allowance['text'] }}@endif</small>
                            <x-ui.button type="submit" size="sm" wire:loading.attr="disabled" wire:target="ask">{{ __('manager_advanced.ai.send') }}</x-ui.button>
                        </div>
                    </form>
                </aside>
            </div>
        @endif
    @endif
</div>
