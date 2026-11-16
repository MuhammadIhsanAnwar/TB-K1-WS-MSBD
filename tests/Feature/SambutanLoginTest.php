<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SambutanLoginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.supabase.katalog.url' => 'https://katalog.test',
            'services.supabase.katalog.publishable_key' => 'pub-key',
            'services.supabase.katalog.secret_key' => 'secret-key',
        ]);
    }

    public function test_successful_login_flashes_the_name_for_the_welcome_overlay(): void
    {
        Http::fake([
            'https://katalog.test/rest/v1/users*' => Http::response([[
                'id' => 15,
                'name' => 'Nadia Medan',
                'email' => 'nadia@example.test',
                'password' => Hash::make('kata-sandi'),
                'peran' => 'wisatawan',
                'email_verified_at' => '2026-01-01T00:00:00Z',
            ]]),
        ]);

        $this->post('/masuk', [
            'email' => 'nadia@example.test',
            'password' => 'kata-sandi',
        ])->assertRedirect('/akun')->assertSessionHas('sambutan', 'Nadia Medan')->assertSessionMissing('pesan');
    }
}
