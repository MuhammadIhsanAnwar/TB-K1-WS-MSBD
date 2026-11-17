<?php

namespace App\Services\P2P;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class PeerRegistry
{
    public function register(int $userId, string $peerId, string $label): array
    {
        $existing = $this->rows('peer_devices', [
            'select' => 'id,user_id,revoked_at',
            'id' => 'eq.'.$peerId,
            'limit' => 1,
        ])[0] ?? null;

        if ($existing) {
            abort_unless((int) $existing['user_id'] === $userId, 403, 'Peer ID sudah terdaftar pada akun lain.');
            abort_if($existing['revoked_at'] !== null, 410, 'Perangkat peer ini sudah dicabut.');

            return $this->patch('peer_devices', [
                'id' => 'eq.'.$peerId,
                'user_id' => 'eq.'.$userId,
            ], [
                'label' => $label,
                'last_seen_at' => now()->toIso8601String(),
                'updated_at' => now()->toIso8601String(),
            ]);
        }

        return $this->insert('peer_devices', [
            'id' => $peerId,
            'user_id' => $userId,
            'label' => $label,
            'last_seen_at' => now()->toIso8601String(),
        ]);
    }

    public function startConnection(int $userId, string $requesterPeerId, string $targetPeerId): array
    {
        abort_if($requesterPeerId === $targetPeerId, 422, 'Perangkat tidak dapat terhubung ke dirinya sendiri.');

        $requester = $this->ownedDevice($requesterPeerId, $userId);
        $target = $this->activeDevice($targetPeerId);
        abort_if((int) $requester['user_id'] === (int) $target['user_id'], 422, 'Gunakan peer dari akun lain.');
        abort_if($this->isBlocked((int) $requester['user_id'], (int) $target['user_id']), 403, 'Koneksi tidak dapat dibuat karena salah satu akun memblokir akun lainnya.');

        return $this->insert('peer_sessions', [
            'id' => (string) Str::uuid(),
            'requester_peer_id' => $requesterPeerId,
            'target_peer_id' => $targetPeerId,
            'requester_user_id' => $userId,
            'target_user_id' => (int) $target['user_id'],
            'status' => 'pending',
            'expires_at' => now()->addMinutes(5)->toIso8601String(),
        ]);
    }

    public function pendingConnections(int $userId, string $peerId): array
    {
        $this->ownedDevice($peerId, $userId);

        return $this->rows('peer_sessions', [
            'select' => 'id,requester_peer_id,target_peer_id,requester_user_id,target_user_id,status,expires_at,created_at',
            'target_peer_id' => 'eq.'.$peerId,
            'status' => 'eq.pending',
            'expires_at' => 'gt.'.now()->toIso8601String(),
            'order' => 'created_at.desc',
        ]);
    }

    public function connection(int $userId, string $peerId, string $sessionId): array
    {
        $session = $this->rows('peer_sessions', [
            'select' => 'id,requester_peer_id,target_peer_id,requester_user_id,target_user_id,status,expires_at',
            'id' => 'eq.'.$sessionId,
            'limit' => 1,
        ])[0] ?? null;

        abort_unless($session, 404, 'Permintaan koneksi tidak ditemukan.');
        abort_unless(
            in_array($peerId, [$session['requester_peer_id'], $session['target_peer_id']], true),
            403,
            'Peer ini bukan peserta permintaan koneksi.'
        );
        $this->ownedDevice($peerId, $userId);
        abort_if(now()->greaterThanOrEqualTo($session['expires_at']), 410, 'Sesi koneksi sudah kedaluwarsa.');

        return $session;
    }

    public function respond(int $userId, string $peerId, string $sessionId, bool $accept): array
    {
        $session = $this->connection($userId, $peerId, $sessionId);
        abort_unless($session['target_peer_id'] === $peerId, 403, 'Hanya peer tujuan yang dapat menjawab permintaan.');
        abort_unless($session['status'] === 'pending', 409, 'Permintaan koneksi tidak lagi menunggu jawaban.');
        abort_if(now()->greaterThanOrEqualTo($session['expires_at']), 410, 'Permintaan koneksi sudah kedaluwarsa.');

        if ($accept) {
            abort_if(
                $this->isBlocked((int) $session['requester_user_id'], (int) $session['target_user_id']),
                403,
                'Koneksi tidak dapat diterima karena salah satu akun memblokir akun lainnya.'
            );
        }

        $changes = [
            'status' => $accept ? 'accepted' : 'rejected',
            'updated_at' => now()->toIso8601String(),
        ];
        if ($accept) {
            $changes['expires_at'] = now()->addDay()->toIso8601String();
        }

        return $this->patch('peer_sessions', [
            'id' => 'eq.'.$sessionId,
            'target_peer_id' => 'eq.'.$peerId,
            'status' => 'eq.pending',
        ], $changes);
    }

    private function ownedDevice(string $peerId, int $userId): array
    {
        $device = $this->rows('peer_devices', [
            'select' => 'id,user_id,revoked_at',
            'id' => 'eq.'.$peerId,
            'user_id' => 'eq.'.$userId,
            'limit' => 1,
        ])[0] ?? null;
        abort_unless($device && $device['revoked_at'] === null, 403, 'Peer tidak terdaftar pada akun ini.');

        return $device;
    }

    private function activeDevice(string $peerId): array
    {
        $device = $this->rows('peer_devices', [
            'select' => 'id,user_id,revoked_at',
            'id' => 'eq.'.$peerId,
            'revoked_at' => 'is.null',
            'limit' => 1,
        ])[0] ?? null;
        abort_unless($device, 404, 'Perangkat peer tidak ditemukan atau sudah offline.');

        return $device;
    }

    private function isBlocked(int $firstUserId, int $secondUserId): bool
    {
        $blocked = $this->rows('peer_blocks', [
            'select' => 'id',
            'or' => '(and(blocker_user_id.eq.'.$firstUserId.',blocked_user_id.eq.'.$secondUserId.'),and(blocker_user_id.eq.'.$secondUserId.',blocked_user_id.eq.'.$firstUserId.'))',
            'limit' => 1,
        ]);

        return $blocked !== [];
    }

    private function rows(string $table, array $filters): array
    {
        return $this->request()->get($this->url($table), $filters)->throw()->json();
    }

    private function insert(string $table, array $record): array
    {
        $rows = $this->request()
            ->withHeaders(['Prefer' => 'return=representation'])
            ->post($this->url($table), $record)
            ->throw()
            ->json();

        return $rows[0] ?? throw new \RuntimeException('Supabase tidak mengembalikan peer yang dibuat.');
    }

    private function patch(string $table, array $filters, array $record): array
    {
        $rows = $this->request()
            ->withHeaders(['Prefer' => 'return=representation'])
            ->patch($this->url($table).'?'.http_build_query($filters), $record)
            ->throw()
            ->json();

        return $rows[0] ?? throw new \RuntimeException('Supabase tidak menemukan peer untuk diperbarui.');
    }

    private function request(): PendingRequest
    {
        $config = config('services.supabase.katalog');
        abort_unless($config['url'] && $config['secret_key'], 503, 'Konfigurasi Supabase DB1 belum lengkap.');

        return Http::acceptJson()
            ->withHeaders([
                'apikey' => $config['secret_key'],
                'Authorization' => 'Bearer '.$config['secret_key'],
            ])
            ->timeout(10);
    }

    private function url(string $path): string
    {
        return rtrim((string) config('services.supabase.katalog.url'), '/').'/rest/v1/'.$path;
    }
}
