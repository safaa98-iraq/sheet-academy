<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\StudentPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlayerPreferenceController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'video_quality' => ['sometimes', 'string', 'regex:/\A(?:auto|[1-9]\d{0,4}p)\z/'],
            'playback_speed' => ['sometimes', 'numeric', 'between:0.5,2'],
            'is_muted' => ['sometimes', 'boolean'],
        ], [
            'video_quality.regex' => 'جودة الفيديو المحددة غير مدعومة.',
            'playback_speed.between' => 'سرعة التشغيل يجب أن تكون بين 0.5 و2.',
        ]);

        $preference = StudentPreference::updateOrCreate(['student_id' => $request->user('student')->id], $data);

        return response()->json(['video_quality' => $preference->video_quality, 'playback_speed' => $preference->playback_speed, 'is_muted' => $preference->is_muted]);
    }
}
