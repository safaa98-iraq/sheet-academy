@props(['src' => null, 'title' => '', 'variant' => 'anatomy', 'loading' => 'lazy'])
<span {{ $attributes->class(['course-image']) }}>
    @if($src)<img src="{{ $src }}" alt="غلاف مادة {{ $title }}" loading="{{ $loading }}" decoding="async" draggable="false">@else<x-course-art :variant="$variant"/>@endif
</span>
