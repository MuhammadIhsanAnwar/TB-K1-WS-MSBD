<?php

namespace Tests\Feature;

use App\Support\Katalog;
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

    public function test_forgot_password_link_uses_the_login_entrance_animation(): void
    {
        config(['services.sparql.endpoint' => 'https://sparql.test/query']);
        Katalog::forgetCache();
        Http::fake(['*' => Http::response([
            'head' => ['vars' => ['s', 'p', 'o']],
            'results' => ['bindings' => []],
        ])]);

        $this->get('/masuk')
            ->assertOk()
            ->assertSee('Lupa Password?')
            ->assertSee('data-naik style="margin-top:12px; --i:6"', false);
    }

    public function test_verification_flash_message_is_displayed_inside_the_verification_panel(): void
    {
        $message = 'Jika akun ditemukan dan belum terverifikasi, link baru sudah dikirim.';
        config(['services.sparql.endpoint' => 'https://sparql.test/query']);
        Katalog::forgetCache();
        Http::fake(['*' => Http::response([
            'head' => ['vars' => ['s', 'p', 'o']],
            'results' => ['bindings' => []],
        ])]);

        $this->withSession(['pesan' => $message])
            ->get('/verifikasi-email?email=wisatawan%40example.test')
            ->assertOk()
            ->assertSee('Periksa juga folder spam.')
            ->assertSee($message)
            ->assertSee('class="mv-otentik__visual"', false)
            ->assertSee('peta-medan-1930-kota-lama.jpg')
            ->assertSee('class="mv-otentik__pesan" role="status"', false)
            ->assertDontSee('<div class="pesan" role="status">', false);
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
