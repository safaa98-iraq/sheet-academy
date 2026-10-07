<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAdminRequest;
use App\Http\Requests\Admin\UpdateAdminRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class AdminUserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): View
    {
        return view('admin.admins.index', ['admins' => User::with('roles')->orderBy('name')->paginate(20), 'roles' => Role::where('guard_name', 'web')->where('name', '!=', 'super-admin')->orderBy('label')->get()]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): View
    {
        return view('admin.admins.create', ['roles' => Role::where('guard_name', 'web')->where('name', '!=', 'super-admin')->with('permissions')->orderBy('label')->get()]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreAdminRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => Hash::make($data['password']), 'is_active' => true]);
        $user->roles()->sync($data['role_ids']);

        return redirect()->route('admin.admins.index')->with('status', 'تم إنشاء حساب الأدمن.');
    }

    /**
     * Display the specified resource.
     */
    public function show(User $admin): View
    {
        return view('admin.admins.edit', ['admin' => $admin->load('roles'), 'roles' => Role::where('guard_name', 'web')->where('name', '!=', 'super-admin')->with('permissions')->orderBy('label')->get()]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(User $admin): View
    {
        return $this->show($admin);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateAdminRequest $request, User $admin): RedirectResponse
    {
        abort_if($admin->is_super_admin, 403);
        abort_if($admin->is($request->user('web')), 422, 'لا يمكن تعطيل حسابك من هذه الصفحة.');
        $data = $request->validated();
        $admin->fill(['name' => $data['name'], 'email' => $data['email'], 'is_active' => $data['is_active']]);
        if (filled($data['password'] ?? null)) {
            $admin->password = Hash::make($data['password']);
        }
        $admin->save();
        $admin->roles()->sync($data['role_ids']);

        return redirect()->route('admin.admins.index')->with('status', 'تم تحديث حساب الأدمن وصلاحياته.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(User $admin): RedirectResponse
    {
        abort_if($admin->is_super_admin, 403);
        abort_if($admin->is(auth()->guard('web')->user()), 422, 'لا يمكن حذف حسابك الحالي.');
        $admin->delete();

        return redirect()->route('admin.admins.index')->with('status', 'تم حذف حساب الأدمن.');
    }
}
