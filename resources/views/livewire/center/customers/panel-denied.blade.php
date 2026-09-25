{{-- A customer-page panel the viewer may not read. The server refused it; this only says so. --}}
<div>
    <x-ui.card>
        <x-ui.empty-state compact icon="lock" :title="__('manager_customers.panels.denied_title')" />
    </x-ui.card>
</div>
