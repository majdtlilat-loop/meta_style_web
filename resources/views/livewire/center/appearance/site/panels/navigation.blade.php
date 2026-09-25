@include('livewire.center.appearance.site.partials.link-list', [
    'list' => 'navigation',
    'items' => $content['navigation'],
    'max' => $choices['max']['navigation'],
    'title' => __('manager_site.panels.navigation'),
    'description' => __('manager_site.navigation.help', ['max' => $choices['max']['navigation']]),
])
