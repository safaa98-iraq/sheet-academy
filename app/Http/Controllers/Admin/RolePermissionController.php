<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RolePermissionController extends Controller
{
    public function index(): View
    {
        return view('admin.roles.index', [
            'roles' => Role::where('guard_name', 'web')->where('name', '!=', 'super-admin')->with('permissions')->orderBy('label')->get(),
            'permissions' => Permission::orderBy('section')->orderBy('label')->get()->groupBy('section'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'alpha_dash', 'max:80', 'unique:roles,name'],
            'label' => ['required', 'string', 'max:120'],
            'permission_ids' => ['sometimes', 'array'],
            'permission_ids.*' => ['integer', 'distinct', 'exists:permissions,id'],
        ], ['required' => 'هذا الحقل مطلوب.', 'unique' => 'اسم الدور مستخدم مسبقاً.']);
        $role = Role::create(['name' => $data['name'], 'label' => $data['label'], 'guard_name' => 'web']);
        $role->permissions()->sync($data['permission_ids'] ?? []);

        return back()->with('status', 'تم إنشاء الدور.');
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        abort_if($role->is_system, 403);
        $data = $request->validate([
            'permission_ids' => ['sometimes', 'array'],
            'permission_ids.*' => ['integer', 'distinct', 'exists:permissions,id'],
        ], ['exists' => 'إحدى الصلاحيات المحددة غير موجودة.']);
        $role->permissions()->sync($data['permission_ids'] ?? []);

        return back()->with('status', 'تم تحديث مصفوفة الصلاحيات.');
    }

    public function destroy(Role $role): RedirectResponse
    {
        abort_if($role->is_system, 403);
        $role->delete();

        return back()->with('status', 'تم حذف الدور.');
    }
}
