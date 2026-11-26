<?php

namespace Tests\Feature;

use Tests\TestCase;

class PetaTempatTampilTest extends TestCase
{
    public function test_map_list_initially_shows_ten_places_and_offers_more(): void
    {
        $tempat = array_map(fn (int $nomor) => [
            'slug' => 'tempat-'.$nomor,
            'nama' => 'Tempat '.$nomor,
            'kecamatan' => 'Medan Kota',
            'lat' => 3.5,
            'lng' => 98.6,
            'kategori' => 'sejarah',
            'foto' => 'kesawan.jpg',
            'jam' => 'Buka 24 jam',
            'akses_gratis' => true,
            'tiket' => [],
            'rating' => 0,
            'jumlahRating' => 0,
            'jumlahUlasan' => 0,
            'jumlahKunjungan' => 0,
            'jumlahSuka' => 0,
        ], range(1, 11));

        $html = view('situs.peta', compact('tempat'))->render();

        preg_match_all('/<article\b[^>]*class="mv-peta-kartu"[^>]*>/', $html, $kartu);
        $jumlahTersembunyi = count(array_filter(
            $kartu[0],
            fn (string $tag) => preg_match('/\shidden(?:\s|>)/', $tag) === 1,
        ));

        $this->assertCount(11, $kartu[0]);
        $this->assertSame(1, $jumlahTersembunyi);
        $this->assertStringContainsString('data-batas-awal="10"', $html);
        $this->assertStringContainsString('Tampilkan lebih banyak tempat', $html);
    }
}
