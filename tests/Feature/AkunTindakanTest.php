<?php

namespace Tests\Feature;

use App\Mail\TindakanAkunEmail;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AkunTindakanTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Sec-Fetch-Site', 'same-origin');

        config([
            'services.supabase.katalog.url' => 'https://katalog.test',
            'services.supabase.katalog.publishable_key' => 'publishable-test-key',
            'services.supabase.katalog.secret_key' => 'secret-test-key',
        ]);
    }

    public function test_deactivation_request_sends_a_six_digit_code_and_persists_only_its_hash(): void
    {
        $user = $this->user();
        $rateLimitKey = hash('sha256', $user['id'].'|127.0.0.1');
        RateLimiter::clear('tindakan-akun.jeda.'.$rateLimitKey);
        RateLimiter::clear('tindakan-akun.batas.'.$rateLimitKey);
        Mail::fake();
        Http::fake(fn () => Http::response([$user]));

        $this->from('/akun')->withSession(['pengguna' => $this->sessionUser($user)])
            ->post('/akun/tindakan/kirim-kode', ['tindakan' => 'nonaktifkan'])
            ->assertRedirect(route('akun'))
            ->assertSessionHas('tindakan_akun_pending', [
                'user_id' => $user['id'],
                'tindakan' => 'nonaktifkan',
            ]);

        $kode = null;
        Mail::assertSent(TindakanAkunEmail::class, function (TindakanAkunEmail $mail) use (&$kode): bool {
            $kode = $mail->kode;

            return $mail->tindakan === 'nonaktifkan' && preg_match('/^[0-9]{6}$/', $mail->kode) === 1;
        });
        Http::assertSent(function (Request $request) use ($user, $kode): bool {
            $payload = json_decode($request->body(), true);

            return $request->method() === 'POST'
                && str_contains($request->url(), '/account_action_otps?')
                && is_array($payload)
                && ($payload[0]['user_id'] ?? null) === $user['id']
                && ($payload[0]['code_hash'] ?? null) === hash('sha256', $kode)
                && ($payload[0]['code_hash'] ?? null) !== $kode;
        });
    }

    public function test_correct_otp_deactivates_account_and_logs_out(): void
    {
        $user = $this->user();
        $kode = '004219';
        $otp = [
            'user_id' => $user['id'],
            'tindakan' => 'nonaktifkan',
            'code_hash' => hash('sha256', $kode),
            'expires_at' => now()->addMinutes(5)->toDateTimeString(),
            'attempts' => 0,
        ];
        Http::fake(function (Request $request) use ($user, $otp) {
            if (str_contains($request->url(), '/account_action_otps')) {
                return $request->method() === 'GET'
                    ? Http::response([$otp])
                    : Http::response([], 204);
            }
            if ($request->method() === 'PATCH') {
                return Http::response([array_merge($user, $request->data())]);
            }

            return Http::response([$user]);
        });

        $this->withSession([
            'pengguna' => $this->sessionUser($user),
            'tindakan_akun_pending' => ['user_id' => $user['id'], 'tindakan' => 'nonaktifkan'],
        ])->post('/akun/tindakan/verifikasi', ['kode' => $kode])
            ->assertRedirect(route('masuk'))
            ->assertSessionMissing('pengguna')
            ->assertSessionHas('pesan', 'Akun dinonaktifkan. Masuk kembali dalam 3 bulan untuk mengaktifkannya lagi.');

        Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
            && str_contains($request->url(), '/users?')
            && ! empty($request['dinonaktifkan_pada'])
            && ! empty($request['hapus_otomatis_pada']));
    }

    public function test_correct_otp_permanently_deletes_the_account_and_logs_out(): void
    {
        $user = $this->user();
        $kode = '782041';
        $otp = [
            'user_id' => $user['id'],
            'tindakan' => 'hapus',
            'code_hash' => hash('sha256', $kode),
            'expires_at' => now()->addMinutes(5)->toDateTimeString(),
            'attempts' => 0,
        ];
        Http::fake(function (Request $request) use ($user, $otp) {
            if (str_contains($request->url(), '/account_action_otps')) {
                return $request->method() === 'GET'
                    ? Http::response([$otp])
                    : Http::response([], 204);
            }
            if ($request->method() === 'DELETE') {
                return Http::response([$user]);
            }

            return Http::response([$user]);
        });

        $this->withSession([
            'pengguna' => $this->sessionUser($user),
            'tindakan_akun_pending' => ['user_id' => $user['id'], 'tindakan' => 'hapus'],
        ])->post('/akun/tindakan/verifikasi', ['kode' => $kode])
            ->assertRedirect(route('masuk'))
            ->assertSessionMissing('pengguna')
            ->assertSessionHas('pesan', 'Akun dan data profil berhasil dihapus permanen.');

        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
            && str_contains($request->url(), '/users?'));
        Http::assertNotSent(fn (Request $request) => $request->method() === 'PATCH'
            && str_contains($request->url(), '/users?'));
    }

    public function test_login_restores_a_deactivated_account_before_its_deadline(): void
    {
        $user = $this->user([
            'password' => Hash::make('Password!9'),
            'dinonaktifkan_pada' => now()->subDays(5)->toDateTimeString(),
            'hapus_otomatis_pada' => now()->addMonthsNoOverflow(3)->toDateTimeString(),
        ]);
        Http::fake(function (Request $request) use ($user) {
            if ($request->method() === 'PATCH') {
                return Http::response([array_merge($user, $request->data())]);
            }

            return Http::response([$user]);
        });

        $this->post('/masuk', ['email' => $user['email'], 'password' => 'Password!9'])
            ->assertRedirect(route('beranda'))
            ->assertSessionHas('pengguna');

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return $request->method() === 'PATCH'
                && array_key_exists('dinonaktifkan_pada', $data)
                && $data['dinonaktifkan_pada'] === null
                && $data['hapus_otomatis_pada'] === null;
        });
    }

    public function test_login_permanently_deletes_an_account_after_the_recovery_deadline(): void
    {
        $user = $this->user([
            'password' => Hash::make('Password!9'),
            'dinonaktifkan_pada' => now()->subMonthsNoOverflow(4)->toDateTimeString(),
            'hapus_otomatis_pada' => now()->subSecond()->toDateTimeString(),
        ]);
        Http::fake(function (Request $request) use ($user) {
            if ($request->method() === 'DELETE') {
                return Http::response([$user]);
            }

            return Http::response([$user]);
        });

        $this->from('/masuk')->post('/masuk', ['email' => $user['email'], 'password' => 'Password!9'])
            ->assertRedirect('/masuk')
            ->assertSessionHasErrors('email')
            ->assertSessionMissing('pengguna');

        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
            && str_contains($request->url(), '/users?'));
    }

    private function user(array $attributes = []): array
    {
        return array_merge([
            'id' => 42,
            'name' => 'Pengguna Test',
            'email' => 'pengguna@example.test',
            'peran' => 'wisatawan',
            'email_verified_at' => now()->subYear()->toDateTimeString(),
            'no_hp' => '081234567890',
            'foto_profil' => null,
        ], $attributes);
    }

    private function sessionUser(array $user): array
    {
        return [
            'id' => $user['id'],
            'email' => $user['email'],
            'nama' => $user['name'],
            'peran' => $user['peran'],
            'no_hp' => $user['no_hp'],
        ];
    }
}
