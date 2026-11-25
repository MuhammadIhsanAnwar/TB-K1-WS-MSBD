<?php

namespace App\Services;

use App\Support\Katalog;
use App\Support\Penyimpanan;
use App\Support\SparqlService;
use Illuminate\Support\Str;
use RuntimeException;

class TanyaMasNonovService
{
    private const TITIK_ACUAN = [
        [
            'nama' => 'Universitas Sumatera Utara (USU)',
            'alias' => ['usu', 'universitas sumatera utara', 'kampus usu'],
            'lat' => 3.5613218,
            'lng' => 98.6568033,
        ],
    ];

    private const LABEL_KELAS = [
        'mv:SitusSejarah' => 'Sejarah & cagar budaya',
        'mv:Museum' => 'Museum',
        'mv:RumahIbadah' => 'Rumah ibadah',
        'mv:TempatKuliner' => 'Kuliner',
        'mv:RuangPublik' => 'Taman & ruang publik',
        'mv:PusatBelanja' => 'Belanja',
        'mv:RekreasiAir' => 'Rekreasi air',
    ];

    public function __construct(
        private readonly AIService $ai,
        private readonly PostgreSQLService $postgres,
    ) {}

    public static function klasifikasi(string $pertanyaan): array
    {
        $teks = mb_strtolower($pertanyaan);
        $personalisasi = preg_match('/\b(rekomendasikan|rekomendasi|sesuai|preferensi)\b/u', $teks)
            && preg_match('/\b(riwayat|pesanan|pemesanan|transaksi|preferensi saya|sering saya kunjungi|saya kunjungi)\b/u', $teks);

        if ($personalisasi) {
            return ['intent' => 'personalized_recommendation', 'source' => 'hybrid', 'requires_database' => true];
        }

        if (preg_match('/\b(akun saya|profil saya|nama akun|riwayat pemesanan|riwayat transaksi|pesanan saya|pemesanan saya|transaksi saya|pembayaran saya|status transaksi|status pesanan|pengaturan akun)\b/u', $teks)) {
            return ['intent' => 'user_transaction_history', 'source' => 'sql', 'requires_database' => true];
        }

        if (preg_match('/\b(dekat|terdekat|sekitar|jarak|jauh)\b/u', $teks)) {
            return ['intent' => 'tourism_knowledge', 'source' => 'sparql', 'requires_database' => true];
        }

        if (preg_match('/\b(ayam|soto|bakso|sate|martabak|nasi goreng|mie|mi|bihun|lontong|bubur|rendang|ikan bakar)\b/u', $teks)) {
            return ['intent' => 'tourism_knowledge', 'source' => 'sparql', 'requires_database' => true];
        }

        if (preg_match('/\b(rekomendasi|rekomendasikan|sarankan|saran|paling banyak diulas|banyak diulas|ulasan terbanyak|terbanyak ulasan|jumlah ulasan|paling banyak ulasan|rating tertinggi|rating|ulasan|review|bintang|paling disukai|banyak disukai|paling banyak disukai|paling banyak dilike|banyak dilike|like terbanyak|jumlah suka|jumlah like|wisata populer|wisata terpopuler|wisata paling ramai|paling ramai)\b/u', $teks)) {
            return ['intent' => 'tourism_popularity', 'source' => 'hybrid', 'requires_database' => true];
        }

        if (preg_match('/\b(wisata|tempat|taman|museum|istana|sejarah|religi|masjid|gereja|vihara|kuil|kuliner|kecamatan|fasilitas|tiket|harga|tarif|biaya|bayar|jam buka|jam|lokasi|alamat|terdekat|dekat|kota medan|statistik|jumlah wisata|ulasan|diulas|review|rating|bintang|dilike|di-like|disukai|suka|like|favorit|populer|ramai)\b/u', $teks)) {
            return ['intent' => 'tourism_knowledge', 'source' => 'sparql', 'requires_database' => true];
        }

        if (preg_match('/\b(web semantik|rdf|sparql|teknologi ai|kecerdasan buatan|machine learning|sql)\b/u', $teks)) {
            return ['intent' => 'general_knowledge', 'source' => 'general', 'requires_database' => false];
        }

        if (self::entitasRdfDisebut($pertanyaan) !== null) {
            return ['intent' => 'tourism_knowledge', 'source' => 'sparql', 'requires_database' => true];
        }

        return ['intent' => 'general_knowledge', 'source' => 'general', 'requires_database' => false];
    }

    public function jawab(string $pertanyaan, ?array $pengguna = null, array $riwayat = [], ?array $lokasi = null): array
    {
        $pertanyaan = $this->pertanyaanTerdekatLanjutan($pertanyaan, $riwayat) ?? $pertanyaan;
        $routing = self::klasifikasi($pertanyaan);

        if ($routing['source'] === 'sql') {
            return $this->jawabSql($pertanyaan, $pengguna, $riwayat);
        }
        if ($routing['source'] === 'hybrid') {
            if ($routing['intent'] === 'tourism_popularity') {
                return $this->jawabPopularitas($pertanyaan, $riwayat);
            }

            return $this->jawabHybrid($pertanyaan, $pengguna, $riwayat);
        }
        if ($routing['source'] === 'sparql') {
            return $this->jawabSemantik($pertanyaan, $riwayat, $lokasi);
        }

        return $this->jawabUmum($pertanyaan, $riwayat);
    }

