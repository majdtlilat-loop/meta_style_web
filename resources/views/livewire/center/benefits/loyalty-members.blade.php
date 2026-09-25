{{-- Who holds points: name, balance, tier, what the balance is worth. Rows from LoyaltyPresenter::member(). --}}
<div>
    <x-ui.card :title="__('manager_benefits.loyalty.members_title')" flush>
        <div class="benefits-search">
            <label class="sr-only" for="loyalty-member-search">{{ __('ui.fields.search') }}</label>
            <div class="search-input">
                <x-ui.icon name="search" />
                <input id="loyalty-member-search" type="search" wire:model.live.debounce.400ms="search" placeholder="{{ __('manager_benefits.members.search') }}" autocomplete="off" maxlength="120">
            </div>
        </div>
        <div class="table-shell table-shell--stack" wire:loading.class="is-refreshing" wire:target="search,gotoPage,nextPage,previousPage">
            @if($members === [])
                <x-ui.empty-state compact icon="coins" :title="$search !== '' ? __('manager_benefits.members.no_match') : __('manager_benefits.loyalty.no_members')" />
            @else
                <table>
                    <caption class="sr-only">{{ __('manager_benefits.loyalty.members_title') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('manager_benefits.members.customer') }}</th>
                            <th scope="col">{{ __('manager_benefits.panel.tier') }}</th>
                            <th scope="col" class="numeric">{{ __('manager_benefits.panel.available') }}</th>
                            <th scope="col" class="numeric">{{ __('manager_benefits.panel.lifetime') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($members as $member)
                            <tr wire:key="member-{{ $member['customer']['uuid'] ?? $loop->index }}">
                                <td data-label="{{ __('manager_benefits.members.customer') }}" data-primary>
                                    @if($member['url'])
                                        <a class="cell-title" href="{{ $member['url'] }}" wire:navigate>{{ $member['customer']['name'] }}</a>
                                    @else
                                        <span class="cell-title">—</span>
                                    @endif
                                </td>
                                <td data-label="{{ __('manager_benefits.panel.tier') }}">
                                    @if($member['tier'])<span class="chip"><x-ui.icon name="star" size="12" />{{ $member['tier'] }}</span>@else<span class="muted">—</span>@endif
                                </td>
                                <td data-label="{{ __('manager_benefits.panel.available') }}" class="numeric">
                                    <strong class="tabular">{{ number_format((int) $member['balance']) }}</strong>
                                    @if($member['value'])<span class="cell-sub" dir="ltr">{{ $member['value']['formatted'] }}</span>@endif
                                </td>
                                <td data-label="{{ __('manager_benefits.panel.lifetime') }}" class="numeric"><span class="tabular">{{ number_format((int) $member['lifetime_points']) }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
        @if($page->hasPages())
            <footer class="card__footer">{{ $page->links() }}</footer>
        @endif
    </x-ui.card>
</div>
