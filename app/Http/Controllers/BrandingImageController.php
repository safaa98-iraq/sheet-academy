<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class BrandingImageController extends Controller
{
    public function show(string $image): Response
    {
        abort_unless(preg_match('/\A[a-f0-9-]{36}\.(?:jpg|png)\z/D', $image) === 1, 404);
        $path = 'brand-assets/'.$image;
        abort_unless(Storage::disk('private')->exists($path), 404);

        return response(Storage::disk('private')->get($path), 200, ['Content-Type' => str_ends_with($image, '.png') ? 'image/png' : 'image/jpeg', 'Cache-Control' => 'public, max-age=86400', 'X-Content-Type-Options' => 'nosniff']);
    }
}
