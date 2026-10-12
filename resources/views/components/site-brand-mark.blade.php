@if($siteBrand['logo_url'] ?? null)<img class="site-brand-logo" src="{{ $siteBrand['logo_url'] }}" alt="{{ $siteBrand['name'] }}">@else<x-icon name="tooth"/>@endif
