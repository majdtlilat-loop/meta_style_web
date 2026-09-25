{{--
    The customer create / edit drawer, shared by the CRM list and the profile.

    The phone is the shared picker (country + national number, Iraq by
    default). A number that already belongs to someone is refused by the
    Action, and the drawer offers to open that customer instead — one person,
    one record (ADR-041). Contact fields exist only for staff who may see them.
--}}
@if($showForm && ($editing ? $canUpdate : $canCreate))
    <x-ui.drawer :title="$editing ? __('manager_customers.form.edit_title') : __('manager_customers.form.add_title')" close="closeForm" submit="save">
        <div class="stack">
            <x-ui.field :label="__('manager_customers.fields.name')" for="customer-name" name="name" required>
                <input id="customer-name" type="text" wire:model="name" autocomplete="off" maxlength="190" required>
            </x-ui.field>

            @if($canSeeContact)
                <x-ui.phone number="phone" country="phoneCountry" id="customer-phone" :label="__('manager_customers.fields.phone')" />

                @if($duplicate)
                    <div class="notice crm-duplicate" data-tone="warning" role="status">
                        <x-ui.icon name="user-check" />
                        <p>{{ __('manager_customers.form.duplicate', ['name' => $duplicate['name']]) }}</p>
                        <a class="button button--secondary button--sm" href="{{ route('center.customers.show', ['uuid' => $duplicate['uuid']]) }}" wire:navigate>{{ __('manager_customers.form.open_existing') }}</a>
                    </div>
                @endif

                <x-ui.field :label="__('manager_customers.fields.email')" for="customer-email" name="email">
                    <input id="customer-email" type="email" dir="ltr" wire:model="email" autocomplete="off" maxlength="190">
                </x-ui.field>
            @else
                <div class="notice" data-tone="info"><x-ui.icon name="lock" /><p>{{ __('manager_customers.form.contact_locked') }}</p></div>
            @endif

            <div class="form-grid">
                <x-ui.field :label="__('manager_customers.fields.language')" for="customer-locale" name="preferredLocale">
                    <select id="customer-locale" wire:model="preferredLocale">
                        <option value="">{{ __('manager_customers.form.language_default') }}</option>
                        @foreach($languageOptions as $option)
                            <option value="{{ $option['code'] }}">{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field :label="__('manager_customers.fields.date_of_birth')" for="customer-dob" name="dateOfBirth">
                    <input id="customer-dob" type="date" wire:model="dateOfBirth" max="{{ $today }}">
                </x-ui.field>
            </div>

            @if($tagOptions !== [])
                <fieldset class="field">
                    <legend>{{ __('manager_customers.fields.tags') }}</legend>
                    <div class="chip-select">
                        @foreach($tagOptions as $option)
                            <label class="chip-toggle"><input type="checkbox" value="{{ $option['uuid'] }}" wire:model="tagUuids"><span>{{ $option['name'] }}</span></label>
                        @endforeach
                    </div>
                </fieldset>
            @endif

            <fieldset class="field">
                <legend>{{ __('manager_customers.fields.messages') }}</legend>
                <label class="choice choice--switch"><input class="switch" type="checkbox" role="switch" wire:model="allowOperational"><span>{{ __('manager_customers.form.operational') }}</span></label>
                <label class="choice choice--switch"><input class="switch" type="checkbox" role="switch" wire:model="marketingOptIn"><span>{{ __('manager_customers.form.marketing') }}</span></label>
            </fieldset>
        </div>
        <x-slot:footer>
            <button class="button button--secondary" type="button" wire:click="closeForm">{{ __('ui.actions.cancel') }}</button>
            <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="save">{{ $editing ? __('ui.actions.save_changes') : __('manager_customers.form.create') }}</button>
        </x-slot:footer>
    </x-ui.drawer>
@endif
