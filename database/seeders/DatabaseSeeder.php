<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     * Bootstrap del admin inicial a partir de variables de entorno (D-10 y Artículo IX).
     */
    public function run(): void
    {
        $adminUser = env('ADMIN_USERNAME', 'admin');
        $adminPass = env('ADMIN_PASSWORD', 'admin_secret_pass');

        $exists = DB::table('user')->where('username', strtolower(trim($adminUser)))->exists();
        if (!$exists) {
            DB::table('user')->insert([
                'id' => (string) Str::uuid(),
                'username' => strtolower(trim($adminUser)),
                'password_hash' => password_hash($adminPass, PASSWORD_BCRYPT),
                'role' => 'admin',
            ]);
        }
    }
}
