<?php

namespace Tests\Feature;

use App\Support\Katalog;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class Halaman404Test extends TestCase
{
    public function test_not_found_page_does_not_show_a_search_bar(): void
    {
        config(['services.sparql.endpoint' => 'https://sparql.test/query']);
        Katalog::forgetCache();
        Http::fake(['*' => Http::response([
            'head' => ['vars' => ['s', 'p', 'o']],
            'results' => ['bindings' => []],
        ])]);

        $this->get('/alamat-tidak-ada')
            ->assertNotFound()
            ->assertSee('Belum ada di peta')
            ->assertDontSee('<form class="tanya-medan"', false);
    }
}
