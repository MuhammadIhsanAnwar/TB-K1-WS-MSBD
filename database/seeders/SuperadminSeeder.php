<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SuperadminSeeder extends Seeder
{
    private const DEFAULT_PASSWORD = '@Adminmedanvisit1';

    public function run(): void
    {
        $password = env('SEED_SUPERADMIN_PASSWORD', self::DEFAULT_PASSWORD);
        $password = is_string($password) && $password !== '' ? $password : self::DEFAULT_PASSWORD;

        $user = User::query()->firstOrNew([
            'email' => 'administrator@medanvisit.neoverse.my.id',
        ]);
        $user->name = 'Administrator MedanVisit';
        $user->peran = 'superadmin';
        $user->email_verified_at = now();
        $user->password = Hash::make($password);
        $user->save();
    }
}
