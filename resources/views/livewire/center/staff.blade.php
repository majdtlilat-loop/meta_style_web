<div>
    <x-center-nav />

    <h1>{{ __('Staff') }}</h1>
    <p class="sub">{{ __('People who work here. A login is optional.') }}</p>

    @if ($activationToken)
        <div class="card" style="margin-bottom:1.5rem">
            <label>{{ __('Activation link code') }}</label>
            <p><code>{{ $activationToken }}</code></p>
            <p class="sub" style="margin-top:.5rem">
                {{ __('Shown once. Give this to the person so they can set their own password — you never see it.') }}
            </p>
        </div>
    @endif

    <div class="card" style="margin-bottom:1.5rem">
        <table>
            <thead>
                <tr>
                    <th>{{ __('Name') }}</th>
                    <th>{{ __('Login') }}</th>
                    <th>{{ __('Branches') }}</th>
                    <th>{{ __('Status') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($employees as $employee)
                    <tr>
                        <td>{{ $employee->name }}</td>
                        <td>{{ $employee->hasLogin() ? __('Yes') : __('No') }}</td>
                        <td>
                            @foreach ($employee->branches as $branch){{ $branch->name }}@if (! $loop->last), @endif @endforeach
                        </td>
                        <td><span class="pill">{{ $employee->status->value }}</span></td>
                        <td>
                            @if ($canDeactivate && $employee->status->isActive())
                                <button type="button" wire:click="deactivate({{ $employee->id }})">
                                    {{ __('Deactivate') }}
                                </button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="sub">{{ __('No staff yet.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($canCreate)
        <div class="card">
            <form wire:submit="create">
                <div class="field">
                    <label for="name">{{ __('Name') }}</label>
                    <input id="name" type="text" wire:model="name">
                    @error('name') <p class="error">{{ $message }}</p> @enderror
                </div>

                <div class="field">
                    <label for="email">{{ __('Email (optional — leave blank for no login)') }}</label>
                    <input id="email" type="email" wire:model="email">
                    @error('email') <p class="error">{{ $message }}</p> @enderror
                </div>

                <fieldset class="field">
                    <legend>{{ __('Branches') }}</legend>
                    <div class="checks">
                        @foreach ($branches as $branch)
                            <label>
                                <input type="checkbox" value="{{ $branch->id }}" wire:model="branchIds">
                                {{ $branch->name }}
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                <fieldset class="field">
                    <legend>{{ __('Roles') }}</legend>
                    <div class="checks">
                        @foreach ($roles as $role)
                            <label>
                                <input type="checkbox" value="{{ $role->id }}" wire:model="roleIds">
                                {{ $role->name }}
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                <button type="submit" class="btn">{{ __('Add staff member') }}</button>
            </form>
        </div>
    @endif
</div>
