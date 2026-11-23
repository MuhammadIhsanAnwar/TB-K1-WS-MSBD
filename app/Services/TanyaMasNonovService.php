<?php

namespace App\Services;

use App\Support\Katalog;
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

        if (preg_match('/\b(wisata|tempat|taman|museum|sejarah|religi|masjid|gereja|vihara|kuil|kuliner|kecamatan|fasilitas|terdekat|dekat|kota medan|statistik|jumlah wisata)\b/u', $teks)) {
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

        return ['teks' => [$this->jawabAiAtauFallback($pertanyaan, 'PostgreSQL: pesanan milik pengguna terautentikasi', $konteks, [], $fallback)], 'tempat' => []];
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
            'teks' => [$this->jawabAiAtauFallback($pertanyaan, 'PostgreSQL dan AllegroGraph; rekomendasi berdasarkan riwayat pesanan', $konteks, [], $fallback)],
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
