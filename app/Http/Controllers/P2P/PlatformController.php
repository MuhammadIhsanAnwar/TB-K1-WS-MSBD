<?php

namespace App\Http\Controllers\P2P;

use App\Http\Controllers\Controller;
use App\Services\P2P\PeerRegistry;
use App\Services\P2P\SupabaseToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformController extends Controller
{
    public function index()
    {
        return view('p2p.index');
    }

    public function register(Request $request, PeerRegistry $peers, SupabaseToken $tokens): JsonResponse
    {
        $data = $request->validate([
            'peer_id' => ['required', 'uuid'],
            'label' => ['required', 'string', 'max:80'],
        ]);
        $user = $this->user($request);
        $device = $peers->register($user['id'], $data['peer_id'], $data['label']);
        $supabase = config('services.supabase.katalog');
        abort_unless(
            is_string($supabase['url'] ?? null)
                && $supabase['url'] !== ''
                && is_string($supabase['publishable_key'] ?? null)
                && $supabase['publishable_key'] !== '',
            503,
            'URL dan publishable key Supabase DB1 belum dikonfigurasi.'
        );

        return $this->noStore([
            'peer_id' => $device['id'],
            'user_id' => $user['id'],
            'access_token' => $tokens->issue($user['id'], $device['id'], $user['name']),
            'expires_in' => 300,
            'supabase_url' => $supabase['url'],
            'publishable_key' => $supabase['publishable_key'],
            'ice_servers' => $this->iceServers($device['id']),
            'turn_available' => (bool) config('services.turn.urls'),
        ]);
    }

    public function refreshToken(Request $request, PeerRegistry $peers, SupabaseToken $tokens): JsonResponse
    {
        $data = $request->validate(['peer_id' => ['required', 'uuid']]);
        $user = $this->user($request);
        $device = $peers->assertOwnedDevice($data['peer_id'], $user['id']);

        return $this->noStore([
            'access_token' => $tokens->issue($user['id'], $device['id'], $user['name']),
            'expires_in' => 300,
            'ice_servers' => $this->iceServers($device['id']),
            'turn_available' => (bool) config('services.turn.urls'),
        ]);
    }

    public function connect(Request $request, string $peer, PeerRegistry $peers): JsonResponse
    {
        $data = $request->validate([
            'peer_id' => ['required', 'uuid'],
        ]);
        $session = $peers->startConnection($this->user($request)['id'], $data['peer_id'], $peer);

        return response()->json([
            'session_id' => $session['id'],
            'requester_peer_id' => $session['requester_peer_id'],
            'target_peer_id' => $session['target_peer_id'],
            'status' => $session['status'],
            'expires_at' => $session['expires_at'],
        ], 201);
    }

    public function pending(Request $request, PeerRegistry $peers): JsonResponse
    {
        $data = $request->validate(['peer_id' => ['required', 'uuid']]);

        return response()->json([
            'connections' => $peers->pendingConnections($this->user($request)['id'], $data['peer_id']),
        ]);
    }

    public function connection(Request $request, string $session, PeerRegistry $peers): JsonResponse
    {
        $data = $request->validate(['peer_id' => ['required', 'uuid']]);

        return response()->json(
            $peers->connection($this->user($request)['id'], $data['peer_id'], $session)
        );
    }

    public function respond(Request $request, string $session, string $decision, PeerRegistry $peers): JsonResponse
    {
        abort_unless(in_array($decision, ['accept', 'reject'], true), 404);
        $data = $request->validate(['peer_id' => ['required', 'uuid']]);
        $connection = $peers->respond(
            $this->user($request)['id'],
            $data['peer_id'],
            $session,
            $decision === 'accept',
        );

        return response()->json([
            'session_id' => $connection['id'],
            'requester_peer_id' => $connection['requester_peer_id'],
            'target_peer_id' => $connection['target_peer_id'],
            'status' => $connection['status'],
        ]);
    }

    private function user(Request $request): array
    {
        $user = $request->session()->get('pengguna');

        return [
            'id' => (int) $user['id'],
            'name' => (string) $user['nama'],
        ];
    }

    private function iceServers(string $peerId): array
    {
        $servers = [['urls' => 'stun:stun.l.google.com:19302']];
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
        }

        return $servers;
    }

    private function noStore(array $payload): JsonResponse
    {
        return response()->json($payload)->header('Cache-Control', 'no-store, private');
    }
}
