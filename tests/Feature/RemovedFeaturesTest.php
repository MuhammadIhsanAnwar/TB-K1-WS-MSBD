<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RemovedFeaturesTest extends TestCase
{
    public function test_benang_merah_and_cerita_medan_routes_are_not_registered(): void
    {
        $this->assertFalse(Route::has('jelajah'));
        $this->assertFalse(Route::has('jelajah.graf'));
        $this->assertFalse(Route::has('admin.cerita'));
        $this->assertFalse(Route::has('admin.akun.hapus'));
        $this->assertTrue(Route::has('admin.ulasan.hapus'));
        $this->assertTrue(Route::has('admin.tempat.simpan'));
    }
}
