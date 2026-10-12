<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="app-base-url" content="{{ asset('/') }}">
<meta name="theme-color" content="#f5f5f4">
<title>{{ $title ?? 'مساحتك التعليمية' }} — {{ $siteBrand['name'] }}</title>
<link rel="icon" href="{{ $siteBrand['logo_url'] ?? asset('images/icon.svg') }}">
<link rel="manifest" href="{{ route('branding.manifest') }}">
<script nonce="{{ $cspNonce ?? '' }}">try{let t=localStorage.getItem('academy:theme');document.documentElement.classList.toggle('dark',t?t==='dark':matchMedia('(prefers-color-scheme: dark)').matches)}catch{}</script>
<script src="{{ asset('js/vendor/Sortable.min.js') }}"></script>
@vite(['resources/css/app.css', 'resources/js/app.js'])
