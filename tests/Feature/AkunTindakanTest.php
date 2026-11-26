<?php

namespace Tests\Feature;

use App\Mail\TindakanAkunEmail;
use Illuminate\Http\Client\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AkunTindakanTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Sec-Fetch-Site', 'same-origin');
        $this->withoutMiddleware(ThrottleRequests::class);

        config([
            'services.supabase.katalog.url' => 'https://katalog.test',
            'services.supabase.katalog.publishable_key' => 'publishable-test-key',
            'services.supabase.katalog.secret_key' => 'secret-test-key',
        ]);
    }

    public function test_deactivation_request_sends_a_signed_link_and_persists_only_its_token_hash(): void
    {
        $user = $this->user();
        $rateLimitKey = hash('sha256', $user['id'].'|127.0.0.1');
        RateLimiter::clear('tindakan-akun.jeda.'.$rateLimitKey);
        RateLimiter::clear('tindakan-akun.batas.'.$rateLimitKey);
        Mail::fake();
        Http::fake(fn () => Http::response([$user]));

        $this->from('/akun')->withSession(['pengguna' => $this->sessionUser($user)])
            ->post('/akun/tindakan/kirim-tautan', ['tindakan' => 'nonaktifkan'])
            ->assertRedirect(route('akun'))
            ->assertSessionHas('pesan', 'Tautan tindakan akun telah dikirim ke emailmu. Tautan berlaku selama 30 menit.');

        $url = null;
        Mail::assertSent(TindakanAkunEmail::class, function (TindakanAkunEmail $mail) use (&$url): bool {
            $url = $mail->url;

            return $mail->tindakan === 'nonaktifkan'
                && str_contains($mail->url, 'signature=')
                && str_contains($mail->url, 'expires=');
        });
        $token = basename((string) parse_url($url, PHP_URL_PATH));
        Http::assertSent(function (Request $request) use ($user, $token): bool {
            $payload = json_decode($request->body(), true);

            return $request->method() === 'POST'
                && str_contains($request->url(), '/account_action_otps?')
                && is_array($payload)
                && ($payload[0]['user_id'] ?? null) === $user['id']
                && ($payload[0]['code_hash'] ?? null) === hash('sha256', $token)
                && ($payload[0]['code_hash'] ?? null) !== $token;
        });
    }

    public function test_correct_password_and_confirmation_deactivate_account(): void
    {
        $user = $this->user();
        $token = 'token-for-deactivation-test';
        $url = $this->signedActionUrl($user, $token);
        $user['password'] = Hash::make('Password!9');
        $this->fakeActionLinkRequests($user, 'nonaktifkan', $token);

        $this->get($url)->assertOk()->assertSee('Masukkan password akunmu');

        $this->post($url, ['pilihan' => 'password', 'password' => 'Password!9'])
            ->assertRedirect($url);
        $this->get($url)->assertOk()->assertSee('Apakah anda yakin ingin menonaktifkan akun?');
        $this->post($url, ['pilihan' => 'ya'])
            ->assertRedirect(route('masuk'))
            ->assertSessionHas('pesan', 'Akun dinonaktifkan. Masuk kembali dalam 3 bulan untuk mengaktifkannya lagi.');

        Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
            && str_contains($request->url(), '/users?')
            && ! empty($request['dinonaktifkan_pada'])
            && ! empty($request['hapus_otomatis_pada']));
    }

    public function test_google_account_can_reverify_with_google_before_deactivation(): void
    {
        config([
            'services.google.client_id' => 'google-client',
            'services.google.client_secret' => 'google-secret',
        ]);
        $user = $this->user(['google_id' => 'google-subject']);
        $token = 'token-for-google-deactivation-test';
        $url = $this->signedActionUrl($user, $token);
        $otp = [
            'user_id' => $user['id'],
            'tindakan' => 'nonaktifkan',
            'code_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes(20)->toDateTimeString(),
            'attempts' => 0,
        ];
        Http::fake(function (Request $request) use ($user, $otp) {
            if (str_contains($request->url(), 'oauth2.googleapis.com/token')) {
                return Http::response(['access_token' => 'google-access-token']);
            }
            if (str_contains($request->url(), 'openidconnect.googleapis.com')) {
                return Http::response([
                    'email_verified' => true,
                    'email' => $user['email'],
                    'sub' => $user['google_id'],
                ]);
            }
            if (str_contains($request->url(), '/account_action_otps')) {
                return Http::response([$otp]);
            }
            if ($request->method() === 'PATCH') {
                return Http::response([array_merge($user, $request->data())]);
            }

            return Http::response([$user]);
        });

        $this->get($url)->assertOk()->assertSee('Akun ini menggunakan Google');
        $this->post($url, ['pilihan' => 'google'])
            ->assertRedirect(route('masuk.google'));
        $oauthResponse = $this->get(route('masuk.google'));
        $oauthResponse->assertRedirect();
        parse_str((string) parse_url($oauthResponse->headers->get('Location'), PHP_URL_QUERY), $oauthQuery);

        $this->get(route('masuk.google.callback', [
            'code' => 'authorization-code',
            'state' => $oauthQuery['state'],
        ]))->assertRedirect($url);

        $this->get($url)->assertOk()->assertSee('Akun Google berhasil diverifikasi.');
        $this->post($url, ['pilihan' => 'ya'])
            ->assertRedirect(route('masuk'))
            ->assertSessionHas('pesan', 'Akun dinonaktifkan. Masuk kembali dalam 3 bulan untuk mengaktifkannya lagi.');

        Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
            && str_contains($request->url(), '/users?')
            && ! empty($request['dinonaktifkan_pada']));
    }

    public function test_correct_password_and_confirmation_permanently_delete_account(): void
    {
        $user = $this->user();
        $token = 'token-for-delete-test';
        $url = $this->signedActionUrl($user, $token);
        $user['password'] = Hash::make('Password!9');
        $this->fakeActionLinkRequests($user, 'hapus', $token);

        $this->get($url)->assertOk()->assertSee('Masukkan password akunmu');
        $this->post($url, ['pilihan' => 'password', 'password' => 'Password!9'])
            ->assertRedirect($url);
        $this->get($url)->assertOk()->assertSee('Apakah anda yakin ingin menghapus akun secara permanen?');
        $this->post($url, ['pilihan' => 'ya'])
            ->assertRedirect(route('masuk'))
            ->assertSessionHas('pesan', 'Akun dan data profil berhasil dihapus permanen.');

        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
            && str_contains($request->url(), '/users?'));
        Http::assertNotSent(fn (Request $request) => $request->method() === 'PATCH'
            && str_contains($request->url(), '/users?'));
    }

    public function test_choosing_no_cancels_the_action_without_changing_the_account(): void
    {
        $user = $this->user(['password' => Hash::make('Password!9')]);
        $token = 'token-for-cancel-test';
        $url = $this->signedActionUrl($user, $token);
        $this->fakeActionLinkRequests($user, 'hapus', $token);

        $this->post($url, ['pilihan' => 'password', 'password' => 'Password!9']);
        $this->post($url, ['pilihan' => 'tidak'])
            ->assertRedirect(route('masuk'))
            ->assertSessionHas('pesan', 'Tindakan akun dibatalkan. Akunmu tidak berubah.');

        Http::assertNotSent(fn (Request $request) => in_array($request->method(), ['PATCH', 'DELETE'], true)
            && str_contains($request->url(), '/users?'));
    }

    public function test_wrong_password_does_not_authorize_account_action(): void
    {
        $user = $this->user(['password' => Hash::make('Password!9')]);
        $token = 'token-for-wrong-password-test';
        $url = $this->signedActionUrl($user, $token);
        $this->fakeActionLinkRequests($user, 'nonaktifkan', $token);

        $this->post($url, ['pilihan' => 'password', 'password' => 'wrong-password'])
            ->assertSessionHasErrors('password');
        $this->post($url, ['pilihan' => 'ya'])->assertForbidden();

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

    public function test_session_for_deleted_account_is_cleared_on_any_next_web_request(): void
    {
        Http::fake(fn () => Http::response([]));

        $this->withSession(['pengguna' => $this->sessionUser($this->user())])
            ->get('/lupa-password')
            ->assertRedirect(route('masuk'))
            ->assertSessionMissing('pengguna')
            ->assertSessionHasErrors('email');
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

    private function signedActionUrl(array $user, string $token): string
    {
        return URL::temporarySignedRoute('akun.tindakan.link', now()->addMinutes(30), [
            'userId' => $user['id'],
            'token' => $token,
        ]);
    }

    private function fakeActionLinkRequests(array $user, string $action, string $token): void
    {
        $otp = [
            'user_id' => $user['id'],
            'tindakan' => $action,
            'code_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes(20)->toDateTimeString(),
            'attempts' => 0,
        ];

        Http::fake(function (Request $request) use ($user, $otp) {
            if (str_contains($request->url(), '/account_action_otps')) {
                return Http::response([$otp]);
            }
            if (str_contains($request->url(), '/users?') && $request->method() === 'PATCH') {
                return Http::response([array_merge($user, $request->data())]);
            }

            return Http::response([$user]);
        });
    }
}
