@props(['variant'=>'anatomy'])
<svg {{ $attributes->class('course-art') }} viewBox="0 0 480 280" fill="none" aria-hidden="true">
<g opacity=".16" stroke="currentColor"><path d="M0 70h480M0 140h480M0 210h480M80 0v280M160 0v280M240 0v280M320 0v280M400 0v280"/><circle cx="310" cy="140" r="114"/><circle cx="310" cy="140" r="87"/></g>
@if($variant==='anatomy')
<g transform="translate(222 20)" stroke="currentColor" stroke-width="2"><path d="M60 177c-14-11-37-9-42-36L4 119l18-16C8 41 47 8 96 11c46 2 77 43 68 89-3 18-23 33-28 53l-2 68H69l-9-44Z" fill="currentColor" fill-opacity=".07"/><path d="m23 101 29 2 17 26-7 29-26 2m-13-42 29 9 10 31m0 0 36-6 16-24 6-40-25-20-35 8-9 27m68-13 27-14m-18 57 19 14m-50 7 3 38m-30-23 59 24M84 20l-10 37 21 13m41-32-17 23M74 57l-34 5"/><ellipse cx="47" cy="99" rx="13" ry="12"/><path d="M25 136h22m-16 0v9m8-9v10m8-10v9"/></g>
@elseif($variant==='dental')
<g transform="translate(230 34)" stroke="currentColor" stroke-width="2"><path d="M25 15C-9 40 5 89 22 121c13 27 11 92 32 86 15-4 15-77 35-79 22-2 23 80 39 79 25-2 18-63 36-106C192 35 158-2 128 8 94 23 82 18 59 9 45 4 32 8 25 15Z" fill="currentColor" fill-opacity=".09"/><path d="M33 31c35 11 63 7 100-2M38 63c10 29 17 38 24 62m63-69-23 58M76 48l12 41M52 174l7-64m66 59-11-62"/></g>
@elseif($variant==='pharma')
<g stroke="currentColor" stroke-width="2"><g transform="translate(233 44) rotate(30 60 90)"><rect width="105" height="190" rx="52" fill="currentColor" fill-opacity=".08"/><path d="M0 95h105"/><path d="M22 61c0-23 12-36 30-38"/></g><circle cx="390" cy="70" r="22"/><path d="m375 55 30 30M202 181l-30 35m0-35 30 35"/></g>
@else
<g transform="translate(188 35)" stroke="currentColor" stroke-width="2"><rect x="10" y="0" width="228" height="211" rx="10" fill="currentColor" fill-opacity=".04"/><path d="M40 60q85-69 165 0M37 113q83 107 172 0"/><path d="m49 55 12 35 16-4-2-44m13-5 1 43 17-2 3-47m13 0 2 46 17 2 5-41m11 4-4 42 16 4 17-35M48 120l18 39 17 8-6-39m13 5 5 43 18 3-3-42m13 0 2 42 17-3 9-42m12-7-7 41 17-10 22-40"/></g>
@endif
<path d="M22 23h30M22 23v30M458 257h-30m30 0v-30" stroke="currentColor" opacity=".45"/>
</svg>
