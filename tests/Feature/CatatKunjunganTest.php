<?php

namespace Tests\Feature;

use App\Http\Middleware\CatatKunjungan;
use App\Support\Kunjungan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CatatKunjunganTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        Cache::store('array')->flush();
    }

    public function test_successful_public_html_page_views_are_counted(): void
    {
        $request = $this->requestForRoute('beranda');

        app(CatatKunjungan::class)->handle($request, fn () => response('Beranda', 200));

        $this->assertSame(1, Kunjungan::ringkasan(1)['total']);
    }

    public function test_admin_pages_and_unsuccessful_responses_are_not_counted_as_public_visits(): void
    {
        $adminRequest = $this->requestForRoute('admin.ringkasan');
        app(CatatKunjungan::class)->handle($adminRequest, fn () => response('Admin', 200));

        $publicRequest = $this->requestForRoute('beranda');
        app(CatatKunjungan::class)->handle($publicRequest, fn () => response('Redirect', 302));

        $this->assertSame(0, Kunjungan::ringkasan(1)['total']);
    }

    private function requestForRoute(string $name): Request
    {
        $route = app('router')->getRoutes()->getByName($name);
        $request = Request::create('/');
        $request->headers->set('Accept', 'text/html');
        $request->setRouteResolver(fn () => $route);

        return $request;
    }
}
