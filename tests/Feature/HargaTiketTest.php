<?php

namespace Tests\Feature;

use App\Support\Asisten;
use App\Support\Katalog;
use App\Support\Semantik;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HargaTiketTest extends TestCase
{
    private array $bindings = [];

    protected function setUp(): void
    {
        parent::setUp();

        Cache::setDefaultDriver('array');
        Cache::store('array')->flush();
        Katalog::forgetCache();
        Carbon::setTestNow(Carbon::parse('2026-11-23 10:00:00', 'Asia/Jakarta'));
        config(['services.sparql.endpoint' => 'https://sparql.test/query']);
        $this->gunakanKatalogRdf();

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'sparql.test')) {
                return Http::response([
                    'head' => ['vars' => ['s', 'p', 'o']],
                    'results' => ['bindings' => $this->bindings],
                ]);
            }

            return Http::response([]);
        });
    }

    protected function tearDown(): void
    {
        Cache::store('array')->flush();
        Katalog::forgetCache();
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_paid_place_without_a_verified_offer_is_not_reported_as_free(): void
    {
        $graph = array_column(Semantik::jsonld()['@graph'], null, '@id');
        $museum = $graph['https://medanvisit.neoverse.my.id/id/museum-negeri'];
        $park = $graph['https://medanvisit.neoverse.my.id/id/taman-gratis'];
        $zoo = $graph['https://medanvisit.neoverse.my.id/id/medan-zoo'];

        $this->assertFalse($museum['isAccessibleForFree']);
        $this->assertArrayNotHasKey('offers', $museum);
        $this->assertTrue($park['isAccessibleForFree']);
        $this->assertCount(1, $zoo['offers']);
        $this->assertSame('Hari kerja', $zoo['offers'][0]['name']);
        $this->assertSame(15000, $zoo['offers'][0]['price']);
    }

    public function test_example_flagged_offers_are_not_published_as_jsonld_or_catalog_prices(): void
    {
        $museum = Katalog::get('museum-contoh');
        $graph = array_column(Semantik::jsonld()['@graph'], null, '@id');

        $this->assertTrue($museum['harga_contoh']);
        $this->assertSame([], $museum['tiket']);
        $this->assertFalse($graph['https://medanvisit.neoverse.my.id/id/museum-contoh']['isAccessibleForFree']);
        $this->assertArrayNotHasKey('offers', $graph['https://medanvisit.neoverse.my.id/id/museum-contoh']);
    }

    public function test_chatbot_reports_the_zoo_weekday_rate_without_calling_it_an_example(): void
    {
        $jawaban = Asisten::jawab('Berapa harga tiket Medan Zoo?');

        $this->assertStringContainsString('Rp15.000', $jawaban['teks'][0]);
        $this->assertStringContainsString('Senin-Jumat', $jawaban['teks'][0]);
        $this->assertStringNotContainsString('contoh', mb_strtolower($jawaban['teks'][0]));
    }

    public function test_chatbot_does_not_call_an_unverified_paid_place_free(): void
    {
        $jawaban = Asisten::jawab('Berapa harga tiket Museum Negeri?');

        $this->assertStringContainsString('belum tersedia', mb_strtolower($jawaban['teks'][0]));
        $this->assertStringNotContainsString('tidak memungut', mb_strtolower($jawaban['teks'][0]));
        $this->assertSame('Harga belum tersedia', $jawaban['tempat'][0]['harga']);
    }

    public function test_weekend_booking_is_rejected_for_a_weekday_only_ticket(): void
    {
        $tanggal = Carbon::today('Asia/Jakarta')->next(Carbon::SATURDAY)->toDateString();
        $this->withoutExceptionHandling();

        $this->withSession(['pengguna' => ['id' => 7, 'peran' => 'wisatawan']])
            ->from('/tempat/medan-zoo/tiket')
            ->post('/tempat/medan-zoo/tiket', ['tanggal' => $tanggal, 'jumlah' => [1]])
            ->assertRedirect('/tempat/medan-zoo/tiket')
            ->assertSessionHasErrors('tanggal');
    }

    public function test_weekday_booking_keeps_the_published_ticket_price(): void
    {
        $tanggal = Carbon::today('Asia/Jakarta')->toDateString();

        $this->withSession(['pengguna' => ['id' => 7, 'peran' => 'wisatawan']])
            ->post('/tempat/medan-zoo/tiket', ['tanggal' => $tanggal, 'jumlah' => [1]])
            ->assertRedirect(route('checkout'))
            ->assertSessionHas('pilihan', fn (array $pilihan) => $pilihan['tanggal'] === $tanggal
                && $pilihan['item'][0]['harga'] === 15000);
    }

    public function test_checkout_prefills_phone_number_from_account_session(): void
    {
        $tanggal = Carbon::today('Asia/Jakarta')->toDateString();

        $this->withSession([
            'pengguna' => ['id' => 7, 'nama' => 'Pengguna Tes', 'email' => '', 'peran' => 'wisatawan', 'no_hp' => '081234567890'],
            'pilihan' => [
                'tempat' => 'medan-zoo',
                'tanggal' => $tanggal,
                'item' => [['jenis' => 'Dewasa', 'harga' => 15000, 'jumlah' => 1, 'subtotal' => 15000]],
            ],
        ])
            ->get(route('checkout'))
            ->assertOk()
            ->assertSee('name="hp" value="081234567890"', false);
    }

    public function test_booking_rejects_dates_other_than_today(): void
    {
        $besok = Carbon::tomorrow('Asia/Jakarta')->toDateString();

        $this->withSession(['pengguna' => ['id' => 7, 'peran' => 'wisatawan']])
            ->from('/tempat/medan-zoo/tiket')
            ->post('/tempat/medan-zoo/tiket', ['tanggal' => $besok, 'jumlah' => [1]])
            ->assertRedirect('/tempat/medan-zoo/tiket')
            ->assertSessionHasErrors('tanggal');
    }

    public function test_booking_is_rejected_outside_the_venue_opening_hours(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-23 18:00:00', 'Asia/Jakarta'));

        $this->withSession(['pengguna' => ['id' => 7, 'peran' => 'wisatawan']])
            ->from('/tempat/medan-zoo/tiket')
            ->post('/tempat/medan-zoo/tiket', [
                'tanggal' => Carbon::today('Asia/Jakarta')->toDateString(),
                'jumlah' => [1],
            ])
            ->assertRedirect('/tempat/medan-zoo/tiket')
            ->assertSessionHasErrors('tanggal');
    }

    public function test_checkout_rechecks_opening_hours_before_creating_an_order(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-23 18:00:00', 'Asia/Jakarta'));

        $this->withSession([
            'pengguna' => ['id' => 7, 'peran' => 'wisatawan'],
            'pilihan' => [
                'tempat' => 'medan-zoo',
                'tanggal' => Carbon::today('Asia/Jakarta')->toDateString(),
                'item' => [['jenis' => 'Dewasa', 'harga' => 15000, 'jumlah' => 1, 'subtotal' => 15000]],
            ],
        ])
            ->from(route('checkout'))
            ->post(route('checkout'))
            ->assertRedirect(route('checkout'))
            ->assertSessionHasErrors('jam_operasional');
    }

    private function gunakanKatalogRdf(): void
    {
        $base = 'https://medanvisit.neoverse.my.id/';
        $ontology = $base.'ontologi#';
        $schema = 'https://schema.org/';
        $rdf = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#';
        $xsd = 'http://www.w3.org/2001/XMLSchema#';
        $zoo = $base.'id/medan-zoo';
        $museum = $base.'id/museum-negeri';
        $museumContoh = $base.'id/museum-contoh';
        $park = $base.'id/taman-gratis';
        $offer = 'offer-medan-zoo';
        $offerContoh = 'offer-museum-contoh';

        $this->bindings = [
            $this->triple($zoo, $rdf.'type', $ontology.'RekreasiAir', 'uri'),
            $this->triple($zoo, $schema.'name', 'Medan Zoo'),
            $this->triple($zoo, $schema.'isAccessibleForFree', 'false', 'literal', $xsd.'boolean'),
            $this->triple($zoo, $ontology.'hariTiket', 'Senin-Jumat'),
            $this->triple($zoo, $ontology.'keteranganJam', 'Senin-Jumat, 09.00-17.00'),
            $this->triple($zoo, $schema.'offers', $offer, 'bnode'),
            $this->triple($offer, $schema.'name', 'Hari kerja', 'literal', null, 'bnode'),
            $this->triple($offer, $schema.'price', '15000', 'literal', $xsd.'integer', 'bnode'),
            $this->triple($museum, $rdf.'type', $ontology.'Museum', 'uri'),
            $this->triple($museum, $schema.'name', 'Museum Negeri'),
            $this->triple($museum, $schema.'isAccessibleForFree', 'false', 'literal', $xsd.'boolean'),
            $this->triple($museumContoh, $rdf.'type', $ontology.'Museum', 'uri'),
            $this->triple($museumContoh, $schema.'name', 'Museum Contoh'),
            $this->triple($museumContoh, $schema.'isAccessibleForFree', 'false', 'literal', $xsd.'boolean'),
            $this->triple($museumContoh, $ontology.'hargaContoh', 'true', 'literal', $xsd.'boolean'),
            $this->triple($museumContoh, $schema.'offers', $offerContoh, 'bnode'),
            $this->triple($offerContoh, $schema.'name', 'Umum', 'literal', null, 'bnode'),
            $this->triple($offerContoh, $schema.'price', '25000', 'literal', $xsd.'integer', 'bnode'),
            $this->triple($park, $rdf.'type', $ontology.'RuangPublik', 'uri'),
            $this->triple($park, $schema.'name', 'Taman Gratis'),
            $this->triple($park, $schema.'isAccessibleForFree', 'true', 'literal', $xsd.'boolean'),
        ];
    }

    private function triple(
        string $subject,
        string $predicate,
        string $object,
        string $objectType = 'literal',
        ?string $datatype = null,
        string $subjectType = 'uri',
    ): array {
        return [
            's' => ['type' => $subjectType, 'value' => $subject],
            'p' => ['type' => 'uri', 'value' => $predicate],
            'o' => array_filter([
                'type' => $objectType,
                'value' => $object,
                'datatype' => $datatype,
            ], fn ($value) => $value !== null),
        ];
    }
}
