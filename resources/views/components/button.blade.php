@props(['href'=>null,'primary'=>false])
@if($href)<a href="{{ $href }}" {{ $attributes->class(['btn','primary'=>$primary]) }}>{{ $slot }}</a>
@else<button {{ $attributes->merge(['type'=>'button'])->class(['btn','primary'=>$primary]) }}>{{ $slot }}</button>@endif
