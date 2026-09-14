{{--
    Branch management.

    Split shifts are entered as several intervals on one day, because that is
    what the schema stores and what most centers in this market actually work
    (docs/13-ROADMAP.md Phase 4 §1).
--}}
<div>
    <h1>{{ __('Branches') }}</h1>
    <p class="sub">{{ __('Locations, contact details and opening hours.') }}</p>

    @if ($notice !== '')
        <p class="notice">{{ $notice }}</p>
    @endif

    <div class="card">
        <table class="list">
            <thead>
                <tr>
                    <th>{{ __('Branch') }}</th>
                    <th>{{ __('Timezone') }}</th>
                    <th>{{ __('Visibility') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($branches as $branch)
                    <tr @if ($branch->archived_at) class="muted-row" @endif>
                        <td>
                            <strong>{{ $branch->name?->get() }}</strong>
                            @if ($branch->is_main)
                                <span class="tag">{{ __('Main') }}</span>
                            @endif
                            @if ($branch->archived_at)
                                <span class="tag">{{ __('Archived') }}</span>
                            @endif
                            <div class="sub">{{ $branch->workingHours->count() }} {{ __('interval(s)') }}</div>
                        </td>
                        <td>{{ $branch->timezone }}</td>
                        <td>
                            {{ $branch->is_active ? __('Active') : __('Inactive') }}
                            @if (! $branch->is_public)
                                · {{ __('Hidden from menu') }}
                            @endif
                        </td>
                        <td class="actions">
                            @if ($canManage && ! $branch->archived_at)
                                <button type="button" wire:click="edit('{{ $branch->uuid }}')">{{ __('Edit') }}</button>
                                @unless ($branch->is_main)
                                    <button type="button" wire:click="archive('{{ $branch->uuid }}')"
                                        wire:confirm="{{ __('Archive this branch?') }}">{{ __('Archive') }}</button>
                                @endunless
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if ($canManage)
        <h2>{{ $editing ? __('Edit branch') : __('Add a branch') }}</h2>

        <form wire:submit="save" class="card">
            @foreach ($locales as $locale)
                <label for="name-{{ $locale }}">{{ __('Name') }} ({{ $locale }})</label>
                <input id="name-{{ $locale }}" type="text" wire:model="name.{{ $locale }}">
            @endforeach
            @error('name') <p class="error">{{ $message }}</p> @enderror

            @foreach ($locales as $locale)
                <label for="address-{{ $locale }}">{{ __('Address') }} ({{ $locale }})</label>
                <input id="address-{{ $locale }}" type="text" wire:model="address.{{ $locale }}">
            @endforeach

            <label for="timezone">{{ __('Timezone') }}</label>
            <input id="timezone" type="text" wire:model="timezone">
            @error('timezone') <p class="error">{{ $message }}</p> @enderror

            <label for="phone">{{ __('Phone') }}</label>
            <input id="phone" type="text" wire:model="phone">

            <label for="whatsapp">{{ __('WhatsApp') }}</label>
            <input id="whatsapp" type="text" wire:model="whatsapp">

            <label for="email">{{ __('Email') }}</label>
            <input id="email" type="email" wire:model="email">
            @error('email') <p class="error">{{ $message }}</p> @enderror

            <label for="mapUrl">{{ __('Map link') }}</label>
            <input id="mapUrl" type="text" wire:model="mapUrl">
            @error('mapUrl') <p class="error">{{ $message }}</p> @enderror

            <label class="inline">
                <input type="checkbox" wire:model="isActive"> {{ __('Active') }}
            </label>
            <label class="inline">
                <input type="checkbox" wire:model="isPublic"> {{ __('Show on the public menu') }}
            </label>

            <h3>{{ __('Opening hours') }}</h3>
            <p class="sub">
                {{ __('Add more than one interval to a day for a split shift. A closing time earlier than the opening time means the interval runs past midnight.') }}
            </p>

            @error('hours') <p class="error">{{ $message }}</p> @enderror

            @foreach ($hours as $index => $interval)
                <div class="row">
                    <select wire:model="hours.{{ $index }}.day_of_week" aria-label="{{ __('Day') }}">
                        @foreach ($days as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <input type="time" wire:model="hours.{{ $index }}.opens_at" aria-label="{{ __('Opens') }}">
                    <input type="time" wire:model="hours.{{ $index }}.closes_at" aria-label="{{ __('Closes') }}">
                    <button type="button" wire:click="removeInterval({{ $index }})">{{ __('Remove') }}</button>
                </div>
            @endforeach

            <p>
                <button type="button" wire:click="addInterval">{{ __('Add an interval') }}</button>
            </p>

            <button type="submit" class="btn">{{ __('Save branch') }}</button>
        </form>
    @endif
</div>
