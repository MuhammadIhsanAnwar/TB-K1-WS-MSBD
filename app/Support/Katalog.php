<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Facade katalog. Seluruh entitas dan relasi dibaca dari Database 3 SPARQL.
 */
class Katalog
{
    public const KELAS = [
        'tempat' => 'Tempat',
        'tokoh' => 'Tokoh',
        'kerajaan' => 'Kerajaan',
        'peristiwa' => 'Peristiwa',
        'budaya' => 'Budaya',
        'kuliner' => 'Kuliner',
        'transportasi' => 'Transportasi',
        'wilayah' => 'Wilayah',
    ];

    public const KATEGORI = [
        'sejarah' => 'Sejarah & cagar budaya',
        'museum' => 'Museum',
        'religi' => 'Rumah ibadah',
        'kuliner' => 'Kuliner',
        'ruang-publik' => 'Taman & ruang publik',
        'belanja' => 'Belanja',
        'rekreasi-air' => 'Rekreasi air',
    ];

    public const KALIMAT = [
        'dibangunOleh' => ['{a} dibangun oleh {b}.', '{a} membangun {b}.'],
        'memimpin' => ['{a} memimpin {b}.', '{a} dipimpin oleh {b}.'],
        'terlibatDalam' => ['{a} hidup dan berperan pada masa {b}.', 'Pada masa {a}, {b} ikut berperan.'],
        'merepresentasikan' => ['{a} mencerminkan budaya {b}.', 'Budaya {a} tercermin di {b}.'],
        'khasDari' => ['{a} adalah kuliner khas {b}.', 'Salah satu kuliner khas {a} adalah {b}.'],
        'dekatDengan' => ['{a} hanya {jarak} meter dari {b}.', '{a} hanya {jarak} meter dari {b}.'],
        'bergaya' => ['{a} memakai gaya {b}.', 'Gaya {a} juga terlihat di {b}.'],
        'bagianDari' => ['{a} berada di dalam kompleks {b}.', 'Di dalam kompleks {a} ada {b}.'],
        'berkaitanDengan' => ['{a} berkaitan dengan {b}.', '{a} berkaitan dengan {b}.'],
        'memilikiSejarah' => ['Sejarah {a} dimulai dari {b}.', '{a} melahirkan {b}.'],
        'menjual' => ['{a} menjual {b}.', '{a} bisa dicicipi di {b}.'],
        'dilayaniOleh' => ['{a} bisa dicapai dengan {b}.', '{a} biasa mengantar pengunjung ke {b}.'],
        'beradaDi' => ['{a} berada di Kecamatan {b}.', 'Kecamatan {a} menjadi tempat {b}.'],
    ];

    public const LABEL_RELASI = [
        'dibangunOleh' => 'dibangun oleh',
        'memimpin' => 'memimpin',
        'terlibatDalam' => 'terlibat dalam',
        'merepresentasikan' => 'mencerminkan',
        'khasDari' => 'khas dari',
        'dekatDengan' => 'dekat dengan',
        'bergaya' => 'bergaya',
        'bagianDari' => 'bagian dari',
        'berkaitanDengan' => 'berkaitan dengan',
        'memilikiSejarah' => 'punya sejarah',
        'menjual' => 'menjual',
        'dilayaniOleh' => 'dilayani oleh',
        'beradaDi' => 'berada di',
    ];

    public const LABEL_BALIK = [
        'dibangunOleh' => 'membangun',
        'memimpin' => 'dipimpin oleh',
        'terlibatDalam' => 'melibatkan',
        'merepresentasikan' => 'tercermin di',
        'khasDari' => 'punya kuliner khas',
        'dekatDengan' => 'dekat dengan',
        'bergaya' => 'terlihat di',
        'bagianDari' => 'mencakup',
        'berkaitanDengan' => 'berkaitan dengan',
        'memilikiSejarah' => 'melahirkan',
        'menjual' => 'dijual di',
        'dilayaniOleh' => 'mengantar ke',
        'beradaDi' => 'menjadi tempat',
    ];

