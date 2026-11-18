<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'peran' => \App\Http\Middleware\Peran::class,
            'bukan-pengelola' => \App\Http\Middleware\BukanPengelola::class,
            'kunjungan' => \App\Http\Middleware\CatatKunjungan::class,
        ]);
        // server Midtrans tidak membawa token CSRF; keasliannya diperiksa lewat tanda tangan notifikasi
        $middleware->validateCsrfTokens(except: ['midtrans/notifikasi']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
