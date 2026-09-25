@php
    use App\Modules\LandingCms\Domain\LandingContent;

    $footer = $content['footer'];
    $linkRow = function (string $path, array $link, string $group, int $index) {
        return compact('path', 'link', 'group', 'index');
    };
@endphp
<x-ui.card :title="__('sadmin_cms.panels.footer')">
    <x-slot:actions>
        <label class="choice choice--switch"><input class="switch" type="checkbox" role="switch" wire:model.live="content.footer.enabled"><span>{{ __('sadmin_cms.fields.show_section') }}</span></label>
    </x-slot:actions>
    <div class="stack">
        <label class="choice choice--switch"><input class="switch" type="checkbox" role="switch" wire:model="content.footer.show_logo"><span>{{ __('sadmin_cms.fields.show_logo') }}</span></label>
        <x-ui.lang-tabs id="footer-text" primary="en" :values="['content.footer.description' => $footer['description'], 'content.footer.address' => $footer['address'], 'content.footer.copyright' => $footer['copyright']]" :fields="[
            ['name' => 'content.footer.description', 'label' => __('sadmin_cms.fields.description'), 'type' => 'textarea', 'rows' => 2, 'max' => 600],
            ['name' => 'content.footer.address', 'label' => __('sadmin_cms.fields.address'), 'max' => 300],
            ['name' => 'content.footer.copyright', 'label' => __('sadmin_cms.fields.copyright'), 'max' => 190],
        ]" />
        <div class="cms-grid-2">
            <div class="field">
                <label for="footer-email">{{ __('sadmin_cms.fields.email') }}</label>
                <input id="footer-email" type="email" dir="ltr" wire:model="content.footer.email" autocomplete="off">
            </div>
            <div class="field">
                <label for="footer-phone">{{ __('sadmin_cms.fields.phone') }}</label>
                <input id="footer-phone" type="tel" dir="ltr" wire:model="content.footer.phone" autocomplete="off">
            </div>
        </div>
    </div>
</x-ui.card>

<x-ui.card :title="__('sadmin_cms.fields.social_links')">
    <x-slot:actions>
        <button class="button button--secondary button--sm" type="button" wire:click="addFooterLink('social_links')" @disabled(count($footer['social_links']) >= 9)><x-ui.icon name="plus" size="16" />{{ __('sadmin_cms.actions.add_link') }}</button>
    </x-slot:actions>
    <div class="cms-item-list">
        @forelse($footer['social_links'] as $index => $link)
            <div class="cms-row" wire:key="social-{{ $index }}-{{ count($footer['social_links']) }}">
                <select wire:model="content.footer.social_links.{{ $index }}.network" aria-label="{{ __('sadmin_cms.fields.network') }}">
                    @foreach(LandingContent::SOCIAL_NETWORKS as $network)<option value="{{ $network }}">{{ __('sadmin_cms.networks.'.$network) }}</option>@endforeach
                </select>
                <input dir="ltr" wire:model="content.footer.social_links.{{ $index }}.url" placeholder="https://" aria-label="{{ __('sadmin_cms.fields.url') }}">
                <label class="switch-label"><input class="switch" type="checkbox" role="switch" wire:model="content.footer.social_links.{{ $index }}.enabled" aria-label="{{ __('sadmin_cms.fields.enabled') }}"></label>
                <button class="icon-button icon-button--sm icon-button--danger" type="button" wire:click="removeFooterLink('social_links', {{ $index }})" aria-label="{{ __('sadmin_cms.actions.remove') }}" title="{{ __('sadmin_cms.actions.remove') }}"><x-ui.icon name="trash" /></button>
            </div>
        @empty
            <p class="muted">{{ __('sadmin_cms.empty.links') }}</p>
        @endforelse
    </div>
</x-ui.card>

<x-ui.card :title="__('sadmin_cms.fields.link_groups')">
    <x-slot:actions>
        <button class="button button--secondary button--sm" type="button" wire:click="addFooterGroup" @disabled(count($footer['groups']) >= 4)><x-ui.icon name="plus" size="16" />{{ __('sadmin_cms.actions.add_group') }}</button>
    </x-slot:actions>
    <div class="stack">
        @forelse($footer['groups'] as $groupIndex => $group)
            <section class="cms-group" wire:key="footer-group-{{ $groupIndex }}-{{ count($footer['groups']) }}">
                <header class="cms-group__head">
                    <x-ui.lang-tabs :id="'group-'.$groupIndex" primary="en" :values="['content.footer.groups.'.$groupIndex.'.title' => $group['title']]" :fields="[
                        ['name' => 'content.footer.groups.'.$groupIndex.'.title', 'label' => __('sadmin_cms.fields.group_title'), 'max' => 80, 'required' => true],
                    ]" />
                    <button class="icon-button icon-button--sm icon-button--danger" type="button" wire:click="removeFooterGroup({{ $groupIndex }})" wire:confirm="{{ __('sadmin_cms.remove_confirm') }}" data-confirm-tone="danger" aria-label="{{ __('sadmin_cms.actions.remove') }}"><x-ui.icon name="trash" /></button>
                </header>
                @foreach($group['links'] as $index => $link)
                    @include('livewire.sadmin.cms.partials.link', ['path' => 'content.footer.groups.'.$groupIndex.'.links.'.$index, 'link' => $link, 'remove' => 'removeGroupLink('.$groupIndex.', '.$index.')', 'key' => 'g'.$groupIndex.'-'.$index.'-'.count($group['links'])])
                @endforeach
                <button class="button button--ghost button--sm" type="button" wire:click="addGroupLink({{ $groupIndex }})" @disabled(count($group['links']) >= 8)><x-ui.icon name="plus" size="16" />{{ __('sadmin_cms.actions.add_link') }}</button>
            </section>
        @empty
            <p class="muted">{{ __('sadmin_cms.empty.groups') }}</p>
        @endforelse
    </div>
</x-ui.card>

@foreach(['navigation' => 'quick_links', 'legal_links' => 'legal_links'] as $group => $title)
    <x-ui.card :title="__('sadmin_cms.fields.'.$title)">
        <x-slot:actions>
            <button class="button button--secondary button--sm" type="button" wire:click="addFooterLink('{{ $group }}')"><x-ui.icon name="plus" size="16" />{{ __('sadmin_cms.actions.add_link') }}</button>
        </x-slot:actions>
        <div class="cms-item-list">
            @forelse($footer[$group] as $index => $link)
                @include('livewire.sadmin.cms.partials.link', ['path' => 'content.footer.'.$group.'.'.$index, 'link' => $link, 'remove' => "removeFooterLink('".$group."', ".$index.')', 'key' => $group.'-'.$index.'-'.count($footer[$group])])
            @empty
                <p class="muted">{{ __('sadmin_cms.empty.links') }}</p>
            @endforelse
        </div>
    </x-ui.card>
@endforeach
