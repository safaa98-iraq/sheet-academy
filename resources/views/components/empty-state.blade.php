@props(['title'=>'لا يوجد محتوى بعد','description'=>'ستجد كل جديد هنا عندما يصبح متاحاً.','icon'=>'book'])
<section {{ $attributes->class('empty-state') }} role="status"><span class="empty-icon"><x-icon :name="$icon"/></span><h2>{{ $title }}</h2><p class="muted">{{ $description }}</p>{{ $slot }}</section>
