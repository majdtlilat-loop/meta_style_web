{{--
    Up to three files (PDF, PNG, JPEG, text; 5 MB each). Validated again in
    the component and by content in CenterSupportAttachments.
--}}
<div class="field support-files">
    <div class="support-files__row">
        <label for="{{ $inputId }}" class="button button--ghost button--sm"><x-ui.icon name="paperclip" size="16" />{{ __('manager_support.fields.attach') }}</label>
        <input id="{{ $inputId }}" class="sr-only" type="file" wire:model="files" multiple accept=".pdf,.png,.jpg,.jpeg,.txt" aria-describedby="{{ $inputId }}-help">
        <span class="field-help" id="{{ $inputId }}-help">{{ __('manager_support.fields.attach_help') }}</span>
        <span class="support-files__loading" wire:loading wire:target="files"><span class="spinner" aria-hidden="true"></span>{{ __('manager_support.fields.uploading') }}</span>
    </div>
    @if($files !== [])
        <ul class="chip-list support-files__list">
            @foreach($files as $index => $file)
                <li class="chip" wire:key="file-{{ $index }}">
                    <x-ui.icon name="file" size="14" /><span dir="ltr" class="truncate">{{ $file->getClientOriginalName() }}</span>
                    <button type="button" class="icon-button icon-button--xs" wire:click="removeFile({{ $index }})" aria-label="{{ __('manager_support.fields.remove_file', ['name' => $file->getClientOriginalName()]) }}"><x-ui.icon name="close" size="12" /></button>
                </li>
            @endforeach
        </ul>
    @endif
    @error('files')<p class="error" role="alert"><x-ui.icon name="alert-circle" size="14" />{{ $message }}</p>@enderror
    @foreach($errors->get('files.*') as $messages)
        @foreach($messages as $message)
            <p class="error" role="alert"><x-ui.icon name="alert-circle" size="14" />{{ $message }}</p>
        @endforeach
    @endforeach
</div>
