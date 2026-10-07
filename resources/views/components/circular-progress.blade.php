@props(['value'=>0])
<div {{ $attributes->class('circular-progress') }} style="--progress:{{ $value }}%" role="progressbar" aria-label="التقدم" aria-valuenow="{{ $value }}" aria-valuemin="0" aria-valuemax="100"><span>{{ $value }}٪</span></div>
