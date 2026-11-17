<?php

namespace App\Services\P2P;

class SupabaseToken
{
    public function issue(int $userId, string $peerId, string $name): string
    {
        $config = config('services.supabase.katalog');
        $keyPath = $config['jwt_private_key_path'] ?? null;
        $keyId = $config['jwt_key_id'] ?? null;

        if (! is_string($keyPath) || $keyPath === '' || ! is_string($keyId) || $keyId === '') {
            abort(503, 'Supabase P2P JWT signing key belum dikonfigurasi.');
        }

        $privateKey = file_get_contents($keyPath);
        if ($privateKey === false) {
            abort(503, 'Private key Supabase P2P tidak dapat dibaca.');
        }
        $signingKey = openssl_pkey_get_private($privateKey);
        $keyDetails = $signingKey ? openssl_pkey_get_details($signingKey) : false;
        if (! $keyDetails || $keyDetails['type'] !== OPENSSL_KEYTYPE_RSA) {
            abort(503, 'Signing key Supabase P2P harus berupa private key RSA yang valid.');
        }

        $header = $this->encode([
            'alg' => 'RS256',
            'typ' => 'JWT',
            'kid' => $keyId,
        ]);
        $now = time();
        $payload = $this->encode([
            'iss' => rtrim((string) $config['url'], '/').'/auth/v1',
            'aud' => 'authenticated',
            'sub' => $peerId,
            'role' => 'authenticated',
            'iat' => $now,
            'exp' => $now + 300,
            'app_metadata' => [
                'user_id' => (string) $userId,
                'peer_id' => $peerId,
            ],
            'user_metadata' => [
                'name' => $name,
            ],
        ]);
        $message = $header.'.'.$payload;

        if (! openssl_sign($message, $signature, $signingKey, OPENSSL_ALGO_SHA256)) {
            abort(503, 'Private key Supabase P2P tidak dapat menandatangani JWT.');
        }

        return $message.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    }

    private function encode(array $value): string
    {
        return rtrim(strtr(base64_encode(json_encode($value, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }
}
