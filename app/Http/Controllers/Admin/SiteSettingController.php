<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSiteSettingRequest;
use App\Models\SiteSetting;
use App\Services\BrandImageService;
use App\Services\SiteBrandService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class SiteSettingController extends Controller
{
    public function edit(SiteBrandService $brand): View
    {
        return view('admin.settings', ['settings' => $brand->current()]);
    }

    public function update(UpdateSiteSettingRequest $request, BrandImageService $images, SiteBrandService $brand): RedirectResponse
    {
        $data = $request->safe()->except(['logo', 'remove_logo']);
        if ($request->hasFile('logo')) {
            $data['logo_path'] = $images->store($request->file('logo'), 'logo');
        } elseif ($request->boolean('remove_logo')) {
            $data['logo_path'] = null;
        }
        SiteSetting::updateOrCreate(['id' => 1], $data);
        $brand->forget();

        return back()->with('status', 'تم حفظ إعدادات الموقع. تظهر الهوية الجديدة في صفحات الدخول وحسابات الطلاب والأستاذ.');
    }

    public function manifest(SiteBrandService $brand): JsonResponse
    {
        $data = $brand->current();

        return response()->json(['id' => '/', 'name' => $data['name'], 'short_name' => $data['name'], 'description' => $data['description'], 'start_url' => route('student.login', [], false), 'scope' => '/', 'display' => 'standalone', 'background_color' => '#fff', 'theme_color' => '#e96d28', 'lang' => 'ar', 'dir' => 'rtl', 'icons' => [['src' => asset('images/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png'], ['src' => asset('images/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png']]])->header('Content-Type', 'application/manifest+json')->header('Cache-Control', 'no-cache');
    }
}
