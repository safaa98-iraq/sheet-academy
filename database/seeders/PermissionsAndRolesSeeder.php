<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class PermissionsAndRolesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $permissions = [
            ['dashboard.view', 'لوحة الأستاذ', 'عرض لوحة الأستاذ'],
            ['students.view', 'الطلاب', 'عرض الطلاب والتقدم'],
            ['students.manage', 'الطلاب', 'إنشاء وتعديل وتجميد الطلاب'],
            ['courses.view', 'المواد والدروس', 'عرض المواد'],
            ['courses.manage', 'المواد والدروس', 'إدارة المواد والدروس'],
            ['admins.manage', 'الأدمنز والصلاحيات', 'إدارة الأدمنز والصلاحيات'],
            ['audit.view', 'سجل النشاط', 'عرض سجل النشاط'],
            ['settings.manage', 'الإعدادات', 'إدارة إعدادات المنصة'],
        ];

        foreach ($permissions as [$name, $section, $label]) {
            Permission::updateOrCreate(['name' => $name], ['section' => $section, 'label' => $label]);
        }

        $all = Permission::all();
        $definitions = [
            'super-admin' => ['مدير المنصة', true, $all],
            'student-manager' => ['مدير الطلاب', false, $all->whereIn('name', ['dashboard.view', 'students.view', 'students.manage'])],
            'content-editor' => ['محرر المحتوى', false, $all->whereIn('name', ['dashboard.view', 'courses.view', 'courses.manage'])],
            'learning-analyst' => ['محلل التقدم', false, $all->whereIn('name', ['dashboard.view', 'students.view', 'audit.view'])],
        ];

        foreach ($definitions as $name => [$label, $isSystem, $rolePermissions]) {
            $role = Role::updateOrCreate(['name' => $name, 'guard_name' => 'web'], ['label' => $label, 'is_system' => $isSystem]);
            $role->permissions()->sync($rolePermissions->modelKeys());
        }
    }
}
