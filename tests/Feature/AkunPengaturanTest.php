<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AkunPengaturanTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.supabase.katalog.url' => 'https://katalog.test',
            'services.supabase.katalog.publishable_key' => 'publishable-test-key',
            'services.supabase.katalog.secret_key' => 'secret-test-key',
        ]);
    }

    public function test_google_account_has_circular_avatar_and_disabled_password_fields(): void
    {
        $photoPath = 'profil_picture/settings-test-'.Str::random(10).'.jpg';
        $photoFile = public_path('storage/'.$photoPath);
        File::ensureDirectoryExists(dirname($photoFile));
        file_put_contents($photoFile, 'test image');
        $user = $this->user(['google_id' => 'google-user-id', 'foto_profil' => $photoPath]);
        Http::fake(fn () => Http::response([$user]));

        try {
            $this->withSession(['pengguna' => [
                'id' => 42,
                'nama' => $user['name'],
                'email' => $user['email'],
                'peran' => 'wisatawan',
            ]])->get('/akun/pengaturan')
                ->assertOk()
                ->assertSee('width:96px;height:96px;aspect-ratio:1;object-fit:cover;border-radius:50%', false)
                ->assertSee('<fieldset disabled', false)
                ->assertSee('Password akun ini dikelola melalui Google', false);
        } finally {
            File::delete($photoFile);
        }
    }

    public function test_google_account_cannot_change_password_and_success_is_shown_in_centered_dialog(): void
    {
        $user = $this->user(['google_id' => 'google-user-id']);
        Http::fake(function (Request $request) use ($user) {
            if ($request->method() === 'PATCH') {
                return Http::response([$user]);
            }

            return Http::response([$user]);
        });

        $this->withSession(['pengguna' => [
            'id' => 42,
            'nama' => $user['name'],
            'email' => $user['email'],
            'peran' => 'wisatawan',
        ]])->post('/akun/pengaturan', [
            'nama' => 'Nama Baru',
            'tanggal_lahir' => '1990-01-01',
            'no_hp' => '081234567890',
            'password' => 'NewPassword!9',
            'password_confirmation' => 'NewPassword!9',
        ])->assertRedirect(route('akun.pengaturan'))
            ->assertSessionHas('pesan', 'Pengaturan akun berhasil disimpan.');

        Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
            && str_contains($request->url(), '/users?')
            && ! isset($request['password']));

        $this->get('/akun/pengaturan')
            ->assertOk()
            ->assertSee('class="mv-dialog-pengaturan"', false)
            ->assertSee('role="alertdialog"', false)
            ->assertSee('Perubahan berhasil')
            ->assertDontSee('class="pesan"', false);
    }

    public function test_supabase_save_failure_is_shown_in_centered_dialog(): void
    {
        $user = $this->user();
        Http::fake(function (Request $request) use ($user) {
            if ($request->method() === 'PATCH') {
                return Http::response(['message' => 'Temporary failure'], 503);
            }

            return Http::response([$user]);
        });

        $this->withSession(['pengguna' => [
            'id' => 42,
            'nama' => $user['name'],
            'email' => $user['email'],
            'peran' => 'wisatawan',
        ]])->post('/akun/pengaturan', [
            'nama' => 'Nama Baru',
            'tanggal_lahir' => '1990-01-01',
            'no_hp' => '081234567890',
        ])->assertRedirect(route('akun.pengaturan'))
            ->assertSessionHas('galat', 'Perubahan akun gagal disimpan. Coba lagi beberapa saat lagi.');

        $this->get('/akun/pengaturan')
            ->assertOk()
            ->assertSee('Perubahan belum berhasil')
            ->assertSee('class="mv-dialog-pengaturan"', false)
            ->assertDontSee('class="pesan"', false);
    }

    private function user(array $attributes = []): array
    {
        return array_merge([
            'id' => 42,
            'name' => 'Pengguna Test',
            'email' => 'pengguna@example.test',
            'peran' => 'wisatawan',
            'tanggal_lahir' => '1990-01-01',
            'no_hp' => '081234567890',
            'email_verified_at' => '2026-01-01T00:00:00+00:00',
        ], $attributes);
    }
}