    private static ?array $entities = null;
    private static ?array $relations = null;
    public static function forgetCache(): void
    {
        self::$entities = self::$relations = null;
        Database3Catalog::forgetCache();
        StatusOperasional::lupakanCache();
    }

    public static function entitas(): array
    {
        return self::$entities ??= Database3Catalog::entities();
    }

    public static function relasi(): array
    {
        if (self::$relations !== null) {
            return self::$relations;
        }

        $relations = Database3Catalog::relations();
        foreach ($relations as &$relation) {
            if ($relation[1] === 'dekatDengan') {
                $a = self::get($relation[0]);
                $b = self::get($relation[2]);
                if (! $a || ! $b) {
                    throw new RuntimeException('Relasi SPARQL merujuk entitas yang tidak ditemukan.');
                }
                $relation['jarak_m'] = self::jarakMeter($a, $b);
            }
        }

        return self::$relations = $relations;
    }

    public static function get(string $slug): ?array
    {
        return self::entitas()[$slug] ?? null;
    }

    public static function jenis(string $jenis): array
    {
        return array_filter(self::entitas(), fn ($entity) => $entity['jenis'] === $jenis);
    }

    public static function tempat(?string $kategori = null): array
    {
        return array_filter(self::jenis('tempat'), fn ($place) => $kategori === null || $place['kategori'] === $kategori);
    }

    public static function bertiket(): array
    {
        return array_filter(self::tempat(), fn ($place) => ! empty($place['tiket']));
    }

    public static function hargaTermurah(array $tempat): ?int
    {
        return empty($tempat['tiket']) ? null : (int) min($tempat['tiket']);
    }

    public static function labelHarga(array $tempat): string
    {
        $harga = self::hargaTermurah($tempat);
        if ($harga !== null) {
            return self::rupiah($harga);
        }

        return ($tempat['akses_gratis'] ?? null) === true ? 'Gratis' : 'Harga belum tersedia';
    }

    public static function tetangga(string $slug, bool $denganWilayah = true): array
    {
        $hasil = [];
        foreach (self::relasi() as $i => $relation) {
            [$subjek, $predikat, $objek] = $relation;
            if ($subjek !== $slug && $objek !== $slug) {
                continue;
            }
            $lawan = $subjek === $slug ? $objek : $subjek;
            $entitasLawan = self::get($lawan);
            if (! $entitasLawan || (! $denganWilayah && $entitasLawan['jenis'] === 'wilayah')) {
                continue;
            }
            $hasil[] = [
                'id' => $i,
                'predikat' => $predikat,
                'maju' => $subjek === $slug,
                'lawan' => $lawan,
                'relasi' => $relation,
            ];
        }

        return $hasil;
    }

    public static function labelRelasi(array $relasi, string $sudut): string
    {
        $labels = $relasi[0] === $sudut ? self::LABEL_RELASI : self::LABEL_BALIK;
        if (! isset($labels[$relasi[1]])) {
            throw new RuntimeException('Label relasi SPARQL tidak dikenali: '.$relasi[1]);
        }

        return $labels[$relasi[1]].(isset($relasi['jarak_m']) ? ' '.$relasi['jarak_m'].' m' : '');
    }

    public static function kalimat(array $relasi, string $sudut): string
    {
        [$subjek, $predikat, $objek] = $relasi;
        if (isset($relasi['kalimat']) && $sudut === $subjek) {
            return $relasi['kalimat'];
        }
        if (! isset(self::KALIMAT[$predikat])) {
            throw new RuntimeException('Kalimat relasi SPARQL tidak dikenali: '.$predikat);
        }

        $maju = $subjek === $sudut;
        $a = self::get($maju ? $subjek : $objek)['nama'];
        $b = self::get($maju ? $objek : $subjek)['nama'];
        $pola = self::KALIMAT[$predikat][$maju ? 0 : 1];

        return strtr($pola, [
            '{a}' => $a,
            '{b}' => $b,
            '{jarak}' => number_format($relasi['jarak_m'] ?? 0, 0, ',', '.'),
        ]);
    }

