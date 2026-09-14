{{--
    Menu appearance.

    Every control is a choice from config/menu.php. There is deliberately no
    HTML, CSS or JavaScript field: a center-authored script on a page guests
    open is stored XSS against that center's own customers
    (docs/13-ROADMAP.md Phase 4 §14).
--}}
<div>
    <h1>{{ __('Menu appearance') }}</h1>
    <p class="sub">
        {{ __('Changes are saved as a draft. Customers keep seeing the published menu until you publish.') }}
    </p>

    @if ($notice !== '')
        <p class="notice">{{ $notice }}</p>
    @endif

    <div class="card">
        <dl>
            <dt>{{ __('Published') }}</dt>
            <dd>
                @if ($published)
                    {{ __('Version :v', ['v' => $published->version]) }} · {{ $published->template_key }}
                @else
                    {{ __('Not published yet') }}
                @endif
            </dd>
            <dt>{{ __('Public link') }}</dt>
            <dd><a href="{{ $publicUrl }}" target="_blank" rel="noopener">{{ $publicUrl }}</a></dd>
        </dl>
    </div>

    @if (! $canManage)
        <p class="sub">{{ __('You may view the menu settings but not change them.') }}</p>
    @else
        <form wire:submit="saveDraft" class="card">
            <h2>{{ __('Template') }}</h2>
            <label for="templateKey">{{ __('Base template') }}</label>
            <select id="templateKey" wire:model="templateKey">
                @foreach ($templates as $template)
                    <option value="{{ $template }}">{{ $template }}</option>
                @endforeach
            </select>

            <h2>{{ __('Theme') }}</h2>
            @error('theme') <p class="error">{{ $message }}</p> @enderror

            @foreach ($colorKeys as $key)
                <label for="theme-{{ $key }}">{{ __(ucfirst($key)) }} {{ __('colour') }}</label>
                <input id="theme-{{ $key }}" type="color" wire:model="theme.{{ $key }}">
            @endforeach

            @foreach ($themeOptions as $key => $choices)
                <label for="theme-{{ $key }}">{{ __(ucfirst(str_replace('_', ' ', $key))) }}</label>
                <select id="theme-{{ $key }}" wire:model="theme.{{ $key }}">
                    @foreach ($choices as $choice)
                        <option value="{{ $choice }}">{{ $choice }}</option>
                    @endforeach
                </select>
            @endforeach

            <h2>{{ __('Sections') }}</h2>
            <p class="sub">
                {{ __('Only sections whose feature exists are listed. Offers and reviews appear when those modules ship.') }}
            </p>

            @foreach ($sections as $index => $section)
                <div class="row section-row">
                    <label class="inline">
                        <input type="checkbox" wire:model="sections.{{ $index }}.visible">
                        <strong>{{ __(ucfirst(str_replace('_', ' ', $section['key']))) }}</strong>
                    </label>

                    @foreach ($sectionCatalog[$section['key']]['config'] ?? [] as $setting)
                        @php $rule = config('menu.section_config.'.$setting); @endphp

                        @if ($rule === 'bool')
                            <label class="inline">
                                <input type="checkbox" wire:model="sections.{{ $index }}.config.{{ $setting }}">
                                {{ str_replace('_', ' ', $setting) }}
                            </label>
                        @elseif (is_array($rule))
                            <select wire:model="sections.{{ $index }}.config.{{ $setting }}"
                                aria-label="{{ $setting }}">
                                @foreach ($rule as $choice)
                                    <option value="{{ $choice }}">{{ $choice }}</option>
                                @endforeach
                            </select>
                        @else
                            <input type="number" wire:model="sections.{{ $index }}.config.{{ $setting }}"
                                aria-label="{{ $setting }}" placeholder="{{ $setting }}">
                        @endif
                    @endforeach

                    <button type="button" wire:click="moveSection({{ $index }}, -1)" aria-label="{{ __('Move up') }}">↑</button>
                    <button type="button" wire:click="moveSection({{ $index }}, 1)" aria-label="{{ __('Move down') }}">↓</button>
                </div>
            @endforeach

            <p>
                <button type="submit" class="btn">{{ __('Save draft') }}</button>
                <button type="button" wire:click="publish">{{ __('Publish') }}</button>
                <a href="{{ $publicUrl }}" target="_blank" rel="noopener">{{ __('Preview the live menu') }}</a>
            </p>
        </form>

        @if ($history->isNotEmpty())
            <h2>{{ __('Previous versions') }}</h2>
            <div class="card">
                <table class="list">
                    <thead>
                        <tr>
                            <th>{{ __('Version') }}</th>
                            <th>{{ __('Template') }}</th>
                            <th>{{ __('Published') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($history as $version)
                            <tr>
                                <td>{{ $version->version }}</td>
                                <td>{{ $version->template_key }}</td>
                                <td>{{ $version->published_at?->toDayDateTimeString() ?? '—' }}</td>
                                <td class="actions">
                                    <button type="button" wire:click="rollback('{{ $version->uuid }}')"
                                        wire:confirm="{{ __('Restore this version?') }}">
                                        {{ __('Restore') }}
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endif
</div>
