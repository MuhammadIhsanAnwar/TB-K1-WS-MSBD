<?php

namespace App\Http\Middleware;

use App\Support\SupabaseApi;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Memastikan pengguna sudah masuk (dan punya peran yang diminta).
 */
class Peran
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $pengguna = $request->session()->get('pengguna');
        if (! $pengguna) {
            return redirect()->route('masuk', ['kembali' => $request->getRequestUri()]);
        }

        if (
            $roles
            && ! in_array($pengguna['peran'], $roles, true)
            && $request->is('admin', 'admin/*')
            && ! empty($pengguna['email'])
        ) {
            $akun = (new SupabaseApi)->findUserByEmail($pengguna['email']);
            if ($akun) {
                $pengguna['id'] = $akun['id'];
                $pengguna['nama'] = $akun['name'];
                $pengguna['peran'] = $akun['peran'];
                $pengguna['foto_profil'] = $akun['foto_profil'] ?? null;
                $request->session()->put('pengguna', $pengguna);
            }
        }

        if (! empty($pengguna['email'])) {
            $akun = (new SupabaseApi)->findUserByEmail($pengguna['email']);
            if ($akun && ! empty($akun['ditangguhkan_pada'])) {
                $request->session()->forget('pengguna');
                $request->session()->invalidate();

                return redirect()->route('masuk')
                    ->withErrors(['email' => 'Akun ini ditangguhkan. Hubungi admin melalui halaman Hubungi Admin.']);
            }

            if ($akun && ! empty($akun['dinonaktifkan_pada'])) {
                $request->session()->forget('pengguna');
                $request->session()->invalidate();

                return redirect()->route('masuk')
                    ->withErrors(['email' => 'Akun ini sedang dinonaktifkan. Masuk kembali sebelum masa 3 bulan berakhir untuk mengaktifkannya.']);
            }

            if ($akun && ($pengguna['no_hp'] ?? null) !== ($akun['no_hp'] ?? null)) {
                $pengguna['no_hp'] = $akun['no_hp'] ?? null;
                $request->session()->put('pengguna', $pengguna);
            }
        }

        if ($roles && ! in_array($pengguna['peran'], $roles, true)) {
            abort(403, 'Akun ini tidak memiliki akses ke halaman tersebut.');
        }

        if (($pengguna['peran'] ?? null) === 'admin' && $request->is('admin', 'admin/*')) {
            $tempat = (new SupabaseApi)->tempatAdmin($pengguna['id']);
            abort_if($tempat === [], 403, 'Akun admin belum ditautkan ke tempat wisata. Hubungi superadmin.');
            $pengguna['tempat_slugs'] = $tempat;
            $request->session()->put('pengguna', $pengguna);
        }

        return $next($request);
    }
}
