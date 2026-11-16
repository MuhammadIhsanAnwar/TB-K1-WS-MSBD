<?php

namespace Tests\Feature;

use App\Mail\VerifikasiEmail;
use App\Support\Katalog;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class VerifikasiEmailKirimUlangTest extends TestCase
{
    private const EMAIL = 'wisatawan@example.test';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.supabase.katalog.url' => 'https://katalog.test',
            'services.supabase.katalog.publishable_key' => 'pub-key',
            'services.supabase.katalog.secret_key' => 'secret-key',
            'services.sparql.endpoint' => 'https://sparql.test/query',
        ]);
        Katalog::forgetCache();
        Mail::fake();
        Http::fake(function (HttpRequest $request) {
            if (str_contains($request->url(), '/rest/v1/users')) {
                return Http::response([[
                    'id' => 15,
                    'email' => self::EMAIL,
                    'email_verified_at' => null,
                ]]);
            }

            if (str_contains($request->url(), '/rest/v1/email_verification_tokens')) {
                return Http::response([['id' => 1]]);
            }

            return Http::response([
                'head' => ['vars' => ['s', 'p', 'o']],
                'results' => ['bindings' => []],
            ]);
        });
    }

    public function test_resend_message_is_below_the_button_and_cooldown_expires_after_60_seconds(): void
    {
        $this->post(route('verifikasi.kirim'), ['email' => self::EMAIL])
            ->assertRedirect(route('verifikasi', ['email' => self::EMAIL]))
            ->assertSessionHas('pesan_sukses', 'Email berhasil dikirim ulang.');

        Mail::assertSent(VerifikasiEmail::class, 1);
        $response = $this->get(route('verifikasi', ['email' => self::EMAIL]))->assertOk();
        $html = $response->getContent();
        $tombol = strpos($html, 'Kirim ulang email verifikasi</button>');
        $pesan = strpos($html, 'class="mv-otentik__pesan mv-otentik__pesan--sukses" role="status"');

        $this->assertNotFalse($tombol);
        $this->assertNotFalse($pesan);
        $this->assertGreaterThan($tombol, $pesan);
        $this->assertStringContainsString('Email berhasil dikirim ulang.', $html);
        $this->assertStringContainsString('data-verifikasi-kirim disabled', $html);
        $this->assertMatchesRegularExpression('/data-verifikasi-hitung[^>]*data-sisa="[1-6]?[0-9]"/', $html);

        $this->post(route('verifikasi.kirim'), ['email' => self::EMAIL])
            ->assertRedirect(route('verifikasi', ['email' => self::EMAIL]))
            ->assertSessionHas('pesan', fn (string $pesan) => preg_match('/^Tunggu \d+ detik sebelum meminta link baru lagi\.$/', $pesan) === 1);
        Mail::assertSent(VerifikasiEmail::class, 1);

        $this->travel(60)->seconds();
        $html = $this->get(route('verifikasi', ['email' => self::EMAIL]))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-verifikasi-kirim disabled', $html);
    }

    public function test_fourth_resend_attempt_is_blocked_for_one_hour(): void
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            if ($attempt > 1) {
                $this->travel(60)->seconds();
            }

            $this->post(route('verifikasi.kirim'), ['email' => self::EMAIL])
                ->assertRedirect(route('verifikasi', ['email' => self::EMAIL]));
        }

        Mail::assertSent(VerifikasiEmail::class, 3);

        $this->travel(60)->seconds();
        $this->post(route('verifikasi.kirim'), ['email' => self::EMAIL])
            ->assertRedirect(route('verifikasi', ['email' => self::EMAIL]))
            ->assertSessionHas('pesan', 'Batas 3 percobaan tercapai. Tombol akan aktif kembali dalam 1 jam.');

        Mail::assertSent(VerifikasiEmail::class, 3);
        $html = $this->get(route('verifikasi', ['email' => self::EMAIL]))->assertOk()->getContent();
        $this->assertStringContainsString('data-verifikasi-kirim disabled', $html);
        $this->assertStringContainsString('1 jam', $html);

        $this->travel(3600)->seconds();
        $html = $this->get(route('verifikasi', ['email' => self::EMAIL]))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-verifikasi-kirim disabled', $html);
    }
}
