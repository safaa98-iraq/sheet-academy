@if($node['type'] === 'lesson' && isset($node['lesson']))
    @php($lesson = $node['lesson'])
    <a class="curriculum-lesson {{ (string)$lesson['id'] === (string)$currentId ? 'is-current' : '' }}" href="{{ $lesson['url'] }}" data-curriculum-lesson="{{ $lesson['id'] }}" @if((string)$lesson['id'] === (string)$currentId) aria-current="page" @endif>
        <span class="lesson-check" data-lesson-check aria-hidden="true"></span><div><strong>{{ $lesson['title'] }}</strong><small>{{ $lesson['type'] === 'video' ? 'فيديو' : 'محتوى الدرس' }} · {{ gmdate('i:s', (int)$lesson['duration']) }}</small></div>
    </a>
@else
    <details class="curriculum-section" data-curriculum-group open>
        <summary><span class="curriculum-chevron" aria-hidden="true">⌄</span><div><strong>{{ $node['title'] }}</strong><small><span data-curriculum-group-percent>{{ $node['progress_percent'] ?? 0 }}</span>٪ مكتمل</small></div></summary>
        @foreach($node['children'] as $child)@include('student._player-curriculum-node', ['node'=>$child, 'currentId'=>$currentId])@endforeach
    </details>
@endif
