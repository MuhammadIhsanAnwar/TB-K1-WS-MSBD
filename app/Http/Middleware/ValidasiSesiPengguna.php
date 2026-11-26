<?php

namespace App\Http\Middleware;

use App\Support\SupabaseApi;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidasiSesiPengguna
{
    public function handle(Request $request, Closure $next): Response
    {
        $pengguna = $request->session()->get('pengguna');
        if (! is_array($pengguna) || empty($pengguna['email'])) {
            return $next($request);
        }

        $akun = (new SupabaseApi)->findUserByEmail($pengguna['email']);
        if (! $akun) {
            $request->session()->forget('pengguna');
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('masuk')
                ->withErrors(['email' => 'Akun ini sudah dihapus permanen. Sesi di perangkat ini telah diakhiri.']);
        }

        $request->attributes->set('akun_sesi', $akun);

        return $next($request);
    }
}