    private function pertanyaanTerdekatLanjutan(string $pertanyaan, array $riwayat): ?string
    {
        if (! preg_match('/^\s*(?:dari|mulai\s+dari)\s+([\p{L}\p{N}][\p{L}\p{N}\s,.\'-]{0,80})[?.!]*\s*$/iu', $pertanyaan, $cocok)) {
            return null;
        }

        $pertanyaanTerdekat = null;
        foreach (array_reverse($riwayat) as $giliran) {
            $sebelumnya = (string) ($giliran['pertanyaan'] ?? '');
            if (preg_match('/\b(dekat|terdekat|sekitar)\b/iu', $sebelumnya)) {
                $pertanyaanTerdekat = rtrim($sebelumnya, " \t\n\r\0\x0B?.!");
                break;
            }
        }

        return $pertanyaanTerdekat === null
            ? null
            : $pertanyaanTerdekat.' dari '.trim($cocok[1]);
    }

    private function jawabUmum(string $pertanyaan, array $riwayat): array
    {
        $konteks = [
            'website' => [
                'nama' => 'MedanVisit',
                'cakupan' => 'informasi wisata Kota Medan',
                'fitur' => ['pencarian tempat', 'informasi kuliner', 'tiket', 'ulasan', 'suka', 'perencanaan rute'],
            ],
        ];
        $perluKatalog = (bool) preg_match('/\b(medan|medanvisit|wisata|kuliner|makan|tiket|tempat|rute|perjalanan|museum|situs|website|platform|aplikasi|layanan|fitur|bantu|kemampuan)\b/u', mb_strtolower($pertanyaan));
        if ($perluKatalog) {
            $tempat = array_values(Katalog::tempat());
            $konteks['katalog_database3'] = [
                'jumlah_tempat' => count($tempat),
                'kategori' => array_values(array_unique(array_filter(array_column($tempat, 'kategori')))),
                'contoh_tempat' => array_map(
                    fn (array $item) => [
                        'nama' => $item['nama'],
                        'kategori' => $item['kategori'] ?? null,
                    ],
                    array_slice($tempat, 0, 8),
                ),
            ];
        }

        return [
            'teks' => [$this->jawabAiAtauFallback(
                $pertanyaan,
                'pengetahuan umum dan fakta publik MedanVisit'.($perluKatalog ? ' dari Database 3' : '').'; riwayat percakapan sesi dari Database 1 bila tersedia',
                $konteks,
                $riwayat,
                $this->fallbackUmum($pertanyaan, $konteks),
            )],
            'tempat' => [],
        ];
    }

    private function fallbackUmum(string $pertanyaan, array $konteks): string
    {
        $teks = mb_strtolower($pertanyaan);
        if (preg_match('/\b(hai|halo|hello|assalamualaikum|selamat pagi|selamat siang|selamat sore|selamat malam)\b/u', $teks)) {
            return 'Hai! Aku Mas Nonov, asisten AI MedanVisit. Ada yang ingin kamu tanyakan?';
        }
        if (preg_match('/\b(siapa kamu|kamu siapa|nama kamu|siapa namamu)\b/u', $teks)) {
            return 'Aku Mas Nonov, asisten AI MedanVisit. Aku bisa membantu mencari informasi tempat wisata, kuliner, tiket, ulasan, dan rute di Kota Medan.';
        }

        $contohTempat = array_column($konteks['katalog_database3']['contoh_tempat'] ?? [], 'nama');
        $contoh = $contohTempat === [] ? '' : ' Contoh tempat yang tercatat: '.implode(', ', $contohTempat).'.';

        return 'Aku Mas Nonov, asisten AI MedanVisit. Aku bisa ngobrol dan membantu berdasarkan informasi tempat wisata, kuliner, tiket, ulasan, serta rute di Kota Medan.'.$contoh;
    }

    private static function entitasRdfDisebut(string $pertanyaan): ?array
    {
        preg_match_all('/\b\p{Lu}[\p{L}\p{N}-]{2,}\b/u', $pertanyaan, $kataBerhurufBesar);
        $kataUmum = ['apa', 'siapa', 'mana', 'kota', 'medan', 'sumatera', 'utara', 'wisata', 'tempat', 'taman', 'museum', 'sejarah', 'religi', 'kuliner', 'gereja', 'masjid'];
        $namaKandidat = array_filter(
            array_map('mb_strtolower', $kataBerhurufBesar[0] ?? []),
            fn (string $kata) => ! in_array($kata, $kataUmum, true),
        );
        $teks = mb_strtolower($pertanyaan);
        $bertanyaTentangEntitas = (bool) preg_match('/\b(siapa|tentang|ceritakan|cerita|kisah|sejarah|asal-usul|tokoh|kerajaan|peristiwa|budaya|hubungan|relasi|dibangun|didirikan)\b/u', $teks);
        if ($namaKandidat === [] && ! $bertanyaTentangEntitas) {
            return null;
        }

        $entitasKatalog = Katalog::entitas();
        uasort($entitasKatalog, fn (array $a, array $b) => mb_strlen($b['nama']) <=> mb_strlen($a['nama']));
        foreach ($entitasKatalog as $entitas) {
            $nama = mb_strtolower(trim((string) ($entitas['nama'] ?? '')));
            if (
                mb_strlen($nama) >= 4
                && preg_match('/(?<![\p{L}\p{N}])'.preg_quote($nama, '/').'(?![\p{L}\p{N}])/u', $teks)
            ) {
                return $entitas;
            }
        }

        return null;
    }

