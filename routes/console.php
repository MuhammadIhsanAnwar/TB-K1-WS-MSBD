<?php

use App\Support\AkunNonaktifKedaluwarsa;
use App\Support\TiketKedaluwarsa;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(fn () => TiketKedaluwarsa::perbaruiTerjadwal())
    ->name('kedaluwarsakan-tiket-setelah-jam-operasional')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::call(fn () => AkunNonaktifKedaluwarsa::hapusKedaluwarsa())
    ->name('hapus-akun-nonaktif-kedaluwarsa')
    ->everyMinute()
    ->withoutOverlapping();
