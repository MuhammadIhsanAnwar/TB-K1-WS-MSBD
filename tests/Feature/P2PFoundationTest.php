<?php

namespace Tests\Feature;

use App\Services\P2P\SupabaseToken;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class P2PFoundationTest extends TestCase
{
    public function test_peer_registration_requires_a_laravel_session(): void
    {
        $this->postJson('/api/p2p/peers/register', [
            'peer_id' => '54f53d49-a38c-47dd-a627-8d0d83d8c874',
            'label' => 'Browser test',
        ])->assertUnauthorized();
    }

    public function test_registration_returns_a_short_lived_server_signed_supabase_token(): void
    {
        $opensslConfigPath = tempnam(sys_get_temp_dir(), 'p2p-openssl-config-');
        self::assertNotFalse($opensslConfigPath);
        file_put_contents($opensslConfigPath, "openssl_conf = openssl_init\n[openssl_init]\nproviders = provider_sect\n[provider_sect]\ndefault = default_sect\n[default_sect]\nactivate = 1\n");
        $privateKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
            'config' => $opensslConfigPath,
        ]);
        self::assertNotFalse($privateKey);
        self::assertTrue(openssl_pkey_export($privateKey, $privatePem, null, ['config' => $opensslConfigPath]));
        $privateKeyPath = tempnam(sys_get_temp_dir(), 'p2p-signing-key-');
        self::assertNotFalse($privateKeyPath);
        file_put_contents($privateKeyPath, $privatePem);
        config([
            'services.supabase.katalog.url' => 'https://db1.example.test',
            'services.supabase.katalog.publishable_key' => 'publishable-test-key',
            'services.supabase.katalog.secret_key' => 'server-only-test-key',
            'services.supabase.katalog.jwt_private_key_path' => $privateKeyPath,
            'services.supabase.katalog.jwt_key_id' => 'test-key-id',
        ]);
        Http::fakeSequence()
            ->push([])
            ->push([[
                'id' => '54f53d49-a38c-47dd-a627-8d0d83d8c874',
                'user_id' => 42,
                'revoked_at' => null,
            ]]);

        try {
            $response = $this->withSession([
                'pengguna' => ['id' => 42, 'nama' => 'Pengguna Test', 'peran' => 'wisatawan'],
            ])->postJson('/api/p2p/peers/register', [
                'peer_id' => '54f53d49-a38c-47dd-a627-8d0d83d8c874',
                'label' => 'Browser test',
            ])->assertOk()
                ->assertJsonPath('peer_id', '54f53d49-a38c-47dd-a627-8d0d83d8c874')
                ->assertJsonPath('user_id', 42)
                ->assertJsonPath('publishable_key', 'publishable-test-key')
                ->assertDontSee('server-only-test-key');

            $token = $response->json('access_token');
            [$headerPart, $payloadPart, $signaturePart] = explode('.', $token);
            $decode = fn (string $part) => json_decode(
                base64_decode(strtr($part, '-_', '+/')),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            $header = $decode($headerPart);
            $claims = $decode($payloadPart);
            $signature = base64_decode(strtr($signaturePart, '-_', '+/'));
            $publicKey = openssl_pkey_get_details($privateKey)['key'];

            self::assertSame('RS256', $header['alg']);
            self::assertSame('test-key-id', $header['kid']);
            self::assertSame('authenticated', $claims['role']);
            self::assertSame('54f53d49-a38c-47dd-a627-8d0d83d8c874', $claims['sub']);
            self::assertSame('42', $claims['app_metadata']['user_id']);
            self::assertSame('54f53d49-a38c-47dd-a627-8d0d83d8c874', $claims['app_metadata']['peer_id']);
            self::assertGreaterThan(time(), $claims['exp']);
            self::assertLessThanOrEqual(300, $claims['exp'] - $claims['iat']);
            self::assertSame(1, openssl_verify(
                $headerPart.'.'.$payloadPart,
                $signature,
                $publicKey,
                OPENSSL_ALGO_SHA256,
            ));
        } finally {
            unlink($privateKeyPath);
            unlink($opensslConfigPath);
        }
    }

    public function test_p2p_page_requires_a_signed_in_user(): void
    {
        $this->get('/MedanVisitt-Hub')
            ->assertRedirect(route('masuk', ['kembali' => '/MedanVisitt-Hub']));
    }

    public function test_p2p_page_is_available_to_a_signed_in_traveler(): void
    {
        $this->withSession([
            'pengguna' => ['id' => 42, 'nama' => 'Pengguna Test', 'peran' => 'wisatawan'],
        ])->get('/MedanVisitt-Hub')
            ->assertOk()
            ->assertSee('MedanVisitt Hub')
            ->assertSee('css/medanvisitt-hub.css', false)
            ->assertSee('aria-label="Navigasi utama"', false)
            ->assertSee(route('medanvisitt-hub.index'), false)
            ->assertSee('Wisatawan Online')
            ->assertSee('Komunikasi peer-to-peer')
            ->assertSee('WebRTC DataChannel')
            ->assertSee('data-p2p-root', false);

        self::assertFileExists(public_path('css/medanvisitt-hub.css'));
    }

    public function test_legacy_p2p_url_redirects_to_medanvisitt_hub(): void
    {
        $this->get('/p2p')->assertRedirect('/MedanVisitt-Hub');
    }

    public function test_supabase_token_signing_fails_when_server_key_is_missing(): void
    {
        config([
            'services.supabase.katalog.jwt_private_key_path' => null,
            'services.supabase.katalog.jwt_key_id' => null,
        ]);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Supabase P2P JWT signing key belum dikonfigurasi.');

        app(SupabaseToken::class)->issue(42, '54f53d49-a38c-47dd-a627-8d0d83d8c874', 'Pengguna Test');
    }
}
