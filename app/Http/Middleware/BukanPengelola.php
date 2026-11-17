<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BukanPengelola
{
    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->session()->get('pengguna.peran'), ['admin', 'superadmin'], true)) {
            abort(403, 'Fitur ini hanya tersedia untuk wisatawan.');
        }

        return $next($request);
    }
}
