<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Models\LessonVideo;
use App\Models\Student;
use App\Video\VideoStreamService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class VideoStreamController extends Controller
{
    public function status(Request $request, Lesson $lesson): JsonResponse
    {
        abort_unless(Gate::forUser($request->user('student'))->allows('viewByStudent', $lesson), 404);

        return response()->json(['status' => $lesson->video?->status ?? $lesson->video_status])
            ->header('Cache-Control', 'private, no-store');
    }

    public function session(Request $request, LessonVideo $video): JsonResponse
    {
        $student = $request->user('student');
        $this->authorizeStream($student, $video);
        $viewLink = (string) $request->session()->get('active_view_link');
        abort_unless($viewLink !== '', 404);
        $expiresAt = now()->addMinutes(8);
        $url = URL::temporarySignedRoute('student.video.asset', $expiresAt, [
            'video' => $video->id, 'student' => $student->id, 'asset' => 'hls/master.m3u8', 'view' => $viewLink,
        ]);

        $renditions = collect($video->enabled_resolutions ?? [])->mapWithKeys(fn (int $height): array => [
            $height => URL::temporarySignedRoute('student.video.asset', $expiresAt, [
                'video' => $video->id, 'student' => $student->id, 'asset' => 'hls/v'.$height.'/index.m3u8', 'view' => $viewLink,
            ]),
        ])->all();

        return response()->json(['manifest_url' => $url, 'rendition_urls' => $renditions, 'expires_at' => $expiresAt->toIso8601String()])
            ->header('Cache-Control', 'private, no-store');
    }

    public function asset(Request $request, LessonVideo $video, Student $student, string $asset, VideoStreamService $streams): Response
    {
        abort_unless($student->is($request->user('student')), 404);
        $this->authorizeStream($student, $video);
        abort_unless(preg_match('/\A(?:hls\/(?:master\.m3u8|v[1-9]\d{0,3}\/(?:index\.m3u8|segment_\d+\.ts)))\z/D', $asset) === 1, 404);
        if (preg_match('/\Av(\d+)\//', substr($asset, 4), $resolution)) {
            abort_unless(in_array((int) $resolution[1], array_map('intval', $video->enabled_resolutions ?? []), true), 404);
        }
        $relative = substr($asset, 4);
        $path = $video->output_path.'/'.$relative;
        abort_unless(Storage::disk('private')->exists($path), 404);

        if (str_ends_with($path, '.m3u8')) {
            return response($streams->rewritePlaylist($video, $student, $path, Storage::disk('private')->get($path), (string) $request->query('view')), 200, [
                'Content-Type' => 'application/vnd.apple.mpegurl', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        return response()->file(Storage::disk('private')->path($path), [
            'Content-Type' => 'video/mp2t', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function key(Request $request, LessonVideo $video, Student $student): Response
    {
        abort_unless($student->is($request->user('student')), 404);
        $this->authorizeStream($student, $video);
        abort_unless($video->key_path && Storage::disk('private')->exists($video->key_path), 404);

        return response(Storage::disk('private')->get($video->key_path), 200, [
            'Content-Type' => 'application/octet-stream', 'Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function authorizeStream(Student $student, LessonVideo $video): void
    {
        $lesson = $video->lesson;
        abort_unless($lesson !== null && $video->status === 'ready' && Gate::forUser($student)->allows('viewByStudent', $lesson), 404);
    }
}