    private function jawabPopularitas(string $pertanyaan, array $riwayat): array
    {
        $teks = mb_strtolower($pertanyaan);
        $rekomendasi = (bool) preg_match('/\b(rekomendasi|rekomendasikan|sarankan|saran|populer|terpopuler|paling ramai)\b/u', $teks);
        $mintaUlasan = $rekomendasi || (bool) preg_match('/\b(ulasan|diulas|review|rating|bintang)\b/u', $teks);
        $mintaSuka = $rekomendasi || (bool) preg_match('/\b(dilike|di-like|disukai|suka|like|favorit|populer|terpopuler|paling ramai)\b/u', $teks);
        [$kategori] = $this->filterPertanyaan($pertanyaan);
        $kategori = $kategori === null ? null : (array) $kategori;
        $sesuaiKategori = fn (array $tempat): bool => $kategori === null
            || in_array($tempat['kategori'] ?? null, $kategori, true);
        $konteks = [];
        $bagianFallback = [];
        $kartu = [];
        $tempatTerkait = null;
        $semuaTempat = Katalog::tempat();
        foreach ($semuaTempat as $tempat) {
            if (str_contains($teks, mb_strtolower($tempat['nama']))) {
                $tempatTerkait = $tempat;
                break;
            }
        }
        $mintaPeringkat = $rekomendasi
            || (bool) preg_match('/\b(paling|terbanyak|tertinggi|terbesar|populer|terpopuler|ranking|urutan|top)\b/u', $teks)
            || ($tempatTerkait === null && (bool) preg_match('/\b(mana|banyak|ramai)\b/u', $teks));

        if ($tempatTerkait !== null && ! $mintaPeringkat) {
            $detail = ['nama' => $tempatTerkait['nama']];
            $fallbackDetail = [];
            $popularitas = ($mintaUlasan || $mintaSuka)
                ? $this->postgres->ringkasanPopularitasTempat($tempatTerkait['slug'])
                : null;
            if ($mintaUlasan) {
                $detail['ulasan'] = [
                    'jumlah' => (int) ($popularitas['jumlah_ulasan'] ?? 0),
                    'jumlah_rating' => (int) ($popularitas['jumlah_rating'] ?? 0),
                    'rata_rata_bintang' => isset($popularitas['rata_rata_bintang'])
                        ? (float) $popularitas['rata_rata_bintang']
                        : null,
                ];
                $fallbackDetail[] = $detail['ulasan']['jumlah_rating'] > 0
                    ? $tempatTerkait['nama'].' memiliki '.$detail['ulasan']['jumlah_rating'].' rating publik dengan rata-rata '.number_format($detail['ulasan']['rata_rata_bintang'], 1, ',', '.').'/5 dan '.$detail['ulasan']['jumlah'].' ulasan.'
                    : 'Belum ada rating publik yang tercatat untuk '.$tempatTerkait['nama'].'.';
            }
            if ($mintaSuka) {
                $detail['jumlah_suka_db2'] = (int) ($popularitas['jumlah_suka'] ?? 0);
                $fallbackDetail[] = $tempatTerkait['nama'].' mendapat '.$detail['jumlah_suka_db2'].' suka yang tercatat di DB 2.';
            }

            return [
                'teks' => [$this->jawabAiAtauFallback(
                    $pertanyaan,
                    'AllegroGraph RDF untuk tempat, PostgreSQL untuk jumlah ulasan, serta DB 2 untuk rating dan suka',
                    $detail,
                    $riwayat,
                    implode(' ', $fallbackDetail),
                )],
                'tempat' => [\App\Support\Asisten::kartu($tempatTerkait)],
            ];
        }

        if ($mintaUlasan) {
            $peringkat = $this->postgres->peringkatUlasan(100);
            $ulasan = [];
            foreach ($peringkat as $baris) {
                $tempat = Katalog::get((string) $baris['tempat_slug']);
                if (! $tempat || ($tempat['jenis'] ?? null) !== 'tempat' || ! $sesuaiKategori($tempat)) {
                    continue;
                }

                $ulasan[] = [
                    'nama' => $tempat['nama'],
                    'jumlah_ulasan' => (int) $baris['jumlah_ulasan'],
                    'jumlah_rating' => (int) ($baris['jumlah_rating'] ?? 0),
                    'rata_rata_bintang' => isset($baris['rata_rata_bintang'])
                        ? (float) $baris['rata_rata_bintang']
                        : null,
                ];
                $kartu[] = \App\Support\Asisten::kartu($tempat);
            }
            $konteks['peringkat_ulasan_postgresql'] = $ulasan;
            $bagianFallback[] = $ulasan === []
                ? 'Belum ada ulasan publik yang tercatat untuk dibandingkan.'
                : 'Tempat dengan ulasan terbanyak: '.implode('; ', array_map(
                    fn (array $baris) => $baris['nama'].' ('.$baris['jumlah_ulasan'].' ulasan'.($baris['rata_rata_bintang'] === null ? '' : ', rata-rata '.number_format($baris['rata_rata_bintang'], 1, ',', '.').'/5').')',
                    $ulasan,
                )).'.';
        }

        if ($mintaSuka) {
            $peringkatSuka = [];
            foreach ($this->postgres->peringkatSuka(1000) as $baris) {
                $tempat = Katalog::get((string) $baris['tempat_slug']);
                if (
                    $tempat
                    && ($tempat['jenis'] ?? null) === 'tempat'
                    && $sesuaiKategori($tempat)
                ) {
                    $peringkatSuka[] = [
                        'nama' => $tempat['nama'],
                        'jumlah_suka' => (int) $baris['jumlah_suka'],
                    ];
                }
                if (count($peringkatSuka) === 5) {
                    break;
                }
            }
            $konteks['peringkat_suka_db2'] = $peringkatSuka;
            $bagianFallback[] = $peringkatSuka === []
                ? 'Belum ada jumlah suka yang tercatat di DB 2.'
                : 'Tempat dengan suka terbanyak: '.implode('; ', array_map(
                    fn (array $baris) => $baris['nama'].' ('.$baris['jumlah_suka'].' suka)',
                    $peringkatSuka,
                )).'.';
        }

        if ($rekomendasi) {
            $kandidat = array_values(array_filter(Katalog::tempat(), $sesuaiKategori));
            $konteks['kandidat_rdf'] = array_map(fn (array $tempat) => [
                'nama' => $tempat['nama'],
                'kategori' => $tempat['kategori'],
                'kecamatan' => $tempat['kecamatan'],
                'deskripsi' => $tempat['ringkas'],
            ], array_slice($kandidat, 0, 8));
            $konteks['dasar_rekomendasi'] = 'tempat dan kategori dari RDF MedanVisit; jumlah ulasan publik dari PostgreSQL dan jumlah suka dari DB 2; metrik ditampilkan terpisah tanpa skor gabungan';
            if ($kartu === []) {
                $kartu = array_map(fn (array $tempat) => \App\Support\Asisten::kartu($tempat), array_slice($kandidat, 0, 5));
            }
            if ($bagianFallback === []) {
                $bagianFallback[] = $kandidat === []
                    ? 'Belum ada tempat yang sesuai dengan kategori tersebut di RDF MedanVisit.'
                    : 'Berikut pilihan dari kategori yang tercatat di RDF MedanVisit; data pembanding ulasan dan suka belum tersedia.';
            }
        }
        $fallback = implode(' ', $bagianFallback);

        return [
            'teks' => [$this->jawabAiAtauFallback(
                $pertanyaan,
                'RDF MedanVisit untuk nama tempat, PostgreSQL untuk ringkasan ulasan, dan DB 2 untuk jumlah suka',
                $konteks,
                $riwayat,
                $fallback,
            )],
            'tempat' => array_slice($kartu, 0, 5),
        ];
    }

