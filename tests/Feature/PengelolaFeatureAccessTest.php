<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PengelolaFeatureAccessTest extends TestCase
{
    public function test_admin_and_superadmin_cannot_use_traveler_features(): void
    {
        Http::fake(fn () => Http::response([]));

        foreach (['admin', 'superadmin'] as $peran) {
            $this->withSession(['pengguna' => [
                'id' => 73,
                'nama' => ucfirst($peran),
                'email' => $peran.'@example.test',
                'peran' => $peran,
            ]]);

            $this->get('/rencana')->assertForbidden();
            $this->get('/rencana/saya')->assertForbidden();
            $this->get('/r/bagikan-kode')->assertForbidden();
            $this->get('/MedanVisit-Hub')->assertForbidden();
            $this->get('/hubungi-admin')->assertForbidden();
            $this->get('/tempat/kolam-renang-primbana/tiket')->assertForbidden();
            $this->get('/tiket')->assertForbidden();
            $this->get('/ulasan-saya')->assertForbidden();
            $this->post('/tempat/kolam-renang-primbana/ulasan', [
                'bintang' => 5,
                'teks' => 'Pengalaman kunjungan yang menyenangkan.',
            ])->assertForbidden();
            $this->post('/rencana/simpan', [
                'nama' => 'Rute wisata',
            ])->assertForbidden();
            $this->postJson('/api/p2p/peers/register', [
                'peer_id' => '54f53d49-a38c-47dd-a627-8d0d83d8c874',
                'label' => 'Browser test',
            ])->assertForbidden();
        }
    }
}
