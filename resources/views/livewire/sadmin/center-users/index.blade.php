@php
    use App\Kernel\Platform\Directory\CenterUserSearch;
    use App\View\AuditAction;

    $locale = app()->getLocale();
    $pick = fn ($value) => is_array($value) ? ($value[$locale] ?? $value['en'] ?? (reset($value) ?: '')) : (string) $value;
    $date = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->translatedFormat('j M Y') : '—';
@endphp

<div class="stack">
    <x-ui.page-header :title="__('sadmin_center_users.title')" :subtitle="__('sadmin_center_users.description')">
        <x-slot:meta>
            <span class="result-count">{{ trans_choice('sadmin_center_users.results', $entries->total(), ['count' => number_format($entries->total())]) }}</span>
        </x-slot:meta>
        <x-slot:actions>
            <button class="button button--secondary" type="button" wire:click="refreshDirectory" wire:loading.attr="data-loading" wire:target="refreshDirectory"><x-ui.icon name="refresh" size="16" />{{ __('sadmin_center_users.refresh') }}</button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.flash />
    <x-ui.flash key="notice-error" tone="danger" />

    <div class="stat-strip">
        <div class="stat-strip__item"><span>{{ __('sadmin_center_users.stats.accounts') }}</span><strong>{{ number_format($total) }}</strong></div>
        <div class="stat-strip__item">
            <span>{{ __('phone_field.missing') }}</span>
            <strong>
                @if($missingPhones > 0)
                    <button class="cell-link" type="button" wire:click="$set('phone', 'missing')">{{ number_format($missingPhones) }}</button>
                @else
                    0
                @endif
            </strong>
        </div>
        <div class="stat-strip__item"><span>{{ __('sadmin_center_users.stats.projected') }}</span><strong>{{ $lastProjected ? \Illuminate\Support\Carbon::parse($lastProjected)->diffForHumans() : '—' }}</strong></div>
    </div>

    <form class="filter-bar" role="search" aria-label="{{ __('sadmin_center_users.filters.label') }}" x-on:submit.prevent data-collapsible x-data="{ open: false }" :class="{ 'is-open': open }">
        <div class="field filter-bar__search">
            <label for="cu-search">{{ __('sadmin_center_users.filters.search') }}</label>
            <div class="search-input">
                <x-ui.icon name="search" />
                <input id="cu-search" type="search" wire:model.live.debounce.350ms="search" placeholder="{{ __('sadmin_center_users.filters.search_placeholder') }}" autocomplete="off">
            </div>
        </div>
        <button class="button button--secondary filter-bar__toggle" type="button" x-on:click="open = ! open" :aria-expanded="open ? 'true' : 'false'" aria-controls="cu-center">
            <x-ui.icon name="filter" size="16" />{{ __('ui.actions.filters') }}
            @if($activeFilters > 0)<span class="badge">{{ $activeFilters }}</span>@endif
        </button>
        <div class="field">
            <label for="cu-center">{{ __('sadmin_center_users.filters.center') }}</label>
            <select id="cu-center" wire:model.live="center">
                <option value="">{{ __('sadmin_center_users.filters.all_centers') }}</option>
                @foreach($centers as $option)<option value="{{ $option->id }}">{{ $option->name }}</option>@endforeach
            </select>
        </div>
        <div class="field">
            <label for="cu-kind">{{ __('sadmin_center_users.filters.kind') }}</label>
            <select id="cu-kind" wire:model.live="kind">
                <option value="">{{ __('sadmin_center_users.filters.all') }}</option>
                @foreach(CenterUserSearch::KINDS as $option)<option value="{{ $option }}">{{ __('sadmin_center_users.kinds.'.$option) }}</option>@endforeach
            </select>
        </div>
        <div class="field">
            <label for="cu-role">{{ __('sadmin_center_users.filters.role') }}</label>
            <select id="cu-role" wire:model.live="role">
                <option value="">{{ __('sadmin_center_users.filters.all') }}</option>
                @foreach($roles as $option)<option value="{{ $option }}">{{ \Illuminate\Support\Facades\Lang::has('sadmin_center_users.roles.'.$option) ? __('sadmin_center_users.roles.'.$option) : $option }}</option>@endforeach
            </select>
        </div>
        @if($center !== '' && $branches->isNotEmpty())
            <div class="field">
                <label for="cu-branch">{{ __('sadmin_center_users.filters.branch') }}</label>
                <select id="cu-branch" wire:model.live="branch">
                    <option value="">{{ __('sadmin_center_users.filters.all') }}</option>
                    @foreach($branches as $option)<option value="{{ $option->branch_id }}">{{ $pick($option->branch_name ?? []) }}</option>@endforeach
                </select>
            </div>
        @endif
        <div class="field">
            <label for="cu-status">{{ __('sadmin_center_users.filters.status') }}</label>
            <select id="cu-status" wire:model.live="status">
                <option value="">{{ __('sadmin_center_users.filters.all') }}</option>
                <option value="active">{{ __('sadmin_center_users.status.active') }}</option>
                <option value="blocked">{{ __('sadmin_center_users.status.blocked') }}</option>
            </select>
        </div>
        <div class="field">
            <label for="cu-phone">{{ __('sadmin_center_users.filters.phone') }}</label>
            <select id="cu-phone" wire:model.live="phone">
                <option value="">{{ __('sadmin_center_users.filters.all') }}</option>
                <option value="missing">{{ __('phone_field.missing') }}</option>
                <option value="present">{{ __('sadmin_center_users.filters.phone_present') }}</option>
            </select>
        </div>
        <div class="field">
            <label for="cu-created">{{ __('sadmin_center_users.filters.created') }}</label>
            <select id="cu-created" wire:model.live="created">
                <option value="">{{ __('sadmin_center_users.filters.any_time') }}</option>
                @foreach(['7d', '30d', '365d'] as $option)<option value="{{ $option }}">{{ __('sadmin_center_users.filters.created_within.'.$option) }}</option>@endforeach
            </select>
        </div>
        <div class="field">
            <label for="cu-sort">{{ __('sadmin_center_users.filters.sort') }}</label>
            <select id="cu-sort" wire:model.live="sort">
                @foreach(CenterUserSearch::SORTS as $option)<option value="{{ $option }}">{{ __('sadmin_center_users.sorts.'.$option) }}</option>@endforeach
            </select>
        </div>
        <div class="filter-bar__actions">
            @if($activeFilters > 0 || $search !== '')
                <button class="button button--ghost button--sm" type="button" wire:click="resetFilters">{{ __('sadmin_center_users.filters.reset') }}</button>
            @endif
        </div>
    </form>

    <div class="card card--flush" wire:loading.class="is-refreshing" wire:target="search,center,kind,role,branch,status,phone,created,sort,gotoPage,nextPage,previousPage">
        @if($entries->isEmpty())
            <x-ui.empty-state icon="users" :title="__('sadmin_center_users.empty.title')" :description="$total === 0 ? __('sadmin_center_users.empty.no_projection') : __('sadmin_center_users.empty.no_match')" />
        @else
            <div class="table-shell table-shell--stack">
                <table>
                    <thead><tr>
                        <th scope="col">{{ __('sadmin_center_users.table.person') }}</th>
                        <th scope="col">{{ __('sadmin_center_users.table.phone') }}</th>
                        <th scope="col">{{ __('sadmin_center_users.table.center') }}</th>
                        <th scope="col">{{ __('sadmin_center_users.table.role') }}</th>
                        <th scope="col">{{ __('sadmin_center_users.table.branch') }}</th>
                        <th scope="col">{{ __('sadmin_center_users.table.status') }}</th>
                        <th scope="col">{{ __('sadmin_center_users.table.created') }}</th>
                        <th scope="col">{{ __('sadmin_center_users.table.last_login') }}</th>
                        <th scope="col" class="actions"><span class="sr-only">{{ __('ui.table.actions') }}</span></th>
                    </tr></thead>
                    <tbody>
                        @foreach($entries as $row)
                            <tr wire:key="cu-{{ $row['id'] }}">
                                <td data-label="{{ __('sadmin_center_users.table.person') }}" data-primary>
                                    <button class="cell-link cell-title" type="button" wire:click="show({{ $row['id'] }})">{{ $row['name'] }}</button>
                                    <span class="cell-sub" dir="ltr">{{ $row['contact_email'] ?? '—' }}</span>
                                </td>
                                <td data-label="{{ __('sadmin_center_users.table.phone') }}">
                                    @if($row['contact_phone'])
                                        <span class="flagged" dir="ltr"><img class="flag-icon" src="{{ $row['flag'] }}" alt="{{ $row['phone_country_name'] }}" width="20" height="15" loading="lazy"><span class="tabular">{{ $row['contact_phone'] }}</span></span>
                                    @else
                                        <x-ui.status tone="warning" :dot="false" :label="__('phone_field.missing')" />
                                    @endif
                                </td>
                                <td data-label="{{ __('sadmin_center_users.table.center') }}">
                                    @if($canCenter && $row['center'])
                                        <a class="cell-link" href="{{ route('superadmin.centers.show', ['tenant' => $row['tenant_id'], 'tab' => 'people']) }}" wire:navigate>{{ $row['center'] }}</a>
                                    @else
                                        <span>{{ $row['center'] ?? '—' }}</span>
                                    @endif
                                    <span class="cell-sub" dir="ltr">{{ $row['slug'] ?? '—' }}</span>
                                </td>
                                <td data-label="{{ __('sadmin_center_users.table.role') }}">
                                    <span>{{ $row['role'] }}</span>
                                    @if($row['role'] !== __('sadmin_center_users.kinds.'.$row['kind']))<span class="cell-sub">{{ __('sadmin_center_users.kinds.'.$row['kind']) }}</span>@endif
                                </td>
                                <td data-label="{{ __('sadmin_center_users.table.branch') }}">{{ $row['branches'] }}</td>
                                <td data-label="{{ __('sadmin_center_users.table.status') }}">
                                    <x-ui.status :value="$row['active'] ? 'active' : 'blocked'" :tone="$row['active'] ? 'success' : 'danger'" :label="$row['active'] ? __('sadmin_center_users.status.active') : __('sadmin_center_users.status.blocked')" />
                                </td>
                                <td data-label="{{ __('sadmin_center_users.table.created') }}">{{ $date($row['created']) }}</td>
                                <td data-label="{{ __('sadmin_center_users.table.last_login') }}">{{ $row['last_login'] ? $row['last_login']->diffForHumans() : __('sadmin_center_users.never') }}</td>
                                <td class="actions"><button class="button button--ghost button--sm" type="button" wire:click="show({{ $row['id'] }})">{{ __('sadmin_center_users.open') }}</button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{ $entries->links() }}

    @if($selected)
        <x-ui.drawer :title="$selected['name']" :description="$selected['center']" close="closeDetail" size="lg">
            <div class="stack">
                <div class="cluster">
                    <x-ui.status :value="$selected['active'] ? 'active' : 'blocked'" :tone="$selected['active'] ? 'success' : 'danger'" :label="$selected['active'] ? __('sadmin_center_users.status.active') : __('sadmin_center_users.status.blocked')" />
                    @if($selected['owner'])
                        <span class="chip">{{ __('sadmin_center_users.owner_protected') }}</span>
                    @else
                        <span class="chip">{{ __('sadmin_center_users.kinds.'.$selected['kind']) }}</span>
                    @endif
                </div>
                @unless($selected['contact_phone'])
                    <div class="notice" data-tone="warning"><x-ui.icon name="alert-triangle" /><p>{{ __('sadmin_center_users.missing_phone_note') }}</p></div>
                @endunless
                <dl class="kv-grid">
                    <div><dt>{{ __('sadmin_center_users.fields.name') }}</dt><dd>{{ $selected['name'] }}</dd></div>
                    <div><dt>{{ __('sadmin_center_users.fields.email') }}</dt><dd dir="ltr">{{ $selected['contact_email'] ?? '—' }}</dd></div>
                    <div><dt>{{ __('phone_field.label') }}</dt><dd dir="ltr">{{ $selected['contact_phone'] ?? __('phone_field.missing') }}</dd></div>
                    <div><dt>{{ __('phone_field.country') }}</dt><dd>@if($selected['flag'])<span class="flagged"><img class="flag-icon" src="{{ $selected['flag'] }}" alt="" width="20" height="15">{{ $selected['phone_country_name'] }}</span>@else — @endif</dd></div>
                    <div><dt>{{ __('sadmin_center_users.table.center') }}</dt><dd>{{ $selected['center'] ?? '—' }} <span class="cell-sub" dir="ltr">{{ $selected['slug'] }}</span></dd></div>
                    <div><dt>{{ __('sadmin_center_users.table.role') }}</dt><dd>{{ $selected['role'] }}</dd></div>
                    <div><dt>{{ __('sadmin_center_users.table.branch') }}</dt><dd>{{ $selected['branches'] }}</dd></div>
                    <div><dt>{{ __('sadmin_center_users.table.created') }}</dt><dd>{{ $date($selected['created']) }}</dd></div>
                    <div><dt>{{ __('sadmin_center_users.table.last_login') }}</dt><dd>{{ $selected['last_login'] ? $selected['last_login']->translatedFormat('j M Y, H:i') : __('sadmin_center_users.never') }}</dd></div>
                    <div><dt>{{ __('sadmin_center_users.fields.projected') }}</dt><dd>{{ $selected['projected']->diffForHumans() }}</dd></div>
                </dl>

                <section class="drawer-section">
                    <h3>{{ __('sadmin_center_users.audit') }}</h3>
                    @if($audit->isEmpty())
                        <p class="muted">{{ __('sadmin_center_users.no_audit') }}</p>
                    @else
                        <ul class="row-list">
                            @foreach($audit as $log)
                                <li class="row-list__item" wire:key="cu-audit-{{ $log->id }}">
                                    <div class="row-list__body">
                                        <span class="cell-title">{{ AuditAction::label($log->action) }}</span>
                                        <span class="cell-sub">{{ AuditAction::actor($log->actor_label, $log->actor_type) }} · {{ $log->occurred_at?->diffForHumans() }}</span>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            </div>
            <x-slot:footer>
                @if($canCenter)
                    <a class="button button--ghost" href="{{ route('superadmin.centers.show', ['tenant' => $selected['tenant_id']]) }}" wire:navigate><x-ui.icon name="centers" size="16" />{{ __('sadmin_center_users.inspect_center') }}</a>
                @endif
                @if($canManage)
                    @if($selected['active'] && $selected['contact_email'])
                        <button class="button button--secondary" type="button" wire:click="openPanel('link')"><x-ui.icon name="send" size="16" />{{ __('sadmin_center_users.actions.link') }}</button>
                    @endif
                    @if($selected['active'] && ! $selected['owner'])
                        <button class="button button--ghost button--danger-text" type="button" wire:click="openPanel('block')"><x-ui.icon name="lock" size="16" />{{ __('sadmin_center_users.actions.block') }}</button>
                    @elseif(! $selected['active'])
                        <button class="button button--secondary" type="button" wire:click="openPanel('reactivate')"><x-ui.icon name="unlock" size="16" />{{ __('sadmin_center_users.actions.reactivate') }}</button>
                    @endif
                    <button class="button" type="button" wire:click="openPanel('edit')"><x-ui.icon name="edit" size="16" />{{ $selected['contact_phone'] ? __('sadmin_center_users.actions.edit') : __('sadmin_center_users.actions.complete_phone') }}</button>
                @endif
            </x-slot:footer>
        </x-ui.drawer>

        @if($panel === 'edit')
            <x-ui.modal :title="__('sadmin_center_users.edit.title')" :description="__('sadmin_center_users.edit.body')" icon="user" submit="saveIdentity">
                <x-ui.field :label="__('sadmin_center_users.fields.name')" for="cu-edit-name" name="editName" required>
                    <input id="cu-edit-name" type="text" wire:model="editName" maxlength="190" required>
                </x-ui.field>
                <x-ui.field :label="__('sadmin_center_users.fields.email')" for="cu-edit-email" name="editEmail">
                    <input id="cu-edit-email" type="email" dir="ltr" wire:model="editEmail" maxlength="190">
                </x-ui.field>
                <x-ui.phone number="editPhone" country="editPhoneCountry" id="cu-edit-phone" :label="__('phone_field.label_required')" required />
                <x-slot:footer>
                    <button class="button button--secondary" type="button" wire:click="closePanel">{{ __('ui.actions.cancel') }}</button>
                    <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="saveIdentity">{{ __('sadmin_center_users.edit.save') }}</button>
                </x-slot:footer>
            </x-ui.modal>
        @elseif($panel === 'block' || $panel === 'reactivate')
            @php $blocking = $panel === 'block'; @endphp
            <x-ui.modal :title="$blocking ? __('sadmin_center_users.block.title') : __('sadmin_center_users.reactivate.title')" :description="$blocking ? __('sadmin_center_users.block.body') : __('sadmin_center_users.reactivate.body')" :icon="$blocking ? 'lock' : 'unlock'" :tone="$blocking ? 'danger' : null" :submit="$blocking ? 'setActive(false)' : 'setActive(true)'">
                <x-ui.field :label="__('sadmin_center_users.fields.reason')" for="cu-reason" name="reason" required>
                    <textarea id="cu-reason" rows="2" wire:model="reason" required></textarea>
                </x-ui.field>
                <x-slot:footer>
                    <button class="button button--secondary" type="button" wire:click="closePanel">{{ __('ui.actions.cancel') }}</button>
                    <button class="button {{ $blocking ? 'button--danger' : '' }}" type="submit" wire:loading.attr="data-loading" wire:target="setActive">{{ $blocking ? __('sadmin_center_users.actions.block') : __('sadmin_center_users.actions.reactivate') }}</button>
                </x-slot:footer>
            </x-ui.modal>
        @elseif($panel === 'link')
            <x-ui.modal :title="__('sadmin_center_users.link.title')" :description="__('sadmin_center_users.link.body', ['email' => $selected['contact_email']])" icon="send" submit="sendLink">
                <x-slot:footer>
                    <button class="button button--secondary" type="button" wire:click="closePanel">{{ __('ui.actions.cancel') }}</button>
                    <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="sendLink">{{ __('sadmin_center_users.actions.link') }}</button>
                </x-slot:footer>
            </x-ui.modal>
        @endif
    @endif
</div>
