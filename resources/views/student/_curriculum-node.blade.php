@if($node['type']==='lesson' && isset($node['lesson']))
    @php($lesson=$node['lesson'])
    <a class="curriculum-student-lesson" href="{{ $lesson['url'] }}">
        <x-icon :name="$lesson['type']==='video'?'play':'file'"/>
        <span>{{ $lesson['title'] }}</span><small dir="ltr">{{ gmdate('i:s',(int)$lesson['duration']) }}</small>
        <span data-lesson-complete="{{ $lesson['id'] }}" class="lesson-check">{{ $lesson['completed']?'✓':'' }}</span>
    </a>
@else
    <details class="curriculum-student-node" @if($node['type']==='part') open @endif>
        <summary>{{ $node['title'] }}</summary>
        <div class="curriculum-student-tree">
            @foreach($node['children'] as $child)@include('student._curriculum-node',['node'=>$child])@endforeach
        </div>
    </details>
@endif
