@props(['value'=>0,'label'=>'نسبة الإكمال'])
<div {{ $attributes->class('progress-line') }} role="progressbar" aria-label="{{ $label }}" aria-valuenow="{{ $value }}" aria-valuemin="0" aria-valuemax="100"><i style="width:{{ max(0,min(100,$value)) }}%"></i></div>
