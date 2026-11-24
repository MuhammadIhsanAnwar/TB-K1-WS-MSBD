<?php

namespace App\Http\Controllers;

use App\Support\Katalog;
use App\Support\Kunjungan;
use App\Support\Penyimpanan;
use App\Support\PesanLayanan;
use App\Support\Semantik;
use App\Services\TanyaMasNonovService;
use App\Services\TanyaMasNonovMemori;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SitusController extends Controller
{
    public function beranda()
    {
        $tempat = Katalog::tempat();
        $ulasan = Penyimpanan::ringkasanUlasan();
        $jumlahSukaTempat = Penyimpanan::ringkasanSukaTempat();
        $kategori = [];
        foreach (Katalog::KATEGORI as $k => $label) {
            $kategori[$k] = ['label' => $label, 'tempat' => Katalog::tempat($k)];
        }

        foreach ($tempat as &$t) {
            $ringkasan = $ulasan[$t['slug']] ?? ['rating' => 0, 'jumlahUlasan' => 0];
            $t['rating'] = $ringkasan['rating'];
            $t['jumlahUlasan'] = $ringkasan['jumlahUlasan'];
            $t['jumlahSuka'] = $jumlahSukaTempat[$t['slug']] ?? 0;
        }
        unset($t);

        return view('situs.beranda', [
            'kategori' => $kategori,
            'bertiket' => Katalog::bertiket(),
            'petaTempat' => array_values($tempat),
            'jumlahSukaTempat' => $jumlahSukaTempat,
        ]);
    }

    public function hubungiAdmin(Request $request)
    {
        $peran = $request->session()->get('pengguna.peran');

        return view('situs.hubungi-admin', [
            'tempat' => $peran === 'wisatawan' ? Katalog::tempat() : [],
            'peran' => $peran,
        ]);
    }

    public function simpanPesanAdmin(Request $request)
    {
        $pengguna = $request->session()->get('pengguna', []);
        $data = $request->validate([
            'kategori' => ['required', Rule::in(['tempat', 'akun', 'lainnya'])],
            'tempat_slug' => ['nullable', 'required_if:kategori,tempat', 'string', 'max:120'],
            'pesan' => ['required', 'string', 'min:10', 'max:3000'],
        ], [
            'tempat_slug.required_if' => 'Pilih tempat wisata yang ingin ditanyakan.',
            'pesan.min' => 'Pesan minimal 10 karakter.',
        ]);

        PesanLayanan::kirim($pengguna, $data);

        return redirect()->route('hubungi.admin')->with('pesan', 'Pesan berhasil dikirim ke tim yang sesuai.');
    }

    public function cari(Request $request)
    {
        $q = (string) $request->query('q', '');
        $jenis = $request->query('jenis');
        $hasil = Katalog::cari($q);
        if ($request->query('catat', '1') === '1' && $q !== '') {
            Penyimpanan::catatCari($q, count($hasil));
        }
        $perJenis = collect($hasil)->countBy('jenis')->all();
        if ($jenis) {
            $hasil = array_values(array_filter($hasil, fn ($e) => $e['jenis'] === $jenis));
        }

        return view('situs.cari', [
            'q' => $q,
            'jenis' => $jenis,
            'hasil' => $hasil,
            'perJenis' => $perJenis,
            'koreksi' => $hasil ? null : Katalog::koreksi($q),
            'cepat' => array_slice(array_values(Katalog::tempat()), 0, 4),
        ]);
    }

    public function saran(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['saran' => [], 'tanya' => null]);
        }

        // saringan jenis dari dialog pencarian cepat: tempat, sejarah (tokoh, kerajaan, peristiwa, budaya), kuliner
        $jenis = $request->query('jenis');
        $cocok = match ($jenis) {
            'tempat' => fn ($e) => $e['jenis'] === 'tempat',
            'sejarah' => fn ($e) => in_array($e['jenis'], ['tokoh', 'kerajaan', 'peristiwa', 'budaya'], true),
            'kuliner' => fn ($e) => $e['jenis'] === 'kuliner' || ($e['kategori'] ?? null) === 'kuliner',
            default => fn () => true,
        };
        $items = array_slice(array_values(array_filter(Katalog::cari($q), $cocok)), 0, 6);
        $saran = array_map(fn ($e) => [
            'nama' => $e['nama'],
            'jenis' => $e['jenis'],
            'label_jenis' => $e['jenis'] === 'tempat' ? Katalog::KATEGORI[$e['kategori']] : Katalog::KELAS[$e['jenis']],
            'ringkas' => $e['kecamatan'] ?? ($e['ringkas'] ?? ''),
            'harga' => $e['jenis'] === 'tempat' ? Katalog::labelHarga($e) : null,
            'url' => self::urlEntitas($e),
        ], $items);

        return response()->json([
            'saran' => array_values(array_filter($saran, fn ($x) => ! empty($x['url']))),
            'tanya' => route('tanya', ['q' => $q]),
            'semua' => route('cari', ['q' => $q]),
        ]);
    }

    public function tanya(Request $request)
    {
        $tempat = Katalog::tempat();

        return view('situs.tanya', [
            'awal' => (string) $request->query('q', ''),
            'contohTempat' => array_slice(array_values($tempat), 0, 3),
            'contohTiket' => array_values(Katalog::bertiket())[0] ?? null,
            'contohKuliner' => array_values(Katalog::tempat('kuliner'))[0] ?? null,
        ]);
    }

    public function jawab(Request $request, TanyaMasNonovService $asisten, TanyaMasNonovMemori $memori)
    {
        $data = $request->validate(['pesan' => ['required', 'string', 'max:300']]);
        $pengguna = $request->session()->get('pengguna');
        $idPengguna = filter_var($pengguna['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_unless($idPengguna !== false, 403, 'Akun pengguna tidak valid.');

        $idPesan = $memori->simpan((int) $idPengguna, $data['pesan']);
        $riwayatTersimpan = $memori->pertanyaanSebelumnya((int) $idPengguna, $idPesan);
        $pemilikKonteks = (string) $idPengguna;
        $riwayat = $request->session()->get('tanya_mas_nonov', []);
        if (($riwayat['pemilik'] ?? null) !== $pemilikKonteks) {
            $riwayat = ['pemilik' => $pemilikKonteks, 'giliran' => []];
        }

        $konteksPercakapan = array_slice(array_merge($riwayatTersimpan, $riwayat['giliran'] ?? []), -4);
        $jawaban = $asisten->jawab($data['pesan'], $pengguna, $konteksPercakapan);
        $routing = TanyaMasNonovService::klasifikasi($data['pesan']);
        if (in_array($routing['source'], ['sparql', 'general'], true)) {
            $riwayat['giliran'][] = [
                'pertanyaan' => $data['pesan'],
                'jawaban' => mb_substr(implode(' ', $jawaban['teks'] ?? []), 0, 600),
            ];
            $riwayat['giliran'] = array_slice($riwayat['giliran'], -4);
        } else {
            $riwayat['giliran'] = [];
        }
        $request->session()->put('tanya_mas_nonov', $riwayat);

        return response()->json($jawaban);
    }

    public function peta()
    {
        $tempat = array_values(Katalog::tempat());
        $kunjungan = Kunjungan::ringkasan(365)['tempat'];

        // rating per tempat dihitung trigger DB 2 ke tabel ringkasan_ulasan
        $ulasan = Penyimpanan::ringkasanUlasan();
        $jumlahSukaTempat = Penyimpanan::ringkasanSukaTempat();

        foreach ($tempat as &$t) {
            $t['rating'] = $ulasan[$t['slug']]['rating'] ?? 0;
            $t['jumlahUlasan'] = $ulasan[$t['slug']]['jumlahUlasan'] ?? 0;
            $t['jumlahKunjungan'] = $kunjungan[$t['slug']] ?? 0;
            $t['jumlahSuka'] = $jumlahSukaTempat[$t['slug']] ?? 0;
        }
        unset($t);

        return view('situs.peta', compact('tempat'));
    }

    /** Endpoint publik JSON-LD untuk pemakai data eksternal dan pengindeks. */
    public function wisataJson()
    {
        return response()->json(Semantik::jsonld(), 200, [
            'Content-Type' => 'application/ld+json; charset=UTF-8',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    /** Dokumen JSON-LD satu destinasi. */
    public function tempatJson(string $slug)
    {
        $tempat = Katalog::get($slug);
        abort_unless($tempat && $tempat['jenis'] === 'tempat', 404);

        return response()->json(Semantik::jsonldTempat($tempat), 200, [
            'Content-Type' => 'application/ld+json; charset=UTF-8',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }
    public function tempat(string $slug)
    {
        $t = Katalog::get($slug);
        abort_unless($t && $t['jenis'] === 'tempat', 404);

        $pengguna = session('pengguna', []);
        $idPengguna = filter_var($pengguna['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $disukai = ($pengguna['peran'] ?? null) === 'wisatawan' && $idPengguna !== false
            ? Penyimpanan::tempatDisukai((int) $idPengguna)
            : [];
        $jumlahSuka = Penyimpanan::ringkasanSukaTempat()[$slug] ?? 0;

        return view('situs.tempat', ['e' => $t] + [
            'ulasan' => Penyimpanan::ulasan($slug, 'tayang', aman: true),
            'ramai' => 0,
            'dekat' => $this->terdekat($t, 4),
            'jumlahSuka' => $jumlahSuka,
            'sudahDisukai' => in_array($slug, $disukai, true),
        ]);
    }

    public function sukai(Request $request, string $slug)
    {
        $tempat = Katalog::get($slug);

        abort_unless($tempat && ($tempat['jenis'] ?? null) === 'tempat', 404);

        $pengguna = $request->session()->get('pengguna', []);
        $idPengguna = filter_var($pengguna['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_unless(($pengguna['peran'] ?? null) === 'wisatawan' && $idPengguna !== false, 403, 'Masuk sebagai wisatawan untuk menyukai tempat.');

        $disukai = Penyimpanan::ubahSukaTempat((int) $idPengguna, $slug);

        return back()->with('status', $disukai
            ? 'Terima kasih sudah menyukai tempat ini.'
            : 'Suka berhasil dibatalkan.');
    }

    public function entitas(string $jenis, string $slug)
    {
        $e = Katalog::get($slug);
        abort_unless($e && $e['jenis'] === $jenis, 404);

        return view('situs.entitas', ['e' => $e]);
    }

    private function terdekat(array $t, int $n): array
    {
        $lain = array_filter(Katalog::tempat(), fn ($x) => $x['slug'] !== $t['slug']);
        $hasil = array_map(fn ($x) => $x + ['jarak' => Katalog::jarakMeter($t, $x)], $lain);
        usort($hasil, fn ($a, $b) => $a['jarak'] <=> $b['jarak']);

        return array_slice($hasil, 0, $n);
    }

    public static function urlEntitas(array $e): ?string
    {
        return match ($e['jenis']) {
            'tempat' => route('tempat', $e['slug']),
            'wilayah' => null,
            default => route('entitas', [$e['jenis'], $e['slug']]),
        };
    }

    public function rencana(Request $request)
    {
        $hasil = null;
        $pref = null;
        if ($request->has('minat')) {
            $pref = $request->validate([
                'minat' => ['required', 'array', 'min:1'], 'minat.*' => ['in:'.implode(',', array_keys(Katalog::KATEGORI))],
                'durasi' => ['required', 'integer', 'in:2,4,6,8'],
                'moda' => ['required', 'array', 'min:1'], 'moda.*' => ['in:jalan,becak'],
                'mulai' => ['required', 'string'],
                // "lokasi": mulai dari posisi pengguna sekarang (koordinat dari browser)
                'lat' => ['required_if:mulai,lokasi', 'nullable', 'numeric', 'between:-90,90'],
                'lng' => ['required_if:mulai,lokasi', 'nullable', 'numeric', 'between:-180,180'],
            ], [
                'minat.required' => 'Pilih minimal satu minat.',
                'moda.required' => 'Pilih minimal satu cara berpindah.',
                'lat.required_if' => 'Lokasimu belum terbaca. Izinkan akses lokasi di browser, atau pilih tempat awal dari daftar.',
                'lng.required_if' => 'Lokasimu belum terbaca. Izinkan akses lokasi di browser, atau pilih tempat awal dari daftar.',
            ]);
            $posisi = $pref['mulai'] === 'lokasi' ? ['lat' => (float) $pref['lat'], 'lng' => (float) $pref['lng']] : null;
            try {
                $hasil = Katalog::susunRute($pref['minat'], (int) $pref['durasi'], $pref['moda'], $pref['mulai'], posisi: $posisi);
            } catch (\RuntimeException $e) {
                return view('situs.rencana', ['hasil' => null, 'pref' => $pref, 'tempat' => Katalog::tempat()])
                    ->withErrors(['rute' => $e->getMessage()]);
            }
        }

        return view('situs.rencana', ['hasil' => $hasil, 'pref' => $pref, 'tempat' => Katalog::tempat()]);
    }

    public function rencanaBagikan(string $kode)
    {
        $r = Penyimpanan::cariRencana($kode);
        if ($r === null) {
            abort(404);
        }

        try {
            $hasil = Katalog::susunRute($r['minat'], $r['durasi'], $r['moda'], $r['mulai']);
        } catch (\RuntimeException $e) {
            // misalnya tempat awal sudah dihapus dari Database 3
            return view('situs.rencana', ['hasil' => null, 'pref' => $r, 'tempat' => Katalog::tempat()])
                ->withErrors(['rute' => 'Rencana ini tidak bisa disusun lagi: '.$e->getMessage()]);
        }

        return view('situs.rencana', [
            'hasil' => $hasil,
            'pref' => $r, 'tempat' => Katalog::tempat(), 'dibagikan' => $r,
        ]);
    }

    public function ketentuan()
    {
        return view('situs.ketentuan');
    }
}
