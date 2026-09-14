{{--
    Staff CRM.

    Every customer here arrived already masked or unmasked by CustomerPresenter,
    the same one the API uses. There is no masking logic in this template, on
    purpose: hiding a value in Blade still sends it to the browser
    (docs/06-AUTH-ROLES-PERMISSIONS.md §6).
--}}
<div>
    <h1>{{ __('Customers') }}</h1>
    <p class="sub">{{ __('Everyone this center does business with. One record per person, across every branch.') }}</p>

    @if ($notice !== '')
        <p class="notice">{{ $notice }}</p>
    @endif

    <div class="card">
        <div class="row">
            <input type="search" wire:model.live.debounce.400ms="search"
                placeholder="{{ $canSeeContact ? __('Search name or phone') : __('Search name') }}"
                aria-label="{{ __('Search') }}">

            <select wire:model.live="registered" aria-label="{{ __('Account') }}">
                <option value="">{{ __('Everyone') }}</option>
                <option value="yes">{{ __('Has an account') }}</option>
                <option value="no">{{ __('Guest') }}</option>
            </select>

            @if ($tags->isNotEmpty())
                <select wire:model.live="tag" aria-label="{{ __('Tag') }}">
                    <option value="">{{ __('Any tag') }}</option>
                    @foreach ($tags as $t)
                        <option value="{{ $t->uuid }}">{{ $t->name->get() }}</option>
                    @endforeach
                </select>
            @endif

            <label class="inline">
                <input type="checkbox" wire:model.live="archived"> {{ __('Archived') }}
            </label>

            @if ($canCreate)
                <button type="button" wire:click="create">{{ __('Add a customer') }}</button>
            @endif
        </div>

        <table class="list">
            <thead>
                <tr>
                    <th>{{ __('Name') }}</th>
                    <th>{{ __('Phone') }}</th>
                    <th>{{ __('Account') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($customers as $customer)
                    <tr @if ($customer['is_archived']) class="muted-row" @endif>
                        <td>
                            <strong>{{ $customer['name'] }}</strong>
                            @foreach ($customer['tags'] as $t)
                                <span class="tag">{{ $t['name'] }}</span>
                            @endforeach
                            @if ($customer['is_archived'])
                                <span class="tag">{{ __('Archived') }}</span>
                            @endif
                        </td>
                        <td>
                            {{ $customer['phone'] ?? '—' }}
                            @if ($customer['contact_masked'] && $customer['phone'])
                                <div class="sub">{{ __('Hidden') }}</div>
                            @endif
                        </td>
                        <td>{{ $customer['is_registered'] ? __('Registered') : __('Guest') }}</td>
                        <td class="actions">
                            <button type="button" wire:click="open('{{ $customer['uuid'] }}')">{{ __('Open') }}</button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="sub">{{ __('No customers match.') }}</td></tr>
                @endforelse
            </tbody>
        </table>

        {{ $page->links() }}
    </div>

    {{-- ------------------------------------------------------------ profile --}}
    @if ($profile)
        <h2>{{ $profile['name'] }}</h2>

        <div class="card">
            <dl>
                <dt>{{ __('Phone') }}</dt>
                <dd>{{ $profile['phone'] ?? '—' }}</dd>

                <dt>{{ __('Email') }}</dt>
                <dd>{{ $profile['email'] ?? '—' }}</dd>

                <dt>{{ __('Language') }}</dt>
                <dd>{{ $profile['preferred_locale'] }}</dd>

                <dt>{{ __('Account') }}</dt>
                <dd>
                    @if ($profile['account'])
                        {{ $profile['account']['is_active'] ? __('Active') : __('Deactivated') }}
                        @unless ($profile['account']['phone_verified'])
                            · <span class="sub">{{ __('Phone not verified') }}</span>
                        @endunless
                    @else
                        {{ __('Guest — no login') }}
                    @endif
                </dd>

                <dt>{{ __('Messages') }}</dt>
                <dd>
                    {{ $profile['preferences']['allow_operational_messages'] ? __('Operational: yes') : __('Operational: no') }}
                    ·
                    {{ $profile['preferences']['marketing_opt_in'] ? __('Marketing: opted in') : __('Marketing: no') }}
                </dd>
            </dl>

            <p class="actions">
                @if ($canUpdate)
                    <button type="button" wire:click="edit('{{ $profile['uuid'] }}')">{{ __('Edit') }}</button>
                @endif
                @if ($canArchive)
                    @if ($profile['is_archived'])
                        <button type="button" wire:click="restore('{{ $profile['uuid'] }}')">{{ __('Restore') }}</button>
                    @else
                        <button type="button" wire:click="archive('{{ $profile['uuid'] }}')"
                            wire:confirm="{{ __('Archive this customer? Their history stays.') }}">
                            {{ __('Archive') }}
                        </button>
                    @endif
                @endif
                <button type="button" wire:click="closeProfile">{{ __('Close') }}</button>
            </p>

            {{--
                Bookings, visits, sales, loyalty and reviews are deliberately
                absent. Those modules do not exist, and an empty widget
                promising one makes the product look broken rather than unbuilt
                (docs/13-ROADMAP.md Phase 5 §17).
            --}}
        </div>

        @if ($canViewNotes)
            <h3>{{ __('Internal notes') }}</h3>
            <p class="sub">{{ __('Staff only. Customers never see these.') }}</p>

            <div class="card">
                @forelse ($profile['notes'] as $note)
                    <p>
                        {{ $note['body'] }}
                        <span class="tag">{{ $note['visibility'] === 'manager_only' ? __('Managers only') : __('Internal') }}</span>
                    </p>
                @empty
                    <p class="sub">{{ __('No notes yet.') }}</p>
                @endforelse

                @if ($canManageNotes)
                    <form wire:submit="addNote">
                        <label for="noteBody">{{ __('Add a note') }}</label>
                        <input id="noteBody" type="text" wire:model="noteBody"
                            placeholder="{{ __('Prefers a quiet appointment') }}">
                        @error('noteBody') <p class="error">{{ $message }}</p> @enderror

                        <select wire:model="noteVisibility" aria-label="{{ __('Visibility') }}">
                            <option value="internal">{{ __('All staff') }}</option>
                            <option value="manager_only">{{ __('Managers only') }}</option>
                        </select>

                        <button type="submit" class="btn">{{ __('Add note') }}</button>
                    </form>
                @endif
            </div>
        @endif
    @endif

    {{-- --------------------------------------------------------------- form --}}
    @if ($showForm && ($canCreate || $canUpdate))
        <h2>{{ $editing ? __('Edit customer') : __('Add a customer') }}</h2>

        <form wire:submit="save" class="card">
            <label for="cname">{{ __('Name') }}</label>
            <input id="cname" type="text" wire:model="name">
            @error('name') <p class="error">{{ $message }}</p> @enderror

            @if ($canSeeContact)
                <label for="cphone">{{ __('Phone') }}</label>
                <input id="cphone" type="text" wire:model="phone" placeholder="0750 123 4567">
                @error('phone') <p class="error">{{ $message }}</p> @enderror

                <label for="cemail">{{ __('Email') }}</label>
                <input id="cemail" type="email" wire:model="email">
                @error('email') <p class="error">{{ $message }}</p> @enderror
            @else
                <p class="sub">{{ __('You do not have permission to see or change contact details.') }}</p>
            @endif

            <label for="clocale">{{ __('Preferred language') }}</label>
            <select id="clocale" wire:model="preferredLocale">
                <option value="">{{ __('Not set') }}</option>
                @foreach ($locales as $locale)
                    <option value="{{ $locale }}">{{ $locale }}</option>
                @endforeach
            </select>

            <label for="cdob">{{ __('Date of birth') }}</label>
            <input id="cdob" type="date" wire:model="dateOfBirth">
            @error('dateOfBirth') <p class="error">{{ $message }}</p> @enderror

            @if ($tags->isNotEmpty())
                <label>{{ __('Tags') }}</label>
                @foreach ($tags as $t)
                    <label class="inline">
                        <input type="checkbox" value="{{ $t->uuid }}" wire:model="tagUuids">
                        {{ $t->name->get() }}
                    </label>
                @endforeach
            @endif

            <label class="inline">
                <input type="checkbox" wire:model="allowOperational"> {{ __('May receive operational messages') }}
            </label>
            <label class="inline">
                <input type="checkbox" wire:model="marketingOptIn"> {{ __('Opted in to marketing') }}
            </label>

            <button type="submit" class="btn">{{ __('Save customer') }}</button>
        </form>
    @endif
</div>
