<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'prenom' => 'Admin',
            'nom' => 'KoriPay',
            'telephone' => '770000000',
            'email' => 'admin@koripay.com',
            'role' => 'admin',
        ]);
    }
}
