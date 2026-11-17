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
        if (! $keyDetails) {
            abort(503, 'Private key Supabase P2P tidak valid.');
        }

        if ($keyDetails['type'] === OPENSSL_KEYTYPE_RSA) {
            $algorithm = 'RS256';
        } elseif (
            $keyDetails['type'] === OPENSSL_KEYTYPE_EC
            && ($keyDetails['ec']['curve_oid'] ?? null) === '1.2.840.10045.3.1.7'
        ) {
            $algorithm = 'ES256';
        } else {
            abort(503, 'Signing key Supabase P2P harus berupa RSA atau EC P-256.');
        }

        $header = $this->encode([
            'alg' => $algorithm,
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

        if ($algorithm === 'ES256') {
            $signature = $this->ecdsaDerToJose($signature);
        }

        return $message.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    }

    private function ecdsaDerToJose(string $signature): string
    {
        $offset = 0;
        abort_unless(isset($signature[$offset]) && ord($signature[$offset++]) === 0x30, 503, 'Format signature ES256 tidak valid.');
        $sequenceLength = $this->readDerLength($signature, $offset);
        abort_unless($sequenceLength === strlen($signature) - $offset, 503, 'Format signature ES256 tidak valid.');

        $r = $this->readDerInteger($signature, $offset);
        $s = $this->readDerInteger($signature, $offset);
        abort_unless($offset === strlen($signature), 503, 'Format signature ES256 tidak valid.');

        return $r.$s;
    }

    private function readDerInteger(string $signature, int &$offset): string
    {
        abort_unless(isset($signature[$offset]) && ord($signature[$offset++]) === 0x02, 503, 'Format signature ES256 tidak valid.');
        $length = $this->readDerLength($signature, $offset);
        abort_unless($length > 0 && $offset + $length <= strlen($signature), 503, 'Format signature ES256 tidak valid.');

        $integer = ltrim(substr($signature, $offset, $length), "\0");
        $offset += $length;
        abort_unless($integer !== '' && strlen($integer) <= 32, 503, 'Format signature ES256 tidak valid.');

        return str_pad($integer, 32, "\0", STR_PAD_LEFT);
    }

    private function readDerLength(string $signature, int &$offset): int
    {
        abort_unless(isset($signature[$offset]), 503, 'Format signature ES256 tidak valid.');
        $length = ord($signature[$offset++]);
        if ($length < 0x80) {
            return $length;
        }

        $byteCount = $length & 0x7F;
        abort_unless($byteCount > 0 && $byteCount <= 2 && $offset + $byteCount <= strlen($signature), 503, 'Format signature ES256 tidak valid.');
        $length = 0;
        for ($i = 0; $i < $byteCount; $i++) {
            $length = ($length << 8) | ord($signature[$offset++]);
        }

        return $length;
    }

    private function encode(array $value): string
    {
        return rtrim(strtr(base64_encode(json_encode($value, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }
}
