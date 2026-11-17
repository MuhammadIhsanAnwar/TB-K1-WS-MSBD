<?php

namespace App\Services\P2P;

class IceServerConfiguration
{
    public function forPeer(string $peerId): array
    {
        abort_unless(config('services.webrtc.enabled'), 503, 'WebRTC sedang dinonaktifkan.');

        $stunUrl = config('services.webrtc.stun_url');
        abort_unless(
            is_string($stunUrl) && preg_match('/^stuns?:/i', $stunUrl) === 1,
            503,
            'WEBRTC_STUN_URL harus berisi URL STUN yang valid.'
        );

        $servers = [
            ['urls' => $stunUrl],
            ['urls' => 'stun:stun.relay.metered.ca:80'],
        ];
        $turnAvailable = false;
        $provider = config('services.webrtc.turn_provider', 'coturn');

        if ($provider === 'metered') {
            $urls = config('services.webrtc.metered.urls', []);
            $username = config('services.webrtc.metered.username');
            $credential = config('services.webrtc.metered.credential');

            abort_unless(
                is_array($urls)
                    && $urls !== []
                    && collect($urls)->every(fn ($url) => is_string($url) && preg_match('/^turns?:/i', $url) === 1),
                503,
                'METERED_TURN_URLS harus berisi URL TURN yang valid.'
            );
            abort_unless(
                is_string($username) && $username !== ''
                    && is_string($credential) && $credential !== '',
                503,
                'Kredensial TURN Metered belum dikonfigurasi di server.'
            );

            $servers[] = [
                'urls' => $urls,
                'username' => $username,
                'credential' => $credential,
                'credentialType' => 'password',
            ];
            $turnAvailable = true;
        } elseif ($provider === 'coturn') {
            $urls = config('services.turn.urls', []);

            if ($urls !== []) {
                $secret = config('services.turn.shared_secret');
                abort_unless(is_string($secret) && $secret !== '', 503, 'TURN_URLS diisi tetapi TURN_SHARED_SECRET belum dikonfigurasi.');
                abort_unless(
                    collect($urls)->every(fn ($url) => is_string($url) && preg_match('/^turns?:/i', $url) === 1),
                    503,
                    'TURN_URLS hanya boleh berisi URL turn: atau turns:.'
                );
                $lifetime = (int) config('services.turn.credential_lifetime');
                abort_unless($lifetime >= 60 && $lifetime <= 86400, 503, 'TURN_CREDENTIAL_LIFETIME harus antara 60 dan 86400 detik.');

                $expiry = time() + $lifetime;
                $username = $expiry.':'.$peerId;
                $servers[] = [
                    'urls' => $urls,
                    'username' => $username,
                    'credential' => base64_encode(hash_hmac('sha1', $username, $secret, true)),
                    'credentialType' => 'password',
                ];
                $turnAvailable = true;
            }
        } else {
            abort(503, 'WEBRTC_TURN_PROVIDER tidak didukung.');
        }

        return [
            'ice_servers' => $servers,
            'turn_available' => $turnAvailable,
        ];
    }
}