    private function jawabSql(string $pertanyaan, ?array $pengguna, array $riwayat): array
    {
        $id = filter_var($pengguna['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            return ['teks' => ['Untuk melihat profil atau riwayat pesanan, silakan masuk ke akun MedanVisit terlebih dahulu.'], 'tempat' => []];
        }

        $teks = mb_strtolower($pertanyaan);
        if (preg_match('/\b(akun saya|profil saya|nama akun)\b/u', $teks)) {
            $profil = $this->postgres->profilPengguna((int) $id);
            if ($profil === null) {
                return ['teks' => ['Data profil akunmu tidak ditemukan.'], 'tempat' => []];
            }

            $konteks = ['nama' => $profil['name']];
            $jawaban = $this->jawabAiAtauFallback($pertanyaan, 'PostgreSQL: profil pengguna terautentikasi', $konteks, $riwayat,
                'Nama akunmu adalah '.($profil['name'] ?? 'pengguna MedanVisit').'.');

            return ['teks' => [$jawaban], 'tempat' => []];
        }

        if (preg_match('/\b(berapa(?:\s+banyak)?|jumlah)\b.*\b(transaksi|pesanan|pemesanan)\b/u', $teks)) {
            $jumlah = $this->postgres->jumlahPesanan((int) $id);
            $konteks = ['jumlah_pesanan' => $jumlah];
            $fallback = 'Jumlah transaksi yang tercatat untuk akunmu adalah '.$jumlah.'.';

            return ['teks' => [$this->jawabAiAtauFallback($pertanyaan, 'PostgreSQL: jumlah pesanan milik pengguna terautentikasi', $konteks, $riwayat, $fallback)], 'tempat' => []];
        }

        $pesanan = $this->postgres->riwayatPesanan((int) $id);
        $ringkas = array_map(fn (array $p) => [
            'tempat' => $p['tempat_slug'],
            'tanggal_kunjungan' => $p['tanggal_kunjungan'],
            'total' => (int) $p['total'],
            'status' => $p['status'],
            'metode' => $p['metode'],
        ], $pesanan);
        $konteks = [
            'jumlah_ditemukan' => count($ringkas),
            'pesanan' => $ringkas,
        ];
        if ($ringkas === []) {
            $fallback = 'Belum ada riwayat pesanan yang tercatat untuk akunmu.';
        } else {
            $fallback = 'Ada '.count($ringkas).' pesanan terbaru. '.implode(' ', array_map(
                fn (array $p) => $p['tempat'].' pada '.$p['tanggal_kunjungan'].': '.$p['status'].', total Rp'.number_format($p['total'], 0, ',', '.').'.',
                $ringkas,
            ));
        }

        return ['teks' => [$this->jawabAiAtauFallback($pertanyaan, 'PostgreSQL: pesanan milik pengguna terautentikasi', $konteks, $riwayat, $fallback)], 'tempat' => []];
    }

    private function jawabHybrid(string $pertanyaan, ?array $pengguna, array $riwayat): array
    {
        $id = filter_var($pengguna['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            return ['teks' => ['Untuk membuat rekomendasi berdasarkan riwayat pesanan, silakan masuk ke akun MedanVisit terlebih dahulu.'], 'tempat' => []];
        }

        $pesanan = $this->postgres->riwayatPesanan((int) $id);
        $kategori = [];
        foreach ($pesanan as $pesananPengguna) {
            $tempat = Katalog::get((string) $pesananPengguna['tempat_slug']);
            if (($tempat['jenis'] ?? null) === 'tempat' && ! empty($tempat['kategori'])) {
                $kategori[$tempat['kategori']] = true;
            }
        }

        if ($kategori === []) {
            return ['teks' => ['Belum ada kategori wisata dari riwayat pesananmu yang bisa dipakai untuk rekomendasi.'], 'tempat' => []];
        }

        $hasil = [];
        foreach (array_keys($kategori) as $kategoriWisata) {
            $hasil = array_merge($hasil, $this->hasilTempat(SparqlService::cariTempat(kategori: $kategoriWisata, batas: 12)));
        }
        $hasil = array_slice(array_values(array_unique($hasil, SORT_REGULAR)), 0, 8);
        $konteks = [
            'dasar_rekomendasi' => 'kategori dari tempat pada riwayat pemesanan; bukan riwayat kunjungan',
            'kategori' => array_keys($kategori),
            'tempat' => array_map(fn (array $t) => ['nama' => $t['nama'], 'kategori' => $t['jenis']], $hasil),
        ];
        $fallback = $hasil
            ? 'Rekomendasi ini mengikuti kategori tempat yang pernah kamu pesan: '.implode(', ', array_keys($kategori)).'.'
            : 'Belum ada tempat yang cocok dengan kategori dalam riwayat pesananmu.';

        return [
            'teks' => [$this->jawabAiAtauFallback($pertanyaan, 'PostgreSQL dan AllegroGraph; rekomendasi berdasarkan riwayat pesanan', $konteks, $riwayat, $fallback)],
            'tempat' => $hasil,
        ];
    }

    private function jawabSemantik(string $pertanyaan, array $riwayat, ?array $lokasi = null): array
    {
        [$kategori, $kecamatan, $fasilitas] = $this->filterPertanyaan($pertanyaan);
        $teksPertanyaan = mb_strtolower($pertanyaan);

        if (preg_match('/\b(tiket|harga|bayar|biaya|rute|rencana|itinerary|jadwal)\b/u', $teksPertanyaan)) {
            $jawabanLama = \App\Support\Asisten::jawab($pertanyaan);
            $konteksLama = [
                'teks' => $jawabanLama['teks'] ?? [],
                'tempat' => array_map(fn (array $t) => [
                    'nama' => $t['nama'] ?? '',
                    'jenis' => $t['jenis'] ?? '',
                    'keterangan' => $t['keterangan'] ?? '',
                    'harga' => $t['harga'] ?? null,
                    'jarak' => $t['jarak'] ?? null,
                ], $jawabanLama['tempat'] ?? []),
            ];
            $teksJawaban = $this->jawabAiAtauFallback(
                $pertanyaan,
                'katalog wisata MedanVisit yang bersumber dari AllegroGraph',
                $konteksLama,
                $riwayat,
                implode(' ', $jawabanLama['teks'] ?? []),
            );
            $jawabanLama['teks'] = [$teksJawaban];

            return $jawabanLama;
        }

        if (preg_match('/\b(dekat|terdekat|sekitar|jarak|jauh)\b/u', $teksPertanyaan)) {
            return $this->jawabTerdekat($pertanyaan, $kategori, $riwayat, $lokasi);
        }

        if (preg_match('/\b(berapa|jumlah|total|statistik)\b/u', $teksPertanyaan)) {
            $hasil = SparqlService::hitungTempat($kategori);
            if (! ($hasil['ok'] ?? false)) {
                throw new RuntimeException($hasil['pesan'] ?? 'Statistik AllegroGraph tidak tersedia.');
            }
            $jumlah = array_sum(array_map(fn (array $baris) => (int) ($baris[1] ?? 0), $hasil['baris'] ?? []));
            $konteks = ['kategori' => $kategori, 'jumlah' => $jumlah];
            $fallback = 'Jumlah tempat wisata'.($kategori ? ' kategori '.implode(', ', (array) $kategori) : '').' yang tercatat di AllegroGraph adalah '.$jumlah.'.';

            return ['teks' => [$this->jawabAiAtauFallback($pertanyaan, 'AllegroGraph SPARQL COUNT', $konteks, $riwayat, $fallback)], 'tempat' => []];
        }

        $entitas = self::entitasRdfDisebut($pertanyaan);
        if ($entitas !== null) {
            $jawabanKatalog = \App\Support\Asisten::jawab($pertanyaan);
            $relasi = array_slice(Katalog::tetangga($entitas['slug'], false), 0, 8);
            $konteks = [
                'entitas_rdf' => [
                    'nama' => $entitas['nama'],
                    'jenis' => $entitas['jenis'],
                    'kategori' => $entitas['kategori'] ?? null,
                    'deskripsi' => $entitas['ringkas'] ?? '',
                    'cerita' => $entitas['cerita'] ?? [],
                    'masa' => $entitas['masa'] ?? null,
                    'alamat' => $entitas['alamat'] ?? null,
                    'kecamatan' => $entitas['kecamatan'] ?? null,
                    'kelurahan' => $entitas['kelurahan'] ?? null,
                    'jam_buka' => $entitas['jam'] ?? null,
                    'fasilitas' => $entitas['fasilitas'] ?? [],
                    'cara_menuju' => $entitas['cara'] ?? [],
                    'tiket' => $entitas['tiket'] ?? [],
                    'sumber' => $entitas['sumber'] ?? null,
                ],
                'relasi_rdf' => array_map(fn (array $tetangga) => [
                    'fakta' => Katalog::kalimat($tetangga['relasi'], $entitas['slug']),
                    'sumber' => $tetangga['relasi']['sumber'] ?? 'Database 3',
                ], $relasi),
                'jawaban_katalog' => $jawabanKatalog['teks'] ?? [],
                'entitas' => array_map(fn (array $tempat) => [
                    'nama' => $tempat['nama'] ?? '',
                    'kategori' => $tempat['jenis'] ?? '',
                    'deskripsi' => $tempat['keterangan'] ?? '',
                    'harga' => $tempat['harga'] ?? null,
                    'jarak_meter' => $tempat['jarak'] ?? null,
                ], $jawabanKatalog['tempat'] ?? []),
            ];
            $fallback = implode(' ', $jawabanKatalog['teks'] ?? []);

            return [
                'teks' => [$this->jawabAiAtauFallback(
                    $pertanyaan,
                    'AllegroGraph RDF melalui katalog wisata MedanVisit',
                    $konteks,
                    $riwayat,
                    $fallback !== '' ? $fallback : 'Informasi entitas tersebut belum tersedia di katalog MedanVisit.',
                )],
                'tempat' => $jawabanKatalog['tempat'] ?? [],
            ];
        }

        $mintaRekomendasiKuliner = $kategori === 'kuliner'
            && preg_match('/\b(makan|makanan|kuliner|restoran|rumah makan|enak|rekomendasi|sarankan|saran)\b/u', $teksPertanyaan);
        if ($mintaRekomendasiKuliner) {
            $kandidat = array_values(Katalog::tempat('kuliner'));
            $kandidat = array_slice($kandidat, 0, 5);
            $konteks = [
                'kategori' => 'kuliner',
                'hasil' => array_map(fn (array $tempat) => [
                    'nama' => $tempat['nama'],
                    'kecamatan' => $tempat['kecamatan'],
                    'deskripsi' => $tempat['ringkas'],
                    'alamat' => $tempat['alamat'] ?? null,
                    'jam' => $tempat['jam'] ?? null,
                ], $kandidat),
            ];
            $fallback = $kandidat === []
                ? 'Belum ada data tempat kuliner yang tercatat di katalog MedanVisit.'
                : 'Beberapa pilihan tempat makan yang tercatat di MedanVisit: '.implode(', ', array_map(
                    fn (array $tempat) => $tempat['nama'].(! empty($tempat['kecamatan']) ? ' ('.$tempat['kecamatan'].')' : ''),
                    $kandidat,
                )).'.';

            return [
                'teks' => [$this->jawabAiAtauFallback(
                    $pertanyaan,
                    'AllegroGraph RDF: katalog tempat kuliner MedanVisit',
                    $konteks,
                    $riwayat,
                    $fallback,
                )],
                'tempat' => array_map(fn (array $tempat) => \App\Support\Asisten::kartu($tempat), $kandidat),
            ];
        }

        $kataKunci = $this->kataKunci($pertanyaan, $kategori, $kecamatan, $fasilitas);
        $result = SparqlService::cariTempat($kataKunci, $kategori, $kecamatan, $fasilitas);
        $tempat = $this->hasilTempat($result);
        $konteks = [
            'filter' => array_filter(['kategori' => $kategori, 'kecamatan' => $kecamatan, 'fasilitas' => $fasilitas, 'kata_kunci' => $kataKunci]),
            'hasil' => array_map(fn (array $t) => [
                'nama' => $t['nama'],
                'kategori' => $t['jenis'],
                'kecamatan' => $t['kecamatan'],
                'deskripsi' => $t['keterangan'],
            ], $tempat),
        ];
        $fallback = $tempat
            ? 'Berikut tempat yang cocok dengan data wisata MedanVisit.'
            : 'Aku belum menemukan tempat yang cocok di data wisata MedanVisit.';

        return [
            'teks' => [$this->jawabAiAtauFallback($pertanyaan, 'AllegroGraph SPARQL', $konteks, $riwayat, $fallback)],
            'tempat' => $tempat,
        ];
    }

    private function jawabTerdekat(string $pertanyaan, string|array|null $kategori, array $riwayat, ?array $lokasi = null): array
    {
        $entitas = Katalog::tempat();
        $teks = mb_strtolower($pertanyaan);
        $mintaJarak = (bool) preg_match('/\b(jarak|jauh)\b/u', $teks);
        $mintaLokasiPribadi = (bool) preg_match('/\b(saya|aku|sini|saat ini)\b/u', $teks);
        $asal = null;

        if (preg_match('/\b(?:dari|dekat|sekitar)\s+(.+?)(?=\s+\b(?:ke|menuju|dan)\b|[?.!]|$)/u', $teks, $titikTertulis)) {
            $frasaAsal = trim($titikTertulis[1]);
            foreach (self::TITIK_ACUAN as $titik) {
                foreach ($titik['alias'] as $alias) {
                    if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($alias, '/').'(?![\p{L}\p{N}])/u', $frasaAsal)) {
                        $asal = $titik + ['slug' => null];
                        break 2;
                    }
                }
            }
            foreach ($entitas as $tempat) {
                $nama = mb_strtolower($tempat['nama']);
                if (str_contains($frasaAsal, $nama) && (! $asal || mb_strlen($tempat['nama']) > mb_strlen($asal['nama']))) {
                    $asal = $tempat;
                }
            }
        }

        if ($asal === null && isset($lokasi['lat'], $lokasi['lng'])) {
            if (
                is_numeric($lokasi['lat'])
                && is_numeric($lokasi['lng'])
                && (float) $lokasi['lat'] >= -90
                && (float) $lokasi['lat'] <= 90
                && (float) $lokasi['lng'] >= -180
                && (float) $lokasi['lng'] <= 180
            ) {
                $asal = [
                    'nama' => 'lokasimu saat ini',
                    'lat' => round((float) $lokasi['lat'], 4),
                    'lng' => round((float) $lokasi['lng'], 4),
                    'slug' => null,
                ];
            }
        }

        if ($asal === null && ! $mintaJarak && ! $mintaLokasiPribadi) {
            foreach ($entitas as $tempat) {
                $nama = mb_strtolower($tempat['nama']);
                if (str_contains($teks, $nama) && (! $asal || mb_strlen($tempat['nama']) > mb_strlen($asal['nama']))) {
                    $asal = $tempat;
                }
            }
        }

        if ($asal === null) {
            return ['teks' => ['Aku belum menerima lokasi perangkatmu. Izinkan akses lokasi saat diminta, atau sebutkan nama tempat/landmark sebagai titik awal.'], 'tempat' => []];
        }
        if (
            ! isset($asal['lat'], $asal['lng'])
            || ! is_numeric($asal['lat'])
            || ! is_numeric($asal['lng'])
            || (isset($asal['slug']) && $asal['slug'] !== null && ! $this->koordinatTempatValid($asal))
        ) {
            return ['teks' => ['Koordinat titik awal belum tersedia di katalog, jadi jarak belum bisa dihitung.'], 'tempat' => []];
        }

        $slugTujuan = [];
        if ($mintaJarak) {
            foreach ($entitas as $tempat) {
                if (($tempat['slug'] ?? null) !== ($asal['slug'] ?? null)
                    && str_contains($teks, mb_strtolower($tempat['nama']))) {
                    $slugTujuan[] = $tempat['slug'];
                }
            }
        }

        $kandidat = array_filter($entitas, fn (array $tempat) =>
            ($tempat['slug'] ?? null) !== ($asal['slug'] ?? null)
            && ($kategori === null || in_array($tempat['kategori'] ?? null, (array) $kategori, true))
            && ($slugTujuan === [] || in_array($tempat['slug'], $slugTujuan, true))
            && $this->koordinatTempatValid($tempat)
        );
        $kandidat = array_map(fn (array $tempat) => [
            'tempat' => $tempat,
            'jarak' => Katalog::jarakMeter($asal, $tempat),
        ], $kandidat);
        usort($kandidat, fn (array $a, array $b) => $a['jarak'] <=> $b['jarak']);
        $terdekat = array_slice($kandidat, 0, 5);
        $kartu = array_map(fn (array $item) => \App\Support\Asisten::kartu($item['tempat'], $item['jarak']), $terdekat);
        $konteks = [
            'titik_awal' => $asal['nama'],
            'dasar_jarak' => $asal['nama'] === 'lokasimu saat ini'
                ? 'perkiraan jarak garis lurus; koordinat pengguna hanya dipakai untuk perhitungan ini dan tidak disimpan'
                : 'perkiraan jarak garis lurus dari koordinat tempat atau landmark pada katalog',
            'hasil' => array_map(fn (array $item) => ['nama' => $item['tempat']['nama'], 'jarak_meter' => $item['jarak']], $terdekat),
        ];
        if ($kartu !== [] && $mintaJarak && count($terdekat) === 1) {
            $fallback = 'Perkiraan jarak garis lurus dari '.$asal['nama'].' ke '.$kartu[0]['nama'].' sekitar '.number_format($terdekat[0]['jarak'], 0, ',', '.').' meter.';
        } elseif ($kartu !== []) {
            $fallback = 'Tempat terdekat dari '.$asal['nama'].': '.implode('; ', array_map(
                fn (array $item) => $item['tempat']['nama'].', sekitar '.number_format($item['jarak'], 0, ',', '.').' meter garis lurus',
                $terdekat,
            )).'.';
        } else {
            $fallback = 'Belum ada tempat lain dengan koordinat yang tersedia untuk dibandingkan.';
        }

        return [
            'teks' => [$this->jawabAiAtauFallback($pertanyaan, 'AllegroGraph; jarak garis lurus dihitung dari koordinat pada katalog dan lokasi sementara bila diizinkan', $konteks, $riwayat, $fallback)],
            'tempat' => $kartu,
        ];
    }