    public static function jarakMeter(array $a, array $b): int
    {
        $radiusBumi = 6371000;
        $dLat = deg2rad($b['lat'] - $a['lat']);
        $dLng = deg2rad($b['lng'] - $a['lng']);
        $h = sin($dLat / 2) ** 2 + cos(deg2rad($a['lat'])) * cos(deg2rad($b['lat'])) * sin($dLng / 2) ** 2;

        return (int) round(2 * $radiusBumi * asin(sqrt($h)));
    }

    /** Rute jalan sungguhan (OSRM/Valhalla), disimpan 30 hari karena letak tempat jarang berubah. */
    private static function ambilRuteApi(array $a, array $b, string $moda): ?array
    {
        $kunci = 'medanvisit.rute.'.$moda.'.'.md5($a['lat'].','.$a['lng'].';'.$b['lat'].','.$b['lng']);
        if (is_array($simpan = Cache::get($kunci))) {
            return $simpan;
        }
        $hasil = self::mintaRuteApi($a, $b, $moda);
        if ($hasil !== null) {
            Cache::put($kunci, $hasil, now()->addDays(30));
        }

        return $hasil;
    }

    private static function mintaRuteApi(array $a, array $b, string $moda): ?array
{
    try {
        // batas pendek: kalau layanan rute lambat, perkiraan garis lurus dipakai
        $timeout = min((int) config('services.routing.timeout', 15), 6);

        if ($moda === 'jalan') {
            $url = rtrim(config('services.routing.valhalla_url'), '/').'/route';

            $response = Http::connectTimeout(3)->timeout($timeout)
                ->acceptJson()
                ->post($url, [
                    'locations' => [
                        [
                            'lat' => (float) $a['lat'],
                            'lon' => (float) $a['lng'],
                        ],
                        [
                            'lat' => (float) $b['lat'],
                            'lon' => (float) $b['lng'],
                        ],
                    ],
                    'costing' => 'pedestrian',
                    'units' => 'kilometers',
                    'format' => 'osrm',
                ]);
        } else {
            $url = rtrim(config('services.routing.osrm_url'), '/')
                .'/route/v1/driving/'
                .$a['lng'].','.$a['lat'].';'
                .$b['lng'].','.$b['lat'];

            $response = Http::connectTimeout(3)->timeout($timeout)
                ->acceptJson()
                ->get($url, [
                    'overview' => 'false',
                    'steps' => 'false',
                ]);
        }

        if (! $response->successful()) {
            return null;
        }

        $data = $response->json();
        $rute = $data['routes'][0] ?? null;

        if (! $rute || ! isset($rute['distance'], $rute['duration'])) {
            return null;
        }

        return [
            'meter' => (int) round($rute['distance']),
            'menit' => max(1, (int) round($rute['duration'] / 60)),
        ];
    } catch (\Throwable $e) {
        report($e);

        return null;
    }
}

    public static function perpindahan(array $a, array $b, array $moda): array
    {
        $meter = self::jarakMeter($a, $b);
        $jalan = in_array('jalan', $moda, true);
        $motor = in_array('motor', $moda, true);
        $mobil = in_array('mobil', $moda, true);

        $kodeModa = match (true) {
            $jalan && ($meter <= 700 || (! $motor && ! $mobil)) => 'jalan',
            $motor && $mobil => $meter >= 4000 ? 'mobil' : 'motor',
            $motor => 'motor',
            $mobil => 'mobil',
            default => 'jalan',
        };

        return [
            'kode_moda' => $kodeModa,
            'moda' => match ($kodeModa) {
                'motor' => 'Motor',
                'mobil' => 'Mobil',
                default => 'Jalan kaki',
            },
            'meter' => $meter,
            'menit' => match ($kodeModa) {
                'motor' => max(1, (int) round($meter / 300)),
                'mobil' => max(1, (int) round($meter / 400)),
                default => max(1, (int) round($meter / 75)),
            },
            'biaya' => 0,
        ];
    }

