@props(['available' => []])
<div {{ $attributes->merge(['class' => 'player-quality']) }}>
    <button type="button" class="player-control quality-trigger" data-quality-toggle aria-expanded="false" aria-controls="player-quality-options" aria-label="إعدادات جودة الفيديو" title="جودة الفيديو">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="m9 3-1 3-3 1-2 3 2 2-1 3 2 3 3-1 3 2 3-2 3 1 2-3-1-3 2-2-2-3-3-1-1-3Z"/><circle cx="12" cy="11" r="3"/></svg>
        <span data-quality-label>تلقائي</span>
    </button>
    <div id="player-quality-options" class="quality-popover" data-quality-options hidden role="group" aria-label="جودة الفيديو">
        <strong>جودة الفيديو</strong>
        @foreach(['auto' => 'تلقائي', '1080' => '1080p', '720' => '720p', '480' => '480p', '360' => '360p', '240' => '240p'] as $quality => $label)
            @if($quality === 'auto' || in_array((int) $quality, $available ?? [], true))
            <button type="button" data-quality-value="{{ $quality }}" aria-pressed="{{ $quality === 'auto' ? 'true' : 'false' }}">
                <span>{{ $label }} @if($quality === 'auto')<small data-auto-quality>(720p)</small>@endif</span><span data-quality-check aria-hidden="true">{{ $quality === 'auto' ? '✓' : '' }}</span>
            </button>
            @endif
        @endforeach
    </div>
</div>
