<?php

namespace Tests\Feature;

use App\Support\RekomendasiTempat;
use PHPUnit\Framework\TestCase;

class RekomendasiTempatTest extends TestCase
{
    public function test_recommendation_balances_ratings_and_likes(): void
    {
        $tempat = RekomendasiTempat::urutkan([
            ['slug' => 'rating-tinggi', 'nama' => 'Rating tinggi', 'rating' => 4.9, 'jumlahSuka' => 0],
            ['slug' => 'suka-tinggi', 'nama' => 'Suka tinggi', 'rating' => 2.5, 'jumlahSuka' => 1000],
            ['slug' => 'unggulan', 'nama' => 'Unggulan', 'rating' => 4.5, 'jumlahSuka' => 100],
        ]);

        $this->assertSame('unggulan', $tempat[0]['slug']);
    }
}