    private static function perpindahanDenganRute(
    array $a,
    array $b,
    array $moda
): array {
    $hasil = self::perpindahan($a, $b, $moda);
    $rute = self::ambilRuteApi($a, $b, $hasil['kode_moda']);

    if ($rute === null) {
        return $hasil;
    }

    return array_merge($hasil, [
        'meter' => $rute['meter'],
        'menit' => $rute['menit'],
    ]);
}

    /** Jarak maksimum dari posisi pengguna ke persinggahan pertama (rumah bisa agak jauh dari pusat kota). */
    private const JARAK_LANGKAH_PERTAMA = 25000;

    /**
     * Susun rute satu hari dari tempat awal: setiap langkah memilih tempat terdekat yang cocok dengan minat,
     * masih muat dalam sisa waktu, dan buka saat didatangi (jam buka dari Database 3).
     *
     * Dengan $posisi (lat, lng pengguna), rute dimulai dari titik itu: persinggahan pertama adalah tempat
     * terdekat yang cocok, dan hasilnya membawa 'awal' untuk penanda di peta.
     *
     * Calon diurutkan dengan jarak garis lurus, jadi layanan rute online hanya dipanggil sekali per langkah
     * untuk tempat yang terpilih. Kalau layanan itu gagal, perkiraan garis lurus dipakai.
     */
    public static function susunRute(
        array $minat,
        int $durasiJam,
        array $moda,
        string $mulai,
        ?\DateTimeInterface $tanggal = null,
        ?array $posisi = null,
        array $tujuan = [],
    ): array
    {
        if ($durasiJam <= 0) {
            throw new RuntimeException('Durasi perjalanan harus lebih dari 0 jam.');
        }

        $tujuan = array_values(array_unique($tujuan));
        if (count($tujuan) > 10) {
            throw new RuntimeException('Pilih maksimal 10 tempat tujuan dalam satu rencana.');
        }

        $semuaTempat = self::tempat();
        foreach ($tujuan as $slug) {
            if (! isset($semuaTempat[$slug])) {
                throw new RuntimeException('Salah satu tempat tujuan sudah tidak tersedia.');
            }
        }

        $manual = $tujuan !== [];
        $pool = $manual
            ? array_intersect_key($semuaTempat, array_flip($tujuan))
            : array_filter($semuaTempat, fn ($t) => in_array($t['kategori'], $minat, true));
        $hari = (int) \Illuminate\Support\Carbon::instance($tanggal ?? now())->setTimezone('Asia/Jakarta')->isoFormat('E');

        // mulai pukul 08.30, atau saat tempat awal buka kalau lebih siang
        $jam = 8 * 60 + 30;
        $sisaMenit = $durasiJam * 60;
        $urut = [];
        $total = 0;
        $biayaTiketLengkap = true;
        $titikAwal = null;

        if ($posisi !== null) {
            // mulai dari posisi pengguna: dibulatkan sekitar 11 m, cukup untuk rute dan tidak menyimpan lokasi persisnya
            $titikAwal = ['lat' => round((float) $posisi['lat'], 4), 'lng' => round((float) $posisi['lng'], 4)];
            if (! $pool) {
                throw new RuntimeException('Belum ada tempat wisata yang cocok dengan minat pilihanmu.');
            }
            $terdekat = min(array_map(fn ($t) => self::jarakMeter($titikAwal, $t), $pool));
            if ($terdekat > self::JARAK_LANGKAH_PERTAMA) {
                throw new RuntimeException('Lokasimu sekitar '.number_format($terdekat / 1000, 0, ',', '.').' km dari tempat wisata terdekat yang cocok. Pilih tempat awal dari daftar.');
            }
            $kini = $titikAwal;
        } else {
            $awal = self::get($mulai);
            if (! $awal || $awal['jenis'] !== 'tempat') {
                throw new RuntimeException('Pilih tempat awal dari daftar tempat wisata.');
            }
            $kini = $awal;

            if (! $manual || isset($pool[$mulai])) {
                $pool = array_filter($pool, fn ($t) => $t['slug'] !== $mulai);

                $jadwalAwal = self::jadwal($awal);
                if ($jadwalAwal && in_array($hari, $jadwalAwal['hari'], true) && $jadwalAwal['menit'][0] < $jadwalAwal['menit'][1]) {
                    $jam = max($jam, $jadwalAwal['menit'][0]);
                }

                $durasiAwal = (int) ($awal['singgah'] ?? 45);
                if ($durasiAwal > $sisaMenit) {
                    throw new RuntimeException('Singgah di '.$awal['nama'].' butuh sekitar '.$durasiAwal.' menit, lebih lama dari waktu yang dipilih. Pilih waktu yang lebih panjang.');
                }

                $urut[] = ['tempat' => $awal, 'mulai' => $jam, 'pindah' => null];
                $sisaMenit -= $durasiAwal;
                $jam += $durasiAwal;
                $hargaAwal = self::hargaTermurah($awal);
                $biayaTiketLengkap = $hargaAwal !== null || ($awal['akses_gratis'] ?? null) === true;
                $total = $hargaAwal ?? 0;
            }
        }

        while ($pool) {
            // Rute otomatis dibatasi ke tempat terdekat; tujuan pilihan tetap bisa menempuh jarak lebih jauh.
            $batasMeter = $urut === [] && $posisi !== null
                ? self::JARAK_LANGKAH_PERTAMA
                : ($manual ? PHP_INT_MAX : 6000);
            $jarak = array_map(fn ($t) => self::jarakMeter($kini, $t), $pool);
            asort($jarak);
            $slugCalon = null;
            foreach ($jarak as $slug => $meter) {
                if ($meter > $batasMeter) {
                    break;
                }
                $kira = self::perpindahan($kini, $pool[$slug], $moda);
                $singgah = (int) ($pool[$slug]['singgah'] ?? 45);
                if ($kira['menit'] + $singgah <= $sisaMenit && self::bukaSelama($pool[$slug], $hari, $jam + $kira['menit'], $singgah)) {
                    $slugCalon = $slug;
                    break;
                }
            }
            if ($slugCalon === null) {
                break;
            }

            $calon = $pool[$slugCalon];
            unset($pool[$slugCalon]);
            $perpindahan = self::perpindahanDenganRute($kini, $calon, $moda);
            $durasiSinggah = (int) ($calon['singgah'] ?? 45);
            // rute jalan sungguhan bisa lebih lama dari perkiraan garis lurus: periksa ulang
            if ($perpindahan['menit'] + $durasiSinggah > $sisaMenit || ! self::bukaSelama($calon, $hari, $jam + $perpindahan['menit'], $durasiSinggah)) {
                continue;
            }

            $sisaMenit -= $perpindahan['menit'] + $durasiSinggah;
            $jam += $perpindahan['menit'];
            $urut[] = ['tempat' => $calon, 'mulai' => $jam, 'pindah' => $perpindahan];
            $jam += $durasiSinggah;
            $hargaCalon = self::hargaTermurah($calon);
            if ($hargaCalon === null && ($calon['akses_gratis'] ?? null) !== true) {
                $biayaTiketLengkap = false;
            }
            $total += $perpindahan['biaya'] + ($hargaCalon ?? 0);
            $kini = $calon;
        }

        if ($urut === []) {
            throw new RuntimeException($manual
                ? 'Tempat tujuan pilihanmu tidak muat dalam durasi tersebut. Pilih durasi lebih panjang atau kurangi tujuan.'
                : 'Dari lokasimu belum ada tempat yang cocok, buka, dan muat dalam waktu yang dipilih. Tambah minat atau perpanjang waktu.');
        }

        if ($manual) {
            $terkunjungi = array_column(array_column($urut, 'tempat'), 'slug');
            if (array_diff($tujuan, $terkunjungi) !== []) {
                throw new RuntimeException('Semua tempat tujuan pilihan harus muat dalam durasi. Pilih durasi lebih panjang atau kurangi tujuan.');
            }
        }

        return [
            'singgah' => $urut,
            'biaya' => $biayaTiketLengkap ? $total : null,
            'selesai' => $jam,
            'awal' => $titikAwal,
        ];
    }

