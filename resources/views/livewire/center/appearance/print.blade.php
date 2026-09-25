{{--
    Manager → Appearance → Print. The center's own paper: 80mm receipt, A4
    invoice, queue ticket. Applied only when a document is rendered — an
    invoice is never rewritten. The preview uses the same printing/header and
    printing/footer partials as the real paper; its body is a neutral outline,
    never an invented item or amount.
--}}
<div class="stack appearance-page">
    @include('printing.styles')

    <x-ui.page-header :title="__('manager_appearance.print.title')">
        <x-slot:actions>
            @if ($canManage)
                <button class="button" type="button" wire:click="save" wire:loading.attr="data-loading" wire:target="save"><x-ui.icon name="save" size="16" />{{ __('manager_appearance.actions.save') }}</button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.notice :message="$notice" :tone="$noticeTone" dismiss="$set('notice', '')" />

    @unless ($canManage)
        <div class="notice" data-tone="info" role="status"><x-ui.icon name="lock" /><p>{{ __('manager_appearance.read_only') }}</p></div>
    @endunless

    <div class="appearance-layout">
        <div class="appearance-main stack">
            <x-ui.card :title="__('manager_appearance.print.logo.title')">
                @include('livewire.center.appearance.menu-controls.switch', ['key' => 'show_logo', 'label' => __('manager_appearance.print.logo.show'), 'help' => $hasLogo ? null : __('manager_appearance.print.logo.missing'), 'disabled' => ! $canManage])
                @if (! $hasLogo && $brandUrl)
                    <p class="field-help"><a href="{{ $brandUrl }}" wire:navigate>{{ __('manager_appearance.colours.open_brand') }}</a></p>
                @endif
                <div class="form-grid">
                    @foreach (['logo_size', 'logo_align'] as $choice)
                        @include('livewire.center.appearance.menu-controls.choice', ['key' => $choice, 'label' => __('manager_appearance.print.choices.'.$choice.'.label'), 'options' => $choices[$choice] ?? [], 'current' => (string) ($values[$choice] ?? ''), 'disabled' => ! $canManage, 'help' => null])
                    @endforeach
                </div>
            </x-ui.card>

            <x-ui.card :title="__('manager_appearance.print.details.title')" flush>
                @foreach (['show_center_name', 'show_branch_name', 'show_branch_address', 'show_branch_phone', 'show_customer_name'] as $flag)
                    @include('livewire.center.appearance.menu-controls.switch', ['key' => $flag, 'label' => __('manager_appearance.print.flags.'.$flag), 'help' => $flag === 'show_customer_name' ? __('manager_appearance.print.flags.customer_help') : null, 'disabled' => ! $canManage])
                @endforeach
            </x-ui.card>

            <x-ui.card :title="__('manager_appearance.print.paper.title')">
                <div class="form-grid">
                    @foreach (['document_language', 'receipt_text_size', 'a4_density'] as $choice)
                        @include('livewire.center.appearance.menu-controls.choice', ['key' => $choice, 'label' => __('manager_appearance.print.choices.'.$choice.'.label'), 'options' => $choices[$choice] ?? [], 'current' => (string) ($values[$choice] ?? ''), 'disabled' => ! $canManage, 'help' => $choice === 'document_language' ? __('manager_appearance.print.language_help') : null])
                    @endforeach
                </div>
            </x-ui.card>

            <x-ui.card :title="__('manager_appearance.print.ticket.title')" flush>
                <x-slot:actions><span class="info-tip" role="img" tabindex="0" title="{{ __('manager_appearance.print.ticket.description') }}" aria-label="{{ __('manager_appearance.print.ticket.description') }}"><x-ui.icon name="info" size="16" /></span></x-slot:actions>
                @foreach (['ticket_show_service', 'ticket_show_date'] as $flag)
                    @include('livewire.center.appearance.menu-controls.switch', ['key' => $flag, 'label' => __('manager_appearance.print.flags.'.$flag), 'help' => null, 'disabled' => ! $canManage])
                @endforeach
            </x-ui.card>

            <x-ui.card :title="__('manager_appearance.texts.title')">
                <x-slot:actions><span class="info-tip" role="img" tabindex="0" title="{{ __('manager_appearance.print.texts.description') }}" aria-label="{{ __('manager_appearance.print.texts.description') }}"><x-ui.icon name="info" size="16" /></span></x-slot:actions>
                <div @if (! $canManage) x-data x-readonly-fields @endif>
                    <x-ui.lang-tabs id="print-texts" :fields="$textFields" :values="$textValues" :locales="$contentLocales" :primary="$primaryLocale" live />
                </div>
            </x-ui.card>
        </div>

        <aside class="appearance-aside stack">
            <x-ui.card :title="__('manager_appearance.print.preview.title')">
                <div class="print-preview-controls">
                    <div class="segmented" role="group" aria-label="{{ __('manager_appearance.print.preview.paper') }}">
                        @foreach (['receipt', 'a4', 'ticket'] as $option)
                            <button type="button" wire:click="$set('paper', '{{ $option }}')" aria-pressed="{{ $paper === $option ? 'true' : 'false' }}">{{ __('manager_appearance.print.preview.papers.'.$option) }}</button>
                        @endforeach
                    </div>
                    @if (count($previewLocales) > 1)
                        <div class="segmented" role="group" aria-label="{{ __('manager_appearance.preview.language') }}">
                            @foreach ($previewLocales as $option)
                                <button type="button" wire:click="$set('previewLocale', '{{ $option['code'] }}')" aria-pressed="{{ $sampleLocale === $option['code'] ? 'true' : 'false' }}">{{ $option['label'] }}</button>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="print-paper" data-paper="{{ $paper }}" data-text-size="{{ $sample['text_size'] }}" data-density="{{ $sample['density'] }}" dir="{{ $sampleDirection }}" lang="{{ $sampleLocale }}" wire:loading.class="is-refreshing" wire:target="paper,previewLocale,values,texts">
                    @if ($paper === 'ticket')
                        @if ($sample['logo'])
                            <img class="print-logo" data-size="{{ $sample['logo']['size'] }}" src="{{ $sample['logo']['url'] }}" alt="">
                        @endif
                        @if ($sample['show_center_name'])<div class="print-paper__center">{{ $sampleDoc['center_name'] }}</div>@endif
                        @if ($sample['show_branch_name'] && $sampleDoc['branch_name'])<div>{{ $sampleDoc['branch_name'] }}</div>@endif
                        <div class="print-paper__number" aria-hidden="true">—</div>
                        @if ($sample['ticket_show_service'])<div class="print-paper__bar" aria-hidden="true"></div>@endif
                        <div class="print-paper__meta">{{ __('manager_appearance.print.preview.issued_time') }}@if ($sample['ticket_show_date']) · {{ __('manager_appearance.print.preview.issued_date') }}@endif</div>
                        <div class="print-paper__meta print-foot">{{ $sample['ticket_footer'] ?? __('queue_public.thank_you', [], $sampleLocale) }}</div>
                    @else
                        @include('printing.header', ['print' => $sample, 'doc' => $sampleDoc])
                        <div class="print-paper__body" aria-hidden="true">
                            <span class="print-paper__bar"></span>
                            <span class="print-paper__bar print-paper__bar--short"></span>
                            <span class="print-paper__bar"></span>
                            <span class="print-paper__bar print-paper__bar--total"></span>
                        </div>
                        <p class="print-paper__note">{{ __('manager_appearance.print.preview.body_note') }}</p>
                        @if ($sample['show_customer_name'])
                            <p class="print-paper__note">{{ __('manager_appearance.print.preview.customer_line') }}</p>
                        @endif
                        <footer class="print-foot">{{ $sample['footer'] ?? __('invoice_public.thank_you', [], $sampleLocale) }}</footer>
                    @endif
                </div>
            </x-ui.card>
            @if ($canManage)
                <x-ui.card>
                    <button class="button button--ghost button--sm" type="button" wire:click="restoreDefaults"
                            wire:confirm="{{ __('manager_appearance.restore_defaults_confirm') }}" data-confirm-title="{{ __('manager_appearance.restore_defaults') }}">
                        <x-ui.icon name="reset" size="16" />{{ __('manager_appearance.restore_defaults') }}
                    </button>
                </x-ui.card>
            @endif
        </aside>
    </div>
</div>
