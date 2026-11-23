<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use App\Support\Katalog;
use Tests\TestCase;

class SampulBerandaBanyakSlotTest extends TestCase
{
    protected function tearDown(): void
    {
        Cache::setDefaultDriver('array');
        Cache::store('array')->flush();
        Katalog::forgetCache();

        parent::tearDown();
    }

    public function test_superadmin_can_view_and_save_more_than_four_cover_slots(): void
    {
        Cache::setDefaultDriver('array');
        Cache::store('array')->flush();
        Katalog::forgetCache();
        config([
            'services.sparql.endpoint' => 'https://sparql.test/query',
            'services.supabase.katalog.url' => 'https://katalog.test',
            'services.supabase.katalog.secret_key' => 'secret-key',
        ]);

        $base = 'https://medanvisit.neoverse.my.id/';
        $ontology = $base.'ontologi#';
        $bindings = [];
        $slots = [];
        for ($i = 1; $i <= 5; $i++) {
            $slug = 'tempat-'.$i;
            $subject = $base.'id/'.$slug;
            $bindings[] = $this->triple($subject, 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type', $ontology.'SitusSejarah', 'uri');
            $bindings[] = $this->triple($subject, 'https://schema.org/name', 'Tempat '.$i);
            $slots[] = ['slug' => $slug, 'foto' => null];
        }

        Http::fake(function (ClientRequest $request) use ($bindings, $slots) {
            if (str_contains($request->url(), 'sparql.test')) {
                return Http::response([
                    'head' => ['vars' => ['s', 'p', 'o']],
                    'results' => ['bindings' => $bindings],
                ]);
            }
            if (str_contains($request->url(), '/rest/v1/pengaturan_situs') && $request->method() === 'GET') {
                return Http::response([['nilai' => $slots]]);
            }

            return Http::response([]);
        });

        $this->withSession(['pengguna' => [
            'id' => 1,
            'nama' => 'Super Admin',
            'email' => 'superadmin@example.test',
            'peran' => 'superadmin',
        ]])->get('/admin/beranda')
            ->assertOk()
            ->assertSee('Slot 5')
            ->assertSee('Tambah slot');

        $input = [];
        foreach (range(1, 5) as $i) {
            $input[$i - 1] = ['slug' => 'tempat-'.$i];
        }

        $this->withSession(['pengguna' => [
            'id' => 1,
            'nama' => 'Super Admin',
            'email' => 'superadmin@example.test',
            'peran' => 'superadmin',
        ]])->post('/admin/beranda', ['slot' => $input])
            ->assertRedirect('/admin/beranda')
            ->assertSessionHas('pesan', 'Sampul beranda disimpan: 5 foto, tampil sesuai urutan slot.');

        Http::assertSent(fn (ClientRequest $request) =>
            str_contains($request->url(), '/rest/v1/pengaturan_situs')
            && $request->method() === 'POST'
            && count($request['nilai']) === 5
        );
    }

    private function triple(string $subject, string $predicate, string $object, string $objectType = 'literal'): array
    {
        return [
            's' => ['type' => 'uri', 'value' => $subject],
            'p' => ['type' => 'uri', 'value' => $predicate],
            'o' => ['type' => $objectType, 'value' => $object],
        ];
    }
}
