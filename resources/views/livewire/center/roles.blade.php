<div>
    <x-center-nav />

    <h1>{{ __('Roles') }}</h1>
    <p class="sub">{{ __('What each role may do. Roles are a convenience; permissions are the authorization.') }}</p>

    @error('selected') <p class="error">{{ $message }}</p> @enderror

    <div class="card">
        <table>
            <thead>
                <tr>
                    <th>{{ __('Role') }}</th>
                    <th>{{ __('Permissions') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($roles as $role)
                    <tr>
                        <td>
                            {{ $role->name }}
                            @if ($role->is_system) <span class="pill">{{ __('System') }}</span> @endif
                        </td>
                        <td>{{ $role->permissions->count() }}</td>
                        <td>
                            @if ($canManage)
                                <button type="button" wire:click="edit({{ $role->id }})">{{ __('Edit') }}</button>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if ($editingRoleId !== null)
        <div class="card" style="margin-top:1.5rem">
            <form wire:submit="save">
                <fieldset class="field">
                    <legend>{{ __('Permissions') }}</legend>
                    <div class="checks">
                        @foreach ($catalog as $permission)
                            <label>
                                <input type="checkbox" value="{{ $permission->value }}" wire:model="selected">
                                <span>{{ $permission->value }}</span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                <button type="submit" class="btn">{{ __('Save permissions') }}</button>
            </form>
        </div>
    @endif
</div>
