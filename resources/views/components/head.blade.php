<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="app-base-url" content="{{ asset('/') }}">
<meta name="theme-color" content="#f5f5f4">
<title>{{ $title ?? 'مساحتك التعليمية' }} — عيادة التعلّم</title>
<link rel="icon" href="{{ asset('images/icon.svg') }}" type="image/svg+xml">
<link rel="manifest" href="{{ asset('manifest.json') }}">
<script nonce="{{ $cspNonce ?? '' }}">try{let t=localStorage.getItem('academy:theme');document.documentElement.classList.toggle('dark',t?t==='dark':matchMedia('(prefers-color-scheme: dark)').matches)}catch{}</script>
<script src="{{ asset('js/vendor/Sortable.min.js') }}"></script>
@vite(['resources/css/app.css', 'resources/js/app.js'])
