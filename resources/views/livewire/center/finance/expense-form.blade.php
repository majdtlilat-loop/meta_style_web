{{-- Recording one expense. The Action checks every value again. --}}
<x-ui.drawer :title="__('Record an expense')" close="closeForm" submit="post">
    <div class="stack">
        @if($error !== '')<div class="notice" data-tone="danger" role="alert"><x-ui.icon name="alert-circle" /><p>{{ $error }}</p></div>@endif

        @if($categories === [])
            <div class="notice" data-tone="info" role="note">
                <x-ui.icon name="info" />
                <p>{{ __('manager_finance.expenses.need_category') }}</p>
                <button class="button button--secondary button--sm" type="button" wire:click="openCategories">{{ __('Expense categories') }}</button>
            </div>
        @endif

        <div class="form-grid">
            <x-ui.field :label="__('Category')" for="expense-category" name="category" required>
                <select id="expense-category" wire:model="category" required>
                    <option value="">{{ __('Choose…') }}</option>
                    @foreach($categories as $option)<option value="{{ $option['uuid'] }}">{{ $option['name'] }}</option>@endforeach
                </select>
            </x-ui.field>
            <x-ui.field :label="__('Amount')" for="expense-amount" name="amount" required>
                <input id="expense-amount" type="text" dir="ltr" inputmode="decimal" wire:model="amount" placeholder="25000" required autocomplete="off">
            </x-ui.field>
        </div>
        <x-ui.field :label="__('What it was for')" for="expense-description" name="description" required>
            <input id="expense-description" type="text" wire:model="description" maxlength="500" required>
        </x-ui.field>
        <div class="form-grid">
            <x-ui.field :label="__('manager_finance.expenses.paid_on')" for="expense-date" name="occurredOn" required>
                <input id="expense-date" type="date" wire:model="occurredOn" max="{{ $today }}" required>
            </x-ui.field>
            <fieldset class="field">
                <legend>{{ __('How') }}</legend>
                <div class="segmented" role="radiogroup">
                    @foreach($methods as $option)
                        <label @class(['is-active' => $method === $option['value']])><input class="sr-only" type="radio" value="{{ $option['value'] }}" wire:model.live="method">{{ $option['label'] }}</label>
                    @endforeach
                </div>
            </fieldset>
        </div>
        @if($method === 'cash')
            <label class="choice choice--switch"><input class="switch" type="checkbox" role="switch" wire:model="fromDrawer"><span>{{ __('Paid from my open cash drawer') }}</span></label>
        @endif
        <div class="form-grid">
            <x-ui.field :label="__('Paid to (optional)')" for="expense-payee" name="payee">
                <input id="expense-payee" type="text" wire:model="payee" maxlength="120">
            </x-ui.field>
            <x-ui.field :label="__('Reference (optional)')" for="expense-reference" name="reference">
                <input id="expense-reference" type="text" dir="ltr" wire:model="reference" maxlength="120">
            </x-ui.field>
        </div>
    </div>
    <x-slot:footer>
        <button class="button button--secondary" type="button" wire:click="closeForm">{{ __('Cancel') }}</button>
        <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="post">{{ __('Post expense') }}</button>
    </x-slot:footer>
</x-ui.drawer>
