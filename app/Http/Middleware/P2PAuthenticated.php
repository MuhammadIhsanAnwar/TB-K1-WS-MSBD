<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class P2PAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->session()->get('pengguna');

        if (! is_array($user) || ! isset($user['id']) || ! ctype_digit((string) $user['id'])) {
            return response()->json(['message' => 'Sesi masuk tidak valid atau sudah berakhir.'], 401);
        }

        return $next($request);
    }
}
