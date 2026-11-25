<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AkunController;
use App\Http\Controllers\MidtransController;
use App\Http\Controllers\SampulBerandaController;
use App\Http\Controllers\SitusController;
use App\Http\Controllers\TiketController;
use Illuminate\Support\Facades\Route;


// Dokumen Linked Data publik. Tidak memakai pencatatan kunjungan supaya crawler
// dan pemakai data tidak membuat statistik kunjungan HTML.
Route::controller(SitusController::class)->group(function () {
    Route::get('/data/wisata.json', 'wisataJson')->name('data.wisata');
    Route::get('/data/wisata/{slug}.json', 'tempatJson')->name('data.tempat');
});

// ---------- publik ----------
Route::controller(SitusController::class)->middleware('kunjungan')->group(function () {
    Route::get('/', 'beranda')->name('beranda');
    Route::get('/cari', 'cari')->name('cari');
    Route::get('/cari/saran.json', 'saran')->name('cari.saran');
    Route::post('/asisten/mas-nonov', 'jawab')->middleware('throttle:12,1')->name('tanya.jawab');
    Route::get('/peta', 'peta')->name('peta');
    Route::get('/tempat/{slug}', 'tempat')->name('tempat');
    Route::post('/tempat/{slug}/sukai', 'sukai')->middleware('peran')->name('tempat.sukai');
    Route::get('/rencana', 'rencana')->middleware('bukan-pengelola')->name('rencana');
    Route::get('/r/{kode}', 'rencanaBagikan')->middleware('bukan-pengelola')->name('rencana.bagikan');
    Route::get('/ketentuan', 'ketentuan')->name('ketentuan');
    Route::get('/hubungi-admin', 'hubungiAdmin')->middleware('peran:wisatawan')->name('hubungi.admin');
    Route::post('/hubungi-admin', 'simpanPesanAdmin')->middleware('peran:wisatawan')->name('hubungi.admin.kirim');
    Route::get('/{jenis}/{slug}', 'entitas')->whereIn('jenis', ['tokoh', 'budaya', 'kuliner', 'peristiwa', 'kerajaan', 'transportasi'])->name('entitas');
});

// ---------- akun ----------
Route::controller(AkunController::class)->middleware('kunjungan')->group(function () {
    Route::get('/masuk', 'masuk')->name('masuk');
    Route::post('/masuk', 'prosesMasuk');
    Route::get('/masuk/google', 'google')->name('masuk.google');
    Route::get('/masuk/google/callback', 'googleCallback')->name('masuk.google.callback');
    Route::get('/lupa-password', 'lupaPassword')->name('password.request');
    Route::post('/lupa-password', 'kirimLinkPassword')->name('password.email');
    Route::get('/reset-password/{token}', 'formulirResetPassword')->name('password.reset');
    Route::post('/reset-password', 'ubahPassword')->name('password.update');
    Route::get('/daftar', 'daftar')->name('daftar');
    Route::post('/daftar', 'prosesDaftar');
    Route::get('/verifikasi-email', 'verifikasi')->name('verifikasi');
    Route::get('/verifikasi-email/{token}', 'konfirmasiEmail')->name('verifikasi.konfirmasi');
    Route::post('/verifikasi-email/kirim-ulang', 'kirimUlangVerifikasi')->name('verifikasi.kirim');
    Route::post('/keluar', 'keluar')->name('keluar');

    Route::middleware(['peran', 'kunjungan'])->group(function () {
        Route::get('/akun', 'akun')->name('akun');
        Route::get('/akun/pengaturan', 'pengaturan')->name('akun.pengaturan');
        Route::post('/akun/pengaturan', 'simpanPengaturan');
        Route::get('/ulasan-saya', 'ulasanSaya')->middleware('peran:wisatawan')->name('ulasan.saya');
        Route::post('/tempat/{slug}/ulasan', 'tulisUlasan')->middleware('peran:wisatawan')->name('ulasan.tulis');
        Route::put('/ulasan-saya/{id}', 'perbaruiUlasanSaya')->middleware('peran:wisatawan')->whereNumber('id')->name('ulasan.perbarui');
        Route::delete('/ulasan-saya/{id}', 'hapusUlasanSaya')->middleware('peran:wisatawan')->whereNumber('id')->name('ulasan.hapus-saya');
        Route::get('/rencana/saya', 'rencanaSaya')->middleware('peran:wisatawan')->name('rencana.saya');
        Route::post('/rencana/simpan', 'simpanRencana')->middleware('peran:wisatawan')->name('rencana.simpan');
        Route::put('/rencana/{kode}', 'perbaruiRencana')->middleware('peran:wisatawan')->name('rencana.perbarui');
        Route::delete('/rencana/{kode}', 'hapusRencana')->middleware('peran:wisatawan')->name('rencana.hapus');
    });
});

