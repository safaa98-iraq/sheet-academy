<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateProfileRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        abort_unless($request->user('web')->is_super_admin, 403);

        return view('admin.profile', ['admin' => $request->user('web')]);
    }

    public function update(UpdateProfileRequest $request): RedirectResponse
    {
        $admin = $request->user('web');
        $data = $request->validated();
        $admin->fill(['name' => $data['name'], 'email' => $data['email']]);
        if ($admin->isDirty('email')) {
            $admin->email_verified_at = null;
        }
        if (filled($data['password'] ?? null)) {
            $admin->password = $data['password'];
            $admin->remember_token = Str::random(60);
        }
        $admin->save();
        $request->session()->regenerate();

        return redirect()->route('admin.profile.edit')->with('status', 'تم حفظ بياناتك. استخدم البريد وكلمة المرور المحدّثين عند الدخول القادم.');
    }
}
