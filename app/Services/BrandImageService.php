<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BrandImageService
{
    public function store(UploadedFile $file, string $field): string
    {
        $source = $file->getRealPath();
        $info = @getimagesize($source);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($source);
        if ($info === false || $info[0] * $info[1] > 25_000_000 || ! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw ValidationException::withMessages([$field => 'ارفع صورة JPG أو PNG أو WebP صالحة، حتى 25 مليون بكسل.']);
        }
        $bytes = file_get_contents($source);
        if ($mime === 'image/webp' && ! function_exists('imagecreatefromwebp')) {
            $decoded = Process::timeout(20)->run(['ffmpeg', '-nostdin', '-v', 'error', '-protocol_whitelist', 'file,pipe', '-i', $source, '-frames:v', '1', '-vf', 'scale=w=min(1600\,iw):h=min(1600\,ih):force_original_aspect_ratio=decrease', '-f', 'image2pipe', '-vcodec', 'png', 'pipe:1']);
            if (! $decoded->successful()) {
                throw ValidationException::withMessages([$field => 'تعذّر قراءة الصورة. جرّب PNG أو JPG.']);
            }
            $bytes = $decoded->output();
        }
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw ValidationException::withMessages([$field => 'ملف الصورة غير صالح.']);
        }
        $ratio = min(1, 1600 / max(imagesx($image), imagesy($image)));
        $output = imagecreatetruecolor(max(1, (int) round(imagesx($image) * $ratio)), max(1, (int) round(imagesy($image) * $ratio)));
        imagealphablending($output, false);
        imagesavealpha($output, true);
        imagecopyresampled($output, $image, 0, 0, 0, 0, imagesx($output), imagesy($output), imagesx($image), imagesy($image));
        $extension = $mime === 'image/jpeg' ? 'jpg' : 'png';
        ob_start();
        if ($extension === 'jpg') {
            imagejpeg($output, null, 85);
        } else {
            imagepng($output, null, 6);
        }
        $result = ob_get_clean();
        imagedestroy($output);
        imagedestroy($image);
        $path = 'brand-assets/'.Str::uuid().'.'.$extension;
        Storage::disk('private')->put($path, $result);

        return $path;
    }
}
