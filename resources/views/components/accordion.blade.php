@props(['title','open'=>false])
<details {{ $attributes->class('accordion') }} @if($open) open @endif><summary>{{ $title }}<x-icon name="chevron-down"/></summary><div class="accordion-body">{{ $slot }}</div></details>
