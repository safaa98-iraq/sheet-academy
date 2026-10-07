@props(['state'=>'ready'])
@if($state==='loading')<div class="content-wrap"><x-skeleton-loader/><a class="btn state-return" href="{{ request()->fullUrlWithoutQuery('state') }}">عرض المحتوى</a></div>
@elseif($state==='empty')<div class="content-wrap"><x-empty-state title="لا يوجد محتوى لعرضه" description="سيظهر المحتوى هنا عندما يُضاف إلى حسابك."><a class="btn" href="{{ request()->fullUrlWithoutQuery('state') }}">العودة</a></x-empty-state></div>
@elseif($state==='error')<div class="content-wrap"><x-empty-state title="تعذّر تحميل المحتوى" description="تحقق من الاتصال ثم حاول مرة أخرى." icon="shield"><a class="btn primary" href="{{ request()->fullUrlWithoutQuery('state') }}">إعادة المحاولة</a></x-empty-state></div>
@else{{ $slot }}@endif
