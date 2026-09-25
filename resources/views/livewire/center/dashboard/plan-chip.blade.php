{{-- The center's own plan: a translated status (never the raw value), its name and what matters next. --}}
<x-ui.icon name="subscriptions" size="14" />
<span class="dash-plan__name">{{ $plan['name'] }}</span>
<x-ui.status :value="$plan['status']" :label="$plan['label']" />
@if($plan['detail'])<span class="dash-plan__detail">{{ $plan['detail'] }}</span>@endif
