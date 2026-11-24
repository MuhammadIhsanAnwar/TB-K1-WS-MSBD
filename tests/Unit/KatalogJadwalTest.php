<?php

namespace Tests\Unit;

use App\Support\Katalog;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class KatalogJadwalTest extends TestCase
{
    public function test_colon_formatted_hours_keep_the_declared_weekdays(): void
    {
        $jadwal = Katalog::jadwal([
            'jam' => 'Selasa-Minggu, 09:00-17:00',
            'jam_menit' => [540, 1020],
        ]);

        $this->assertSame([2, 3, 4, 5, 6, 7], $jadwal['hari']);
    }

    public function test_booking_hours_fall_back_to_day_specific_text_ranges(): void
    {
        $tempat = [
            'jam' => 'Senin 09:00-15:00; Selasa–Minggu dan hari libur 08:30–16:00',
            'jam_menit' => null,
        ];

        $this->assertTrue(Katalog::bukaSekarang($tempat, Carbon::parse('2026-11-23 14:59', 'Asia/Jakarta')));
        $this->assertFalse(Katalog::bukaSekarang($tempat, Carbon::parse('2026-11-23 15:00', 'Asia/Jakarta')));
        $this->assertTrue(Katalog::bukaSekarang($tempat, Carbon::parse('2026-11-24 15:59', 'Asia/Jakarta')));
        $this->assertFalse(Katalog::bukaSekarang($tempat, Carbon::parse('2026-11-24 16:00', 'Asia/Jakarta')));
    }

    public function test_unknown_hours_do_not_allow_ticket_booking(): void
    {
        $this->assertFalse(Katalog::bukaSekarang([
            'jam' => 'Buka pagi hingga malam',
            'jam_menit' => null,
        ], Carbon::parse('2026-11-23 10:00', 'Asia/Jakarta')));
    }
}
