<?php

namespace Tests\Unit;

use Database\Seeders\WisatawanUlasanSeeder;
use Database\Seeders\WisatawanRatingSeeder;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class WisatawanUlasanSeederTest extends TestCase
{
    public function test_generated_review_text_is_plain_and_has_no_demo_markers(): void
    {
        $teksUlasan = new ReflectionMethod(WisatawanUlasanSeeder::class, 'teksUlasan');
        $teksUlasan->setAccessible(true);

        foreach (['sejarah', 'museum', 'religi', 'kuliner', 'ruang-publik', 'belanja', 'rekreasi-air'] as $kategori) {
            for ($indeks = 0; $indeks < 3; $indeks++) {
                $teks = $teksUlasan->invoke(null, $kategori, $indeks);

                $this->assertNotSame('', trim($teks));
                $this->assertDoesNotMatchRegularExpression('/simulasi|contoh|perkiraan|estimasi|dummy|demo/i', $teks);
                $this->assertStringNotContainsString('[', $teks);
                $this->assertStringNotContainsString('#', $teks);
            }
        }
    }

    public function test_target_place_review_counts_and_ratings_are_seeded_as_requested(): void
    {
        $seeder = new ReflectionClass(WisatawanRatingSeeder::class);
        $targets = $seeder->getConstant('TARGET_METRIK');
        $bintang = new ReflectionMethod(WisatawanRatingSeeder::class, 'bintang');
        $bintang->setAccessible(true);

        $this->assertSame([
            'Taman Cadika Pramuka' => [6032, 44],
            'Graha Maria Annai Velangkanni' => [4955, 48],
            'Masjid Agung Medan' => [4519, 48],
            'Taman Ahmad Yani' => [4481, 44],
            'Tjong A Fie Mansion' => [3656, 46],
            'Rahmat International Wildlife Museum & Gallery' => [3017, 46],
            'Museum Negeri Sumatera Utara' => [2941, 45],
            'Masjid Raya Al-Mashun' => [2221, 48],
            'Kantor Pos Besar Medan (Pos Bloc)' => [1718, 46],
            'Kuil Shri Mariamman' => [1004, 46],
        ], $targets);

        foreach ($targets as [$jumlah, $ratingPerSepuluh]) {
            $jumlahBintang = 0;
            for ($i = 0; $i < $jumlah; $i++) {
                $jumlahBintang += $bintang->invoke(null, $ratingPerSepuluh, $i, 123);
            }

            $this->assertSame($ratingPerSepuluh / 10, round($jumlahBintang / $jumlah, 1));
        }
    }
}
