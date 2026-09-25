{{-- Who holds a membership in force or starting soon. Rows from MembershipsPresenter::members(). --}}
<div>
    <x-ui.card :title="__('manager_benefits.plans.members_title')" flush>
        <div class="benefits-search">
            <label class="sr-only" for="membership-member-search">{{ __('ui.fields.search') }}</label>
            <div class="search-input">
                <x-ui.icon name="search" />
                <input id="membership-member-search" type="search" wire:model.live.debounce.400ms="search" placeholder="{{ __('manager_benefits.members.search') }}" autocomplete="off" maxlength="120">
            </div>
        </div>
        <div class="table-shell table-shell--stack" wire:loading.class="is-refreshing" wire:target="search,gotoPage,nextPage,previousPage">
            @if($members === [])
                <x-ui.empty-state compact icon="memberships" :title="$search !== '' ? __('manager_benefits.members.no_match') : __('manager_benefits.plans.no_members')" />
            @else
                <table>
                    <caption class="sr-only">{{ __('manager_benefits.plans.members_title') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('manager_benefits.members.customer') }}</th>
                            <th scope="col">{{ __('manager_benefits.members.plan') }}</th>
                            <th scope="col">{{ __('manager_benefits.common.status') }}</th>
                            <th scope="col">{{ __('manager_benefits.members.until') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($members as $member)
                            <tr wire:key="membership-member-{{ $member['uuid'] }}">
                                <td data-label="{{ __('manager_benefits.members.customer') }}" data-primary>
                                    @if($member['url'])<a class="cell-title" href="{{ $member['url'] }}" wire:navigate>{{ $member['customer']['name'] }}</a>@else<span class="cell-title">—</span>@endif
                                </td>
                                <td data-label="{{ __('manager_benefits.members.plan') }}">{{ $member['name'] }}</td>
                                <td data-label="{{ __('manager_benefits.common.status') }}"><x-ui.status :value="$member['state']" :label="$member['state_label']" /></td>
                                <td data-label="{{ __('manager_benefits.members.until') }}">{{ $member['last_day_label'] }}</td>
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
