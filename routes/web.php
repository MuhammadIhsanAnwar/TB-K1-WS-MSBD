<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AkunController;
use App\Http\Controllers\P2P\PlatformController as P2PPlatformController;
use App\Http\Controllers\SitusController;
use App\Http\Controllers\TiketController;
use App\Http\Middleware\P2PAuthenticated;
use Illuminate\Support\Facades\Route;

// ---------- komunikasi peer-to-peer ----------
Route::get('/MedanVisitt-Hub', [P2PPlatformController::class, 'index'])
    ->middleware('peran')
    ->name('medanvisitt-hub.index');
Route::redirect('/p2p', '/MedanVisitt-Hub', 301)->name('p2p.legacy');
Route::middleware([P2PAuthenticated::class, 'throttle:60,1'])->prefix('api/p2p')->name('api.p2p.')->group(function () {
    Route::post('/peers/register', [P2PPlatformController::class, 'register'])->name('peers.register');
    Route::post('/token', [P2PPlatformController::class, 'refreshToken'])->name('token');
    Route::post('/peers/{peer}/connect', [P2PPlatformController::class, 'connect'])
        ->whereUuid('peer')
        ->name('peers.connect');
    Route::get('/connections/pending', [P2PPlatformController::class, 'pending'])->name('connections.pending');
    Route::get('/connections/{session}', [P2PPlatformController::class, 'connection'])
        ->whereUuid('session')
        ->name('connections.show');
    Route::post('/connections/{session}/{decision}', [P2PPlatformController::class, 'respond'])
        ->whereUuid('session')
        ->whereIn('decision', ['accept', 'reject'])
        ->name('connections.respond');
});

// ---------- publik ----------
Route::controller(SitusController::class)->middleware('kunjungan')->group(function () {
    Route::get('/', 'beranda')->name('beranda');
    Route::get('/cari', 'cari')->name('cari');
    Route::get('/cari/saran.json', 'saran')->name('cari.saran');
    Route::get('/tanya', 'tanya')->name('tanya');
    Route::post('/tanya', 'jawab')->name('tanya.jawab');
    Route::get('/peta', 'peta')->name('peta');
    Route::get('/tempat/{slug}', 'tempat')->name('tempat');
    Route::post('/tempat/{slug}/sukai', 'sukai')->name('tempat.sukai');
    Route::get('/rencana', 'rencana')->name('rencana');
    Route::get('/r/{kode}', 'rencanaBagikan')->name('rencana.bagikan');
    Route::get('/ketentuan', 'ketentuan')->name('ketentuan');
    Route::get('/hubungi-admin', 'hubungiAdmin')->middleware('peran:wisatawan,admin')->name('hubungi.admin');
    Route::post('/hubungi-admin', 'simpanPesanAdmin')->middleware('peran:wisatawan,admin')->name('hubungi.admin.kirim');
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
        Route::get('/ulasan-saya', 'ulasanSaya')->name('ulasan.saya');
        Route::post('/tempat/{slug}/ulasan', 'tulisUlasan')->name('ulasan.tulis');
        Route::get('/rencana/saya', 'rencanaSaya')->name('rencana.saya');
        Route::post('/rencana/simpan', 'simpanRencana')->name('rencana.simpan');
    });
});

// ---------- tiket ----------
Route::controller(TiketController::class)->middleware(['peran', 'kunjungan'])->group(function () {
    Route::get('/tempat/{slug}/tiket', 'pilih')->name('tiket.pilih');
    Route::post('/tempat/{slug}/tiket', 'simpanPilihan');
    Route::get('/checkout', 'checkout')->name('checkout');
    Route::post('/checkout', 'bayar');
    Route::get('/pesanan/{kode}', 'status')->name('pesanan');
    Route::post('/pesanan/{kode}/simulasi', 'simulasi')->name('pesanan.simulasi');
    Route::post('/pesanan/{kode}/batal', 'batal')->name('pesanan.batal');
    Route::get('/tiket', 'daftar')->name('tiket.saya');
    Route::get('/tiket/{kode}', 'eTiket')->name('tiket.e');
});

// ---------- kurator & admin ----------
Route::controller(AdminController::class)->middleware('peran:admin,superadmin')->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', 'ringkasan')->name('ringkasan');
    Route::get('/entitas', 'entitas')->name('entitas');
    Route::get('/entitas/{slug}', 'ubahEntitas')->name('entitas.ubah');
    Route::post('/entitas/{slug}', 'simpanEntitas');
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
    Route::get('/tempat', 'entitas')->defaults('jenis', 'tempat')->name('tempat.daftar');
    });
});
