@props(['label'])
<details {{ $attributes->class('dropdown') }}><summary>{{ $label }} <x-icon name="chevron-down"/></summary><div class="dropdown-menu">{{ $slot }}</div></details>
