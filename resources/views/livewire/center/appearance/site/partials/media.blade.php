{{--
    One image or video slot. The upload itself is the SiteMediaSlot child;
    re-keyed on the uuid so a new file re-mounts it with its new preview.
    Params: $target (content path without `content.`), $kind, $label, $uuid, $poster (uuid or '').
--}}
<livewire:center.appearance.site-media-slot
    :target="$target"
    :kind="$kind"
    :label="$label"
    :url="$media[$uuid]['url'] ?? null"
    :poster="$media[$poster ?? '']['url'] ?? null"
    :can-manage="$canManage"
    :key="'slot-'.$target.'-'.($uuid ?: 'empty')" />