    /** Tempat buka saat tiba dan belum tutup sampai kunjungan selesai. Jam buka yang tidak diketahui tidak menghalangi. */
    private static function bukaSelama(array $tempat, int $hari, int $tiba, int $durasi): bool
    {
        $jadwal = self::jadwal($tempat);
        if (! $jadwal) {
            return true;
        }
        foreach (self::rentangUntukHari($jadwal, $hari) as [$buka, $tutup]) {
            if ($tutup < $buka) {
                $tutup += 1440;
            }
            if ($tiba >= $buka && $tiba + $durasi <= $tutup) {
                return true;
            }
        }

        $kemarin = $hari === 1 ? 7 : $hari - 1;
        foreach (self::rentangUntukHari($jadwal, $kemarin) as [$buka, $tutup]) {
            if ($tutup < $buka && $tiba + $durasi <= $tutup) {
                return true;
            }
        }

        return false;
    }

    public static function jamTeks(int $menitDariTengahMalam): string
    {
        return sprintf('%02d.%02d', intdiv($menitDariTengahMalam, 60) % 24, $menitDariTengahMalam % 60);
    }

    public static function rupiah(?int $nilai): string
    {
        return $nilai === null ? 'Gratis' : 'Rp'.number_format($nilai, 0, ',', '.');
    }

