<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Cache;
use App\Support\Asisten;
use App\Support\Katalog;
use App\Support\Kunjungan;
use App\Support\Penyimpanan;
use App\Support\PesanLayanan;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SitusController extends Controller
{
    public function beranda()
    {
        $tempat = Katalog::tempat();
        $kategori = [];
        foreach (Katalog::KATEGORI as $k => $label) {
            $kategori[$k] = ['label' => $label, 'tempat' => Katalog::tempat($k)];
        }

        return view('situs.beranda', [
            'kategori' => $kategori,
            'bertiket' => Katalog::bertiket(),
            'petaTempat' => array_values($tempat),
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
            'harga' => $e['jenis'] === 'tempat' ? Katalog::rupiah(Katalog::hargaTermurah($e)) : null,
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

    public function jawab(Request $request)
    {
        $data = $request->validate(['pesan' => ['required', 'string', 'max:300']]);

        return response()->json(Asisten::jawab($data['pesan']));
    }

        public function peta()
    {
        $tempat = array_values(Katalog::tempat());
        $kunjungan = Kunjungan::ringkasan(365)['tempat'];

        // rating per tempat dihitung trigger DB 2 ke tabel ringkasan_ulasan
        $ulasan = Penyimpanan::ringkasanUlasan();

        foreach ($tempat as &$t) {
            $t['rating'] = $ulasan[$t['slug']]['rating'] ?? 0;
            $t['jumlahUlasan'] = $ulasan[$t['slug']]['jumlahUlasan'] ?? 0;
            $t['jumlahKunjungan'] = $kunjungan[$t['slug']] ?? 0;
            $t['jumlahSuka'] = (int) Cache::get('medanvisit.favorit.' . $t['slug'], 0);
        }
        unset($t);

        return view('situs.peta', compact('tempat'));
    }
    public function tempat(string $slug)
{
    $t = Katalog::get($slug);
    abort_unless($t && $t['jenis'] === 'tempat', 404);

    $disukai = session('tempat_disukai', []);

    return view('situs.tempat', ['e' => $t] + [
        'ulasan' => Penyimpanan::ulasan($slug, 'tayang', aman: true),
        'ramai' => 0,
        'dekat' => $this->terdekat($t, 4),
        'jumlahSuka' => (int) Cache::get('medanvisit.favorit.' . $slug, 0),
        'sudahDisukai' => in_array($slug, $disukai, true),
    ]);
}
    public function sukai(Request $request, string $slug)
{
    $tempat = Katalog::get($slug);

    abort_unless($tempat && ($tempat['jenis'] ?? null) === 'tempat', 404);

    $disukai = $request->session()->get('tempat_disukai', []);
    $kunci = 'medanvisit.favorit.' . $slug;

    if (in_array($slug, $disukai, true)) {
        $jumlah = max(0, (int) Cache::get($kunci, 0));

        if ($jumlah > 0) {
            Cache::decrement($kunci);
        }

        $disukai = array_values(array_diff($disukai, [$slug]));

        $request->session()->put('tempat_disukai', $disukai);

        return back()->with('status', 'Suka berhasil dibatalkan.');
    }

    Cache::add($kunci, 0, now()->addYears(10));
    Cache::increment($kunci);

    $disukai[] = $slug;
    $request->session()->put('tempat_disukai', $disukai);

    return back()->with('status', 'Terima kasih sudah menyukai tempat ini.');
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
            ]);
            try {
                $hasil = Katalog::susunRute($pref['minat'], (int) $pref['durasi'], $pref['moda'], $pref['mulai']);
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
        abort_unless($r, 404);

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