    private function koordinatTempatValid(array $tempat): bool
    {
        if (! isset($tempat['lat'], $tempat['lng']) || ! is_numeric($tempat['lat']) || ! is_numeric($tempat['lng'])) {
            return false;
        }

        $lat = (float) $tempat['lat'];
        $lng = (float) $tempat['lng'];

        return $lat >= -90 && $lat <= 90
            && $lng >= -180 && $lng <= 180
            && ($lat !== 0.0 || $lng !== 0.0);
    }

    private function jawabAiAtauFallback(
        string $pertanyaan,
        string $sumber,
        array $konteks,
        array $riwayat,
        string $fallback,
    ): string {
        if (! config('services.openrouter.api_key')) {
            return $fallback;
        }

        try {
            return $this->ai->jawabBerbasisKonteks($pertanyaan, $sumber, $konteks, $riwayat);
        } catch (\Illuminate\Http\Client\ConnectionException|\Illuminate\Http\Client\RequestException|RuntimeException $e) {
            report($e);

            return $fallback;
        }
    }

    private function filterPertanyaan(string $pertanyaan): array
    {
        $teks = mb_strtolower($pertanyaan);
        $kategori = [];
        foreach ([
            'sejarah' => ['sejarah', 'bersejarah', 'historis', 'cagar budaya'],
            'museum' => ['museum'],
            'religi' => ['religi', 'ibadah', 'masjid', 'gereja', 'vihara', 'kuil', 'kelenteng'],
            'kuliner' => ['kuliner', 'makan', 'restoran', 'rumah makan', 'ayam', 'soto', 'bakso', 'sate', 'martabak', 'nasi goreng', 'mie', 'mi', 'bihun', 'lontong', 'bubur', 'rendang', 'ikan bakar'],
            'ruang-publik' => ['taman', 'ruang publik', 'lapangan'],
            'belanja' => ['belanja', 'mall', 'mal', 'pusat perbelanjaan'],
            'rekreasi-air' => ['rekreasi air', 'kolam renang', 'waterpark', 'water park'],
        ] as $kode => $kata) {
            foreach ($kata as $istilah) {
                if (str_contains($teks, $istilah)) {
                    $kategori[$kode] = true;
                    break;
                }
            }
        }
        $kategori = array_keys($kategori);
        $kategori = count($kategori) === 1 ? $kategori[0] : ($kategori === [] ? null : $kategori);

        $kecamatan = null;
        if (preg_match('/\bmedan\s+(maimun|kota|barat|petisah|polonia|tuntungan|baru|marelan|timur|sunggal|johor|selayang|helvetia|area)\b/u', $teks, $cocok)) {
            $kecamatan = 'Medan '.$cocok[1];
        }

        $fasilitas = null;
        if (preg_match('/\bfasilitas(?:\s+berupa)?\s+([\p{L}\d -]{2,40})/u', $teks, $cocok)) {
            $fasilitas = trim(preg_replace('/\s+(yang|di|untuk|pada|apa|saja|tersedia|ada|tertentu)\b.*$/u', '', $cocok[1]));
            if (in_array($fasilitas, ['tertentu', 'apa', 'saja'], true)) {
                $fasilitas = null;
            }
        }

        return [$kategori, $kecamatan, $fasilitas];
    }