    private const HARI = ['senin' => 1, 'selasa' => 2, 'rabu' => 3, 'kamis' => 4, 'jumat' => 5, 'sabtu' => 6, 'minggu' => 7];

    /**
     * Jadwal buka sebuah tempat: jam buka dan tutup dalam menit, dan hari buka (1 = Senin ... 7 = Minggu).
     * Hari dibaca dari keterangan jam, misalnya "Selasaâ€“Minggu, 09.00â€“16.00 (Senin tutup)". Nama hari hanya
     * dihitung kalau diikuti rentang jam, jadi "Di luar jam misa (Minggu 06.30, ...)" tidak membatasi hari.
     * Bila jam terstruktur tidak tersedia, rentang sederhana dibaca dari keterangan jam.
     * Tanpa rentang yang bisa dipastikan hasilnya null.
     */
    public static function jadwal(array $tempat): ?array
    {
        $menit = $tempat['jam_menit'] ?? null;
        $teks = mb_strtolower($tempat['jam'] ?? '');
        $nama = implode('|', array_keys(self::HARI));

        if (! $menit) {
            return self::jadwalDariTeks($teks, $nama);
        }

        $hari = [];
        if (! str_contains($teks, 'setiap hari')) {
            preg_match_all("/\b($nama)(?:\s*[\p{Pd}-]\s*($nama))?,?\s+\d{1,2}[.:]\d{2}\s*[\p{Pd}-]/u", $teks, $cocok, PREG_SET_ORDER);
            foreach ($cocok as $c) {
                foreach (self::hariDalamRentang($c[1], $c[2] ?? '') as $h) {
                    $hari[$h] = true;
                }
            }
        }
        $hari = $hari ? array_keys($hari) : range(1, 7);
        preg_match_all("/\b($nama)\s+tutup\b/u", $teks, $tutup);
        $hari = array_values(array_diff($hari, array_map(fn ($h) => self::HARI[$h], $tutup[1])));
        sort($hari);
        $rentangHari = array_fill_keys($hari, [$menit]);

        return ['menit' => $menit, 'hari' => $hari, 'rentang_hari' => $rentangHari];
    }