// ---------- tiket ----------
Route::controller(TiketController::class)->middleware(['peran:wisatawan', 'kunjungan'])->group(function () {
    Route::get('/tempat/{slug}/tiket', 'pilih')->name('tiket.pilih');
    Route::post('/tempat/{slug}/tiket', 'simpanPilihan');
    Route::get('/checkout', 'checkout')->name('checkout');
    Route::post('/checkout', 'bayar');
    Route::get('/pesanan/{kode}', 'status')->name('pesanan');
    Route::post('/pesanan/{kode}/bayar', 'bayarMidtrans')->name('pesanan.bayar');
    Route::post('/pesanan/{kode}/cek', 'cekPembayaran')->name('pesanan.cek');
    Route::get('/pesanan/{kode}/status', 'statusLokal')->name('pesanan.status-lokal');
    Route::get('/pesanan/{kode}/qris', 'qrisSandbox')->name('pesanan.qris');
    Route::post('/pesanan/{kode}/simulasi', 'simulasi')->name('pesanan.simulasi');
    Route::post('/pesanan/{kode}/batal', 'batal')->name('pesanan.batal');
    Route::get('/tiket', 'daftar')->name('tiket.saya');
    Route::get('/tiket/{kode}', 'eTiket')->name('tiket.e');
});

// notifikasi pembayaran dari server Midtrans (tanpa sesi; dikecualikan dari CSRF di bootstrap/app.php)
Route::post('/midtrans/notifikasi', [MidtransController::class, 'notifikasi'])->name('midtrans.notifikasi');

// ---------- kurator & admin ----------
Route::controller(AdminController::class)->middleware('peran:admin,superadmin')->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', 'ringkasan')->name('ringkasan');
    Route::get('/entitas', 'entitas')->name('entitas');
    Route::get('/entitas/{slug}', 'ubahEntitas')->name('entitas.ubah');
    Route::post('/entitas/{slug}', 'simpanEntitas');
    Route::post('/entitas/{slug}/status-operasional', 'simpanStatusOperasional')->name('entitas.status-operasional');
    Route::get('/relasi', 'relasi')->name('relasi');
    Route::post('/relasi', 'tambahRelasi');
    Route::get('/pesan', 'pesan')->name('pesan');
    Route::get('/pesan/{id}', 'detailPesan')->whereNumber('id')->name('pesan.detail');
    Route::post('/pesan/{id}/balas', 'balasPesan')->whereNumber('id')->name('pesan.balas');
    Route::get('/ulasan', 'ulasan')->name('ulasan');
    Route::delete('/ulasan/{id}', 'hapusUlasan')->name('ulasan.hapus');
    Route::get('/tiket', 'tiket')->name('tiket');
    Route::post('/tiket/validasi', 'validasi')->name('tiket.validasi');
    Route::get('/sinkronisasi', 'sinkronisasi')->name('sinkronisasi');
    Route::get('/tempat/baru', 'buatTempat')->name('tempat.baru');
    Route::post('/tempat', 'simpanTempat')->name('tempat.simpan');
    Route::delete('/tempat/{slug}', 'hapusTempat')->name('tempat.hapus');
});

Route::controller(AdminController::class)->prefix('admin')->name('admin.')->group(function () {
    Route::get('/insight', 'insight')->middleware('peran:admin,superadmin')->name('insight');
    Route::middleware('peran:superadmin')->group(function () {
        Route::get('/akun', 'akunAdmin')->name('akun');
        Route::post('/akun', 'buatAkun')->name('akun.buat');
        Route::patch('/akun/{id}', 'ubahAkun')->name('akun.ubah');
        Route::delete('/akun/{id}', 'hapusAkun')->name('akun.hapus');
        Route::patch('/akun/{id}/tangguhkan', 'tangguhkanAkun')->name('akun.tangguhkan');
        Route::post('/akun/{id}/tempat', 'tautkanTempat')->name('akun.tempat.tautkan');
        Route::delete('/akun/{id}/tempat/{slug}', 'lepasTempat')->name('akun.tempat.lepas');
        Route::get('/tempat', 'daftarTempat')->name('tempat.daftar');
    });
});

Route::controller(SampulBerandaController::class)->middleware('peran:superadmin')->prefix('admin')->name('admin.')->group(function () {
    Route::get('/beranda', 'tampil')->name('beranda');
    Route::post('/beranda', 'simpan');
});
