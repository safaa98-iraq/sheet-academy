<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class DefaultProfessorSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $password = (string) env('SUPERADMIN_PASSWORD', '');
        if (strlen($password) < 12) {
            throw new RuntimeException('Set SUPERADMIN_PASSWORD to a unique value of at least 12 characters before seeding.');
        }

        $admin = User::updateOrCreate(
            ['email' => env('SUPERADMIN_EMAIL', 'professor@example.test')],
            [
                'name' => env('SUPERADMIN_NAME', 'د. سليم'),
                'password' => Hash::make($password),
                'is_active' => true,
                'is_super_admin' => true,
            ],
        );
        $admin->roles()->sync([Role::where('name', 'super-admin')->value('id')]);
    }
}