    private static function jadwalDariTeks(string $teks, string $namaHari): ?array
    {
        $rentangHari = [];
        $rentang24Jam = [0, 0];

        if (preg_match('/\b24\s+jam\b/u', $teks)) {
            $hari = range(1, 7);
            if (! str_contains($teks, 'setiap hari')
                && preg_match("/\b($namaHari)(?:\s*[\p{Pd}-]\s*($namaHari))?/u", $teks, $cocok)) {
                $hari = self::hariDalamRentang($cocok[1], $cocok[2] ?? '');
            }
            preg_match_all("/\b($namaHari)\s+tutup\b/u", $teks, $tutup);
            $hari = array_values(array_diff($hari, array_map(fn ($h) => self::HARI[$h], $tutup[1])));
            foreach ($hari as $h) {
                $rentangHari[$h] = [$rentang24Jam];
            }
        } else {
            $pola = "/(?:(?<awal>$namaHari)(?:\\s*[\\p{Pd}-]\\s*(?<akhir>$namaHari))?(?:\\s+dan\\s+hari\\s+libur)?[,;:]?\\s*)?(?:pukul\\s*)?(?<jam_buka>\\d{1,2})[.:](?<menit_buka>\\d{2})\\s*[\\p{Pd}-]\\s*(?<jam_tutup>\\d{1,2})[.:](?<menit_tutup>\\d{2})/u";
            preg_match_all($pola, $teks, $cocok, PREG_SET_ORDER);
            foreach ($cocok as $rentang) {
                $jamBuka = (int) $rentang['jam_buka'];
                $menitBuka = (int) $rentang['menit_buka'];
                $jamTutup = (int) $rentang['jam_tutup'];
                $menitTutup = (int) $rentang['menit_tutup'];
                if ($jamBuka > 23 || $menitBuka > 59 || $jamTutup > 23 || $menitTutup > 59) {
                    continue;
                }
                $menit = [$jamBuka * 60 + $menitBuka, $jamTutup * 60 + $menitTutup];
                $hari = ($rentang['awal'] ?? '') === ''
                    ? range(1, 7)
                    : self::hariDalamRentang($rentang['awal'], $rentang['akhir'] ?? '');
                foreach ($hari as $h) {
                    $rentangHari[$h] ??= [];
                    if (! in_array($menit, $rentangHari[$h], true)) {
                        $rentangHari[$h][] = $menit;
                    }
                }
            }
        }

        if ($rentangHari === []) {
            return null;
        }

        ksort($rentangHari);
        $hari = array_keys($rentangHari);

        return [
            'menit' => $rentangHari[$hari[0]][0],
            'hari' => $hari,
            'rentang_hari' => $rentangHari,
        ];
    }

    private static function hariDalamRentang(string $awal, string $akhir = ''): array
    {
        $mulai = self::HARI[$awal];
        $selesai = $akhir !== '' ? self::HARI[$akhir] : $mulai;
        $hari = [];
        for ($h = $mulai, $n = 0; $n < 7; $h = $h % 7 + 1, $n++) {
            $hari[] = $h;
            if ($h === $selesai) {
                break;
            }
        }

        return $hari;
    }

    private static function rentangUntukHari(array $jadwal, int $hari): array
    {
        return $jadwal['rentang_hari'][$hari] ?? [];
    }

