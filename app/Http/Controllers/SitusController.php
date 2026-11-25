<?php

namespace App\Http\Controllers;

use App\Services\TanyaMasNonovMemori;
use App\Services\TanyaMasNonovService;
use App\Support\Katalog;
use App\Support\Kunjungan;
use App\Support\Penyimpanan;
use App\Support\PesanLayanan;
use App\Support\RekomendasiTempat;
use App\Support\Semantik;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SitusController extends Controller
{
    private const ULASAN_PER_HALAMAN = 5;

    public function beranda()
    {
        $tempat = Katalog::tempat();
        $ulasan = Penyimpanan::ringkasanUlasan();
        $ratingTempat = Penyimpanan::ringkasanRatingTempat();
        $jumlahSukaTempat = Penyimpanan::ringkasanSukaTempat();
        $kategori = [];
        foreach (Katalog::KATEGORI as $k => $label) {
            $kategori[$k] = ['label' => $label, 'tempat' => Katalog::tempat($k)];
        }

        foreach ($tempat as &$t) {
            $ringkasan = $ulasan[$t['slug']] ?? ['rating' => 0, 'jumlahUlasan' => 0];
            $rating = $ratingTempat[$t['slug']] ?? ['rating' => 0, 'jumlahRating' => 0];
            $t['rating'] = $rating['rating'];
            $t['jumlahRating'] = $rating['jumlahRating'];
            $t['jumlahUlasan'] = $ringkasan['jumlahUlasan'];
            $t['jumlahSuka'] = $jumlahSukaTempat[$t['slug']] ?? 0;
        }
        unset($t);
        $tempat = RekomendasiTempat::urutkan(array_values($tempat));

        return view('situs.beranda', [
            'kategori' => $kategori,
            'bertiket' => Katalog::bertiket(),
            'petaTempat' => $tempat,
            'rekomendasiUtama' => $tempat[0] ?? null,
            'ringkasanRatingTempat' => $ratingTempat,
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
        $cepat = [];
        if ($q === '') {
            $ratingTempat = Penyimpanan::ringkasanRatingTempat();
            $jumlahSukaTempat = Penyimpanan::ringkasanSukaTempat();
            $cepat = RekomendasiTempat::urutkan(array_map(function (array $tempat) use ($ratingTempat, $jumlahSukaTempat) {
                $tempat['rating'] = $ratingTempat[$tempat['slug']]['rating'] ?? 0;
                $tempat['jumlahSuka'] = $jumlahSukaTempat[$tempat['slug']] ?? 0;

                return $tempat;
            }, array_values(Katalog::tempat())));
        }
        if ($jenis) {
            $hasil = array_values(array_filter($hasil, fn ($e) => $e['jenis'] === $jenis));
        }

        return view('situs.cari', [
            'q' => $q,
            'jenis' => $jenis,
            'hasil' => $hasil,
            'perJenis' => $perJenis,
            'koreksi' => $hasil ? null : Katalog::koreksi($q),
            'cepat' => array_slice($cepat, 0, 4),
        ]);
    }

    public function saran(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['saran' => []]);
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
            'semua' => route('cari', ['q' => $q]),
        ]);
    }

    public function jawab(Request $request, TanyaMasNonovService $asisten, TanyaMasNonovMemori $memori)
    {
        $data = $request->validate([
            'pesan' => ['required', 'string', 'max:300'],
            'lokasi' => ['sometimes', 'array'],
            'lokasi.lat' => ['required_with:lokasi', 'numeric', 'between:-90,90'],
            'lokasi.lng' => ['required_with:lokasi', 'numeric', 'between:-180,180'],
        ]);
        $lokasi = isset($data['lokasi'])
            ? ['lat' => (float) $data['lokasi']['lat'], 'lng' => (float) $data['lokasi']['lng']]
            : null;
        $pengguna = $request->session()->get('pengguna');
        $idPengguna = filter_var($pengguna['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $idPengguna = $idPengguna === false ? null : (int) $idPengguna;
        $pemilikTamu = null;
        if ($idPengguna === null) {
            // Token acak ini hanya tersimpan pada sesi browser. Yang masuk database adalah hash-nya.
            $pemilikTamu = $request->session()->get('tanya_mas_nonov_pemilik_tamu');
            if (! is_string($pemilikTamu) || $pemilikTamu === '') {
                $pemilikTamu = Str::random(64);
                $request->session()->put('tanya_mas_nonov_pemilik_tamu', $pemilikTamu);
            }
        }

        $idPesan = null;
        $riwayatTersimpan = [];
        $peringatanMemori = false;
        try {
            $idPesan = $memori->simpan($idPengguna, $pemilikTamu, $data['pesan']);
            $riwayatTersimpan = $memori->pertanyaanSebelumnya($idPengguna, $pemilikTamu, $idPesan);
        } catch (\PDOException $e) {
            report($e);
            $peringatanMemori = true;
        }
        $pemilikKonteks = $idPengguna === null ? 'tamu:'.$pemilikTamu : 'pengguna:'.$idPengguna;
        $riwayat = $request->session()->get('tanya_mas_nonov', []);
        if (($riwayat['pemilik'] ?? null) !== $pemilikKonteks) {
            $riwayat = ['pemilik' => $pemilikKonteks, 'giliran' => []];
        }

        $konteksPercakapan = array_slice(array_merge($riwayatTersimpan, $riwayat['giliran'] ?? []), -4);
        try {
            $jawaban = $asisten->jawab($data['pesan'], $pengguna, $konteksPercakapan, $lokasi);
        } catch (\Throwable $e) {
            report($e);
            $jawaban = [
                'teks' => ['Maaf, Mas Nonov sedang mengalami kendala saat menyusun jawaban. Silakan coba lagi sebentar.'],
                'tempat' => [],
            ];
        }
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

        $teksJawaban = implode(' ', $jawaban['teks'] ?? []);
        if ($teksJawaban !== '' && $idPesan !== null) {
            try {
                $memori->simpanJawaban($idPesan, $teksJawaban);
            } catch (\PDOException $e) {
                report($e);
                $peringatanMemori = true;
            }
        }

        if ($peringatanMemori) {
            $jawaban['peringatan'] = 'Jawaban berhasil dimuat, tetapi riwayat percakapan tidak dapat disimpan saat ini.';
        }

        return response()->json($jawaban);
    }

    public function peta()
    {
        $tempat = array_values(Katalog::tempat());
        $kunjungan = Kunjungan::ringkasan(365)['tempat'];
        $checkinTerbaru = Kunjungan::ringkasanCheckinTerbaru();

        // Rating dibaca dari ringkasan tabel rating DB 2; ulasan tetap dihitung terpisah.
        $ulasan = Penyimpanan::ringkasanUlasan();
        $ratingTempat = Penyimpanan::ringkasanRatingTempat();
        $jumlahSukaTempat = Penyimpanan::ringkasanSukaTempat();

        foreach ($tempat as &$t) {
            $rating = $ratingTempat[$t['slug']] ?? ['rating' => 0, 'jumlahRating' => 0];
            $t['rating'] = $rating['rating'];
            $t['jumlahRating'] = $rating['jumlahRating'];
            $t['jumlahUlasan'] = $ulasan[$t['slug']]['jumlahUlasan'] ?? 0;
            $t['jumlahKunjungan'] = $checkinTerbaru[$t['slug']] ?? ($kunjungan[$t['slug']] ?? 0);
            $t['jumlahSuka'] = $jumlahSukaTempat[$t['slug']] ?? 0;
        }
        unset($t);
        $tempat = RekomendasiTempat::urutkan(array_values($tempat));

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
        $disukai = $idPengguna !== false
            ? Penyimpanan::tempatDisukai((int) $idPengguna)
            : [];
        $jumlahSuka = Penyimpanan::ringkasanSukaTempat()[$slug] ?? 0;
        $rating = Penyimpanan::ringkasanRatingTempat()[$slug] ?? ['rating' => 0, 'jumlahRating' => 0];
        $ramai = Kunjungan::ringkasanCheckinTerbaru()[$slug] ?? 0;
        $hasilUlasan = Penyimpanan::ulasanHalaman([$slug], 'tayang', self::ULASAN_PER_HALAMAN, 0, aman: true);
        $ulasanSaya = $idPengguna !== false && ($pengguna['peran'] ?? null) === 'wisatawan'
            ? (Penyimpanan::ulasan($slug, 'tayang', (int) $idPengguna, aman: true)[0] ?? null)
            : null;

        return view('situs.tempat', ['e' => $t] + [
            'ulasan' => $hasilUlasan['ulasan'],
            'jumlahUlasan' => $hasilUlasan['jumlah'],
            'ulasanSaya' => $ulasanSaya,
            'ratingTempat' => ['rata' => $rating['rating'], 'jumlah' => $rating['jumlahRating']],
            'ramai' => $ramai,
            'dekat' => $this->terdekat($t, 4),
            'jumlahSuka' => $jumlahSuka,
            'sudahDisukai' => in_array($slug, $disukai, true),
        ]);
    }

    public function ulasanTempat(Request $request, string $slug)
    {
        $tempat = Katalog::get($slug);
        abort_unless($tempat && $tempat['jenis'] === 'tempat', 404);

        $data = $request->validate([
            'offset' => ['required', 'integer', 'min:0', 'max:1000000'],
        ]);
        $hasil = Penyimpanan::ulasanHalaman(
            [$slug],
            'tayang',
            self::ULASAN_PER_HALAMAN,
            (int) $data['offset'],
        );
        $jumlahDimuat = count($hasil['ulasan']);
        $offsetBerikutnya = (int) $data['offset'] + $jumlahDimuat;

        return response()->json([
            'html' => view('situs.partials.ulasan', ['ulasan' => $hasil['ulasan']])->render(),
            'hasMore' => $jumlahDimuat > 0 && $offsetBerikutnya < $hasil['jumlah'],
            'nextOffset' => $offsetBerikutnya,
        ]);
    }

    public function sukai(Request $request, string $slug)
    {
        $tempat = Katalog::get($slug);

        abort_unless($tempat && ($tempat['jenis'] ?? null) === 'tempat', 404);

        $pengguna = $request->session()->get('pengguna', []);
        $idPengguna = filter_var($pengguna['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_unless(
            in_array($pengguna['peran'] ?? null, ['wisatawan', 'admin', 'superadmin'], true) && $idPengguna !== false,
            403,
            'Masuk ke akun MedanVisit untuk menyukai tempat.',
        );

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
        $rencanaEdit = null;
        $mode = in_array($request->query('mode'), ['otomatis', 'manual'], true)
            ? $request->query('mode')
            : null;

        if ($request->filled('edit')) {
            $pengguna = $request->session()->get('pengguna', []);
            abort_unless(($pengguna['peran'] ?? null) === 'wisatawan', 404);
            $rencanaEdit = Penyimpanan::cariRencanaPengguna(
                (int) $pengguna['id'],
                (string) $request->query('edit'),
            );
            abort_unless($rencanaEdit !== null, 404);
        }

        if ($request->hasAny(['minat', 'durasi', 'moda', 'mulai', 'tujuan', 'tujuan_dikirim'])) {
            $mode = in_array($request->input('mode'), ['otomatis', 'manual'], true)
                ? $request->input('mode')
                : ($request->filled('tujuan') ? 'manual' : 'otomatis');
            $request->merge(['mode' => $mode]);

            $aturan = [
                'mode' => ['required', Rule::in(['otomatis', 'manual'])],
                'mulai' => ['required', 'string'],
                'tujuan_dikirim' => ['sometimes', 'in:1'],
                // "lokasi": mulai dari posisi pengguna sekarang (koordinat dari browser)
                'lat' => ['required_if:mulai,lokasi', 'nullable', 'numeric', 'between:-90,90'],
                'lng' => ['required_if:mulai,lokasi', 'nullable', 'numeric', 'between:-180,180'],
            ];
            if ($mode === 'otomatis') {
                $aturan += [
                    'minat' => ['required', 'array', 'min:1'],
                    'minat.*' => ['in:'.implode(',', array_keys(Katalog::KATEGORI))],
                    'durasi' => ['required', 'integer', 'in:2,4,6,8'],
                    'moda' => ['required', 'array', 'min:1'],
                    'moda.*' => ['in:jalan,motor,mobil'],
                ];
            } else {
                $aturan += [
                    'tujuan' => ['required', 'array', 'min:1', 'max:10'],
                    'tujuan.*' => ['required', 'string', 'distinct', Rule::in(array_keys(Katalog::tempat()))],
                ];
            }

            $pref = $request->validate($aturan, [
                'minat.required' => 'Pilih minimal satu minat.',
                'durasi.required' => 'Pilih lama waktu perjalanan.',
                'moda.required' => 'Pilih minimal satu moda transportasi.',
                'tujuan.required' => 'Pilih setidaknya satu tempat tujuan.',
                'tujuan.min' => 'Pilih setidaknya satu tempat tujuan.',
                'lat.required_if' => 'Lokasimu belum terbaca. Izinkan akses lokasi di browser, atau pilih tempat awal dari daftar.',
                'lng.required_if' => 'Lokasimu belum terbaca. Izinkan akses lokasi di browser, atau pilih tempat awal dari daftar.',
            ]);
            if ($mode === 'manual') {
                $pref = array_replace($pref, [
                    'minat' => array_keys(Katalog::KATEGORI),
                    'durasi' => 8,
                    'moda' => ['jalan', 'motor', 'mobil'],
                ]);
            }
            if ($request->has('tujuan_dikirim') && ! array_key_exists('tujuan', $pref)) {
                $pref['tujuan'] = [];
            }
            $pref = array_replace($rencanaEdit ?? [], $pref);
        } elseif ($rencanaEdit !== null) {
            $pref = $rencanaEdit;
        }

        if ($pref !== null) {
            $mode = $pref['mode'] ?? (! empty($pref['tujuan']) ? 'manual' : 'otomatis');
            $posisi = $pref['mulai'] === 'lokasi' ? ['lat' => (float) $pref['lat'], 'lng' => (float) $pref['lng']] : null;
            try {
                $hasil = Katalog::susunRute(
                    $pref['minat'],
                    (int) $pref['durasi'],
                    $pref['moda'],
                    $pref['mulai'],
                    posisi: $posisi,
                    tujuan: $pref['tujuan'] ?? [],
                );
            } catch (\RuntimeException $e) {
                return view('situs.rencana', [
                    'hasil' => null,
                    'pref' => $pref,
                    'mode' => $mode,
                    'tempat' => Katalog::tempat(),
                    'rencanaEdit' => $rencanaEdit,
                ])
                    ->withErrors(['rute' => $e->getMessage()]);
            }
        }

        return view('situs.rencana', [
            'hasil' => $hasil,
            'pref' => $pref,
            'mode' => $mode,
            'tempat' => Katalog::tempat(),
            'rencanaEdit' => $rencanaEdit,
        ]);
    }

    public function rencanaBagikan(string $kode)
    {
        $r = Penyimpanan::cariRencana($kode);
        if ($r === null) {
            abort(404);
        }
        $mode = ! empty($r['tujuan']) ? 'manual' : 'otomatis';

        try {
            $hasil = Katalog::susunRute(
                $r['minat'],
                $r['durasi'],
                $r['moda'],
                $r['mulai'],
                tujuan: $r['tujuan'] ?? [],
            );
        } catch (\RuntimeException $e) {
            // misalnya tempat awal sudah dihapus dari Database 3
            return view('situs.rencana', ['hasil' => null, 'pref' => $r, 'mode' => $mode, 'tempat' => Katalog::tempat()])
                ->withErrors(['rute' => 'Rencana ini tidak bisa disusun lagi: '.$e->getMessage()]);
        }

        return view('situs.rencana', [
            'hasil' => $hasil,
            'pref' => $r, 'mode' => $mode, 'tempat' => Katalog::tempat(), 'dibagikan' => $r,
        ]);
    }

    public function ketentuan()
    {
        return view('situs.ketentuan');
    }
}
