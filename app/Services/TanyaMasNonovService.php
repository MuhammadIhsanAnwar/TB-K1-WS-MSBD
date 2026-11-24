<?php

namespace App\Services;

use App\Support\Katalog;
use App\Support\Penyimpanan;
use App\Support\SparqlService;
use Illuminate\Support\Str;
use RuntimeException;

class TanyaMasNonovService
{
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

        if (preg_match('/\b(dekat|terdekat|sekitar)\b/u', $teks)) {
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

    public function jawab(string $pertanyaan, ?array $pengguna = null, array $riwayat = []): array
    {
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
            return $this->jawabSemantik($pertanyaan, $riwayat);
        }

        if (! config('services.openrouter.api_key')) {
            return ['teks' => ['Layanan AI Mas Nonov belum dikonfigurasi untuk menjawab pertanyaan umum.'], 'tempat' => []];
        }

        return ['teks' => [$this->ai->jawabBerbasisKonteks($pertanyaan, 'pengetahuan umum', [], $riwayat)], 'tempat' => []];
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
            if ($mintaUlasan) {
                $ringkasan = $this->postgres->ringkasanUlasanTempat($tempatTerkait['slug']);
                $detail['ulasan'] = [
                    'jumlah' => (int) ($ringkasan['jumlah_ulasan'] ?? 0),
                    'rata_rata_bintang' => isset($ringkasan['rata_rata_bintang'])
                        ? (float) $ringkasan['rata_rata_bintang']
                        : null,
                ];
                $fallbackDetail[] = $detail['ulasan']['jumlah'] > 0
                    ? $tempatTerkait['nama'].' memiliki '.$detail['ulasan']['jumlah'].' ulasan publik'.($detail['ulasan']['rata_rata_bintang'] === null ? '.' : ' dengan rata-rata '.number_format($detail['ulasan']['rata_rata_bintang'], 1, ',', '.').'/5.')
                    : 'Belum ada ulasan publik yang tercatat untuk '.$tempatTerkait['nama'].'.';
            }
            if ($mintaSuka) {
                $detail['jumlah_suka_db2'] = Penyimpanan::ringkasanSukaTempat()[$tempatTerkait['slug']] ?? 0;
                $fallbackDetail[] = $tempatTerkait['nama'].' mendapat '.$detail['jumlah_suka_db2'].' suka yang tercatat di DB 2.';
            }

            return [
                'teks' => [$this->jawabAiAtauFallback(
                    $pertanyaan,
                    'AllegroGraph RDF untuk tempat, PostgreSQL untuk ulasan, dan DB 2 untuk suka',
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
                    'rata_rata_bintang' => $baris['rata_rata_bintang'] === null
                        ? null
                        : (float) $baris['rata_rata_bintang'],
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
            $jumlahSukaTempat = Penyimpanan::ringkasanSukaTempat();
            $peringkatSuka = [];
            foreach ($semuaTempat as $tempat) {
                $jumlah = $jumlahSukaTempat[$tempat['slug']] ?? 0;
                if ($jumlah > 0 && $sesuaiKategori($tempat)) {
                    $peringkatSuka[] = ['nama' => $tempat['nama'], 'jumlah_suka' => $jumlah];
                }
            }
            usort($peringkatSuka, fn (array $a, array $b) => $b['jumlah_suka'] <=> $a['jumlah_suka']);
            $peringkatSuka = array_slice($peringkatSuka, 0, 5);
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

    private function jawabSemantik(string $pertanyaan, array $riwayat): array
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

        if (preg_match('/\b(dekat|terdekat|sekitar)\b/u', $teksPertanyaan)) {
            return $this->jawabTerdekat($pertanyaan, $kategori, $riwayat);
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

    private function jawabTerdekat(string $pertanyaan, string|array|null $kategori, array $riwayat): array
    {
        $entitas = Katalog::tempat();
        $teks = mb_strtolower($pertanyaan);
        $asal = null;
        foreach ($entitas as $tempat) {
            $nama = mb_strtolower($tempat['nama']);
            if (str_contains($teks, $nama) && (! $asal || mb_strlen($tempat['nama']) > mb_strlen($asal['nama']))) {
                $asal = $tempat;
            }
        }

        if ($asal === null) {
            return ['teks' => ['Sebutkan nama tempat atau landmark sebagai titik awal agar Mas Nonov dapat menghitung jarak.'], 'tempat' => []];
        }
        if (empty($asal['lat']) || empty($asal['lng'])) {
            return ['teks' => ['Koordinat titik awal belum tersedia di katalog, jadi jarak belum bisa dihitung.'], 'tempat' => []];
        }

        $kandidat = array_filter($entitas, fn (array $tempat) =>
            $tempat['slug'] !== $asal['slug']
            && ($kategori === null || in_array($tempat['kategori'] ?? null, (array) $kategori, true))
            && ! empty($tempat['lat'])
            && ! empty($tempat['lng'])
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
            'dasar_jarak' => 'perhitungan garis lurus dari koordinat pada katalog RDF',
            'hasil' => array_map(fn (array $item) => ['nama' => $item['tempat']['nama'], 'jarak_meter' => $item['jarak']], $terdekat),
        ];
        $fallback = $kartu
            ? 'Berdasarkan koordinat pada katalog RDF, tempat terdekat dari '.$asal['nama'].' adalah '.$kartu[0]['nama'].', sekitar '.$terdekat[0]['jarak'].' meter dalam garis lurus.'
            : 'Belum ada tempat lain dengan koordinat yang tersedia untuk dibandingkan.';

        return [
            'teks' => [$this->jawabAiAtauFallback($pertanyaan, 'AllegroGraph; jarak garis lurus dihitung dari koordinat RDF', $konteks, $riwayat, $fallback)],
            'tempat' => $kartu,
        ];
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

        return $this->ai->jawabBerbasisKonteks($pertanyaan, $sumber, $konteks, $riwayat);
    }

    private function filterPertanyaan(string $pertanyaan): array
    {
        $teks = mb_strtolower($pertanyaan);
        $kategori = [];
        foreach ([
            'sejarah' => ['sejarah', 'bersejarah', 'historis', 'cagar budaya'],
            'museum' => ['museum'],
            'religi' => ['religi', 'ibadah', 'masjid', 'gereja', 'vihara', 'kuil', 'kelenteng'],
            'kuliner' => ['kuliner', 'makan', 'restoran', 'rumah makan'],
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