    /** Status tempat saat ini; null jika jadwal tidak diketahui dan pengelola belum mengaturnya. */
    public static function statusOperasional(array $tempat, ?\DateTimeInterface $waktu = null): ?bool
    {
        $manual = StatusOperasional::untuk((string) ($tempat['slug'] ?? ''));
        if ($manual !== null) {
            return $manual === 'buka';
        }

        $jadwal = self::jadwal($tempat);
        if (! $jadwal) {
            return null;
        }
        $waktu = \Illuminate\Support\Carbon::instance($waktu ?? now())->setTimezone('Asia/Jakarta');
        $hari = (int) $waktu->isoFormat('E');
        $sekarang = $waktu->hour * 60 + $waktu->minute;
        foreach (self::rentangUntukHari($jadwal, $hari) as [$buka, $tutup]) {
            if ($buka === $tutup || ($tutup > $buka && $sekarang >= $buka && $sekarang < $tutup)) {
                return true;
            }
            if ($tutup < $buka && $sekarang >= $buka) {
                return true;
            }
        }

        $kemarin = $hari === 1 ? 7 : $hari - 1;
        foreach (self::rentangUntukHari($jadwal, $kemarin) as [$buka, $tutup]) {
            if ($tutup < $buka && $sekarang < $tutup) {
                return true;
            }
        }

        return false;
    }

    /** Apakah tempat sedang buka, menurut jam WIB atau override pengelola. */
    public static function bukaSekarang(array $tempat, ?\DateTimeInterface $waktu = null): bool
    {
        return self::statusOperasional($tempat, $waktu) === true;
    }

    public static function cari(string $q): array
    {
        $q = mb_strtolower(trim($q));
        if ($q === '') {
            return [];
        }

        $hasil = [];
        foreach (self::entitas() as $slug => $entity) {
            if ($entity['jenis'] === 'wilayah') {
                continue;
            }
            $kategori = $entity['kategori'] ?? '';
$labelKategori = self::KATEGORI[$kategori] ?? '';

$teks = mb_strtolower(
    $entity['nama'].' '.
    ($entity['ringkas'] ?? '').' '.
    ($entity['kecamatan'] ?? '').' '.
    $kategori.' '.
    $labelKategori
);
            $nama = mb_strtolower($entity['nama']);
            $skor = 0;
            foreach (preg_split('/\s+/', $q) as $kata) {
                if (mb_strlen($kata) < 3) {
                    continue;
                }
                if (str_contains($teks, $kata)) {
                    $skor += 2;
                } else {
                    foreach (preg_split('/[\s\-]+/', $nama) as $bagianNama) {
                        if (mb_strlen($bagianNama) >= 4 && levenshtein($kata, $bagianNama) <= 1) {
                            $skor += 1.5;
                        }
                    }
                }
            }
            similar_text($q, $nama, $persen);
            $skor += $persen / 50;
            if ($skor >= 1.6) {
                $hasil[$slug] = $skor;
            }
        }
        arsort($hasil);

        return array_map(fn ($slug) => self::get($slug), array_keys($hasil));
    }

    public static function koreksi(string $q): ?string
    {
        $q = mb_strtolower(trim($q));
        $terbaik = null;
        $jarak = PHP_INT_MAX;
        foreach (self::entitas() as $entity) {
            $jarakNama = levenshtein($q, mb_strtolower($entity['nama']));
            if ($jarakNama < $jarak) {
                [$jarak, $terbaik] = [$jarakNama, $entity['nama']];
            }
        }

        return $jarak > 0 && $jarak <= 3 ? $terbaik : null;
    }

    public static function kredit(?string $berkas = null): array|string|null
    {
        static $kredit = null;
        if ($kredit === null) {
            $path = public_path('storage/foto_wisata/credits.json');
            $kredit = [];
            foreach (is_file($path) ? json_decode(file_get_contents($path), true) : [] as $item) {
                $kredit[basename($item['file'])] = $item;
            }
        }

        return $berkas === null ? $kredit : ($kredit[$berkas]['credit'] ?? null);
    }

}
