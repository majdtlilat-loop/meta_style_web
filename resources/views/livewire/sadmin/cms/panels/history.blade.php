@php
    $authors = \App\Kernel\Platform\Identity\Models\PlatformUser::query()->whereIn('id', $revisions->pluck('created_by_id')->filter(fn ($id) => ctype_digit((string) $id)))->pluck('name', 'id');
@endphp
<x-ui.card :title="__('sadmin_cms.panels.history')" flush>
    @if($revisions->isEmpty())
        <x-ui.empty-state compact icon="history" :title="__('sadmin_cms.revisions.empty')" />
    @else
        <ul class="row-list">
            @foreach($revisions as $revision)
                <li class="row-list__item" wire:key="revision-{{ $revision->id }}">
                    <div class="row-list__body">
                        <span class="cell-title">
                            {{ __('sadmin_cms.revisions.version', ['version' => $revision->version]) }}
                            @if((int) $revision->version === (int) $page->published_version)<x-ui.status value="published" :label="__('sadmin_cms.revisions.live')" :dot="false" />@endif
                            @if((int) $revision->version === (int) $page->draft_version && (int) $page->draft_version !== (int) $page->published_version)<x-ui.status value="draft" :label="__('sadmin_cms.revisions.draft')" :dot="false" />@endif
                        </span>
                        <span class="cell-sub">{{ \Illuminate\Support\Carbon::parse($revision->created_at)->translatedFormat('j M Y, H:i') }} · {{ $authors[$revision->created_by_id] ?? __('sadmin_cms.revisions.unknown') }}</span>
                    </div>
                    <button class="button button--secondary button--sm" type="button" wire:click="loadRevision({{ $revision->id }})" wire:confirm="{{ __('sadmin_cms.revisions.load_confirm', ['version' => $revision->version]) }}"><x-ui.icon name="undo" size="16" />{{ __('sadmin_cms.revisions.load') }}</button>
                </li>
            @endforeach
        </ul>
    @endif
</x-ui.card>
