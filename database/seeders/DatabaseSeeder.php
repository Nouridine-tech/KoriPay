<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::create([
            'prenom'    => 'Nouridine',
            'nom'       => 'Idrissa',
            'telephone' => '770000000',
            'email'     => 'admin@koripay.com',
            'code_pin'  => Hash::make('1234'),
            'solde'     => 0.00,
            'role'      => 'admin',
            'statut'    => 'actif',
        ]);
    }
}
