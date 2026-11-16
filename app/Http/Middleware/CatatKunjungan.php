<?php

namespace App\Http\Middleware;

use App\Support\Kunjungan;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CatatKunjungan
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $rute = $request->route();

        if (
            $request->isMethod('GET')
            && $request->accepts('text/html')
            && $response->isSuccessful()
            && $rute?->getName()
            && ! str_starts_with($rute->getName(), 'admin.')
        ) {
            Kunjungan::catat(
                $rute->getName(),
                $rute->getName() === 'tempat' ? $request->route('slug') : null,
            );
        }

        return $response;
    }
}
