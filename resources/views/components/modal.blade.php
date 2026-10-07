@props(['id','title'])
<dialog id="{{ $id }}" {{ $attributes->class('modal') }} aria-labelledby="{{ $id }}-title"><div class="section-heading"><h2 id="{{ $id }}-title">{{ $title }}</h2><button type="button" class="icon-btn" data-close-modal aria-label="إغلاق"><x-icon name="close"/></button></div>{{ $slot }}</dialog>