    private function kataKunci(string $pertanyaan, ?string $kategori, ?string $kecamatan, ?string $fasilitas): ?string
    {
        $teks = mb_strtolower($pertanyaan);
        if ($kecamatan !== null) {
            $teks = str_replace(mb_strtolower($kecamatan), '', $teks);
        }
        if ($fasilitas !== null) {
            $teks = str_replace($fasilitas, '', $teks);
        }
        $teks = preg_replace('/\b(medan|kota|apa|saja|yang|tersedia|ada|di|dengan|untuk|wisata|tempat|taman|museum|sejarah|bersejarah|historis|cagar|budaya|religi|ibadah|masjid|gereja|vihara|kuil|kelenteng|kuliner|makan|restoran|rumah|belanja|mall|mal|pusat|perbelanjaan|rekreasi|air|kolam|renang|waterpark|water|park|fasilitas|berapa|jumlah|total|statistik|dan|atau|tertentu|berada|terletak|dari|sekitar|terdekat|dekat|paling|dalam|dengan|terhadap)\b/u', ' ', $teks);
        $teks = trim(preg_replace('/\s+/u', ' ', $teks));

        return $teks === '' ? null : Str::limit($teks, 80, '');
    }

    private function hasilTempat(array $hasil): array
    {
        if (! ($hasil['ok'] ?? false)) {
            throw new RuntimeException($hasil['pesan'] ?? 'Pencarian AllegroGraph tidak tersedia.');
        }

        $tempat = [];
        foreach ($hasil['baris'] ?? [] as $baris) {
            $data = array_combine($hasil['kolom'] ?? [], $baris);
            $slug = preg_replace('/^res:/', '', (string) ($data['tempat'] ?? ''));
            if (! preg_match('/^[a-z0-9-]+$/', $slug)) {
                continue;
            }
            $nama = (string) ($data['nama'] ?? '');
            $kelas = (string) ($data['kelas'] ?? '');
            $tempat[] = [
                'nama' => $nama,
                'jenis' => self::LABEL_KELAS[$kelas] ?? 'Tempat wisata',
                'keterangan' => (string) ($data['deskripsi'] ?? ''),
                'foto' => (string) ($data['foto'] ?? '') ?: null,
                'url' => route('tempat', $slug),
                'harga' => null,
                'tiket' => null,
                'jarak' => null,
                'kecamatan' => (string) ($data['kecamatan'] ?? ''),
            ];
        }

        return $tempat;
    }
}
