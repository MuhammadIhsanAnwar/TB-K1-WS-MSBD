<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use RuntimeException;

/**
 * Tambah tempat wisata dari database/csv/tempat-baru.csv ke medanvisit-data.ttl.
 *
 * Aman dijalankan berulang: tempat yang slug-nya sudah ada di TTL dilewati.
 *
 * Kolom CSV: nama, kategori, alamat, lat, lng, jam, ringkasan, sumber, harga
 */
class ImporTempat extends Command
{
    protected $signature = 'tempat:impor
                            {--berkas=database/csv/tempat-baru.csv : Berkas CSV masukan}
                            {--tulis : Tulis hasil ke medanvisit-data.ttl (tanpa ini hanya pratinjau)}';

    protected $description = 'Tambah tempat wisata dari CSV ke medanvisit-data.ttl';

    /** Kode kategori aplikasi -> kelas RDF. */
    private const KELAS = [
        'sejarah' => 'mv:SitusSejarah',
        'museum' => 'mv:Museum',
        'religi' => 'mv:RumahIbadah',
        'kuliner' => 'mv:TempatKuliner',
        'ruang-publik' => 'mv:RuangPublik',
        'belanja' => 'mv:PusatBelanja',
        'rekreasi-air' => 'mv:RekreasiAir',
    ];

    private const RADIUS = ['religi' => 100, 'kuliner' => 100, 'rekreasi-air' => 200];

    private const DURASI = ['sejarah' => 60, 'museum' => 90, 'religi' => 30, 'kuliner' => 60, 'ruang-publik' => 90, 'belanja' => 60, 'rekreasi-air' => 180];

    public function handle(): int
    {
        $berkas = base_path((string) $this->option('berkas'));
        $ttl = database_path('rdf/medanvisit-data.ttl');

        if (! is_file($berkas)) {
            $this->error('Berkas CSV tidak ditemukan: '.$berkas);

            return self::FAILURE;
        }

        if (! is_file($ttl)) {
            $this->error('Berkas TTL tidak ditemukan: '.$ttl);

            return self::FAILURE;
        }

        $baris = $this->bacaCsv($berkas);
        if ($baris === null) {
            return self::FAILURE;
        }

        $isi = (string) file_get_contents($ttl);

        // Slug yang sudah ada di TTL
        preg_match_all('/^res:([a-z0-9-]+)\s+a\s+mv:/m', $isi, $m);

        $ada = array_flip($m[1]);
        $blok = [];
        $lewat = [];

        foreach ($baris as $r) {
            $slug = $this->slug($r['nama']);
            if (isset($ada[$slug])) {
                $lewat[] = $r['nama'];

                continue;
            }
            try {
                $blok[] = $this->blok($r, $slug);
                $ada[$slug] = true;
            } catch (\InvalidArgumentException $e) {
                $this->error('Lewati "'.$r['nama'].'": '.$e->getMessage());

                return self::FAILURE;
            }
        }

        $this->info('Baris CSV   : '.count($baris));
        $this->info('Ditambahkan : '.count($blok));
        $this->info('Dilewati    : '.count($lewat));

        if ($lewat !== []) {
            $this->line('  sudah ada: '.implode(', ', array_slice($lewat, 0, 10)).(count($lewat) > 10 ? ', ...' : ''));
        }

        if ($blok === []) {
            $this->warn('Tidak ada yang perlu ditulis.');

            return self::SUCCESS;
        }

        if (! $this->option('tulis')) {
            $this->line('');
            $this->line('--- Pratinjau blok pertama ---');
            $this->line($blok[0]);
            $this->line('');
            $this->warn('Belum ditulis. Jalankan dengan --tulis untuk menyimpan.');

            return self::SUCCESS;
        }

        $tambahan = "\n".implode("\n", $blok)."\n";
        file_put_contents($ttl, rtrim($isi)."\n".$tambahan);

        $this->info('Ditulis ke '.$ttl);

        return self::SUCCESS;
    }

    /** @return list<array<string,string>>|null */
    private function bacaCsv(string $berkas): ?array
    {
        $isi = (string) file_get_contents($berkas);
        $isi = preg_replace('/^\xEF\xBB\xBF/', '', $isi);
        $isi = str_replace(["\r\n", "\r"], "\n", $isi);
        $baris = explode("\n", trim($isi));

        $header = str_getcsv((string) array_shift($baris), ',', '"', '');
        $perlu = ['nama', 'kategori', 'alamat', 'lat', 'lng', 'jam', 'ringkasan', 'sumber', 'harga'];

        foreach ($perlu as $k) {
            if (! in_array($k, $header, true)) {
                $this->error('Kolom "'.$k.'" tidak ada di CSV.');

                return null;
            }
        }

        $hasil = [];
        foreach ($baris as $i => $b) {
            if (trim($b) === '') {
                continue;
            }
            $k = str_getcsv($b, ',', '"', '');

            // Jaga-jaga: lat/lng tergabung jadi satu sel "3.5, 98.6"
            if (count($k) === 8) {
                foreach ($k as $j => $v) {
                    if (preg_match('/^\s*(-?\d+\.\d+)\s*,\s*(-?\d+\.\d+)\s*$/', $v, $x)) {
                        array_splice($k, $j, 1, [$x[1], $x[2]]);
                        break;
                    }
                }
            }

            if (count($k) !== count($header)) {
                $this->error('Baris '.($i + 2).' punya '.count($k).' kolom, seharusnya '.count($header).'.');

                return null;
            }

            $hasil[] = array_map('trim', array_combine($header, $k));
        }

        return $hasil;
    }

    /** @param array<string,string> $r */
    private function blok(array $r, string $slug): string
    {
        $kategori = $r['kategori'];
        if (! isset(self::KELAS[$kategori])) {
            throw new \InvalidArgumentException('kategori "'.$kategori.'" tidak dikenal.');
        }

        if (! is_numeric($r['lat']) || ! is_numeric($r['lng'])) {
            throw new \InvalidArgumentException('koordinat bukan angka.');
        }

        $nama = $r['nama'];
        $ringkas = $r['ringkasan'];
        $gratis = $r['harga'] === '' || (int) $r['harga'] === 0;

        $baris = [
            'res:'.$slug.' a '.self::KELAS[$kategori].' ;',
            '    rdfs:label '.$this->literal($nama).' ;',
            '    schema:name '.$this->literal($nama).' ;',
            '    schema:description '.$this->literal($ringkas).' ;',
            '    mv:cerita '.$this->literal($ringkas).' ;',
            $this->alamat($r['alamat']),
            '    geo:lat "'.$this->angka($r['lat']).'"^^xsd:decimal ;',
            '    geo:long "'.$this->angka($r['lng']).'"^^xsd:decimal ;',
            '    mv:koordinatPerkiraan false ;',
            '    mv:keteranganJam '.$this->literal($r['jam']).' ;',
            '    mv:jamContoh false ;',
            '    mv:radiusCheckin '.(self::RADIUS[$kategori] ?? 120).' ;',
            '    mv:durasiSinggah '.(self::DURASI[$kategori] ?? 60).' ;',
            '    schema:isAccessibleForFree '.($gratis ? 'true' : 'false').' ;',
        ];

        $rentang = $this->rentangJam($r['jam']);
        if ($rentang !== null) {
            $baris[] = '    mv:jamBuka "'.$rentang[0].'"^^xsd:time ;';
            $baris[] = '    mv:jamTutup "'.$rentang[1].'"^^xsd:time ;';
        }

        if ($r['sumber'] !== '') {
            $baris[] = '    dcterms:source '.$this->sumber($r['sumber']).' ;';
        }

        if (! $gratis) {
            $baris[] = '    mv:hargaTiketMulai '.(int) $r['harga'].' ;';
            $baris[] = '    mv:hargaContoh false ;';
        }

        $last = array_pop($baris);
        $last = rtrim(rtrim($last, ' ;'));

        return implode("\n", $baris)."\n    ".ltrim($last)." .\n";
    }

    private function alamat(string $alamat): string
    {
        return '    schema:address [ a schema:PostalAddress ; schema:streetAddress '.$this->literal($alamat)
            .' ; schema:addressLocality "Medan" ; schema:addressRegion "Sumatera Utara" ; schema:addressCountry "ID" ] ;';
    }

    /** "Setiap hari, 09.00–17.00" -> ["09:00:00", "17:00:00"] */
    private function rentangJam(string $jam): ?array
    {
        if (! preg_match('/(\d{1,2})[.:](\d{2})\s*[–\-]\s*(\d{1,2})[.:](\d{2})/u', $jam, $m)) {
            return null;
        }

        return [
            sprintf('%02d:%s:00', (int) $m[1], $m[2]),
            sprintf('%02d:%s:00', (int) $m[3], $m[4]),
        ];
    }

    private function slug(string $nama): string
    {
        $s = mb_strtolower(trim($nama));
        $s = preg_replace('/[^a-z0-9]+/', '-', $s);

        return trim((string) $s, '-');
    }

    private function literal(string $v): string
    {
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).'@id';
    }

    private function sumber(string $v): string
    {
        $scheme = parse_url($v, PHP_URL_SCHEME);

        return filter_var($v, FILTER_VALIDATE_URL) && in_array(strtolower((string) $scheme), ['http', 'https'], true)
            ? '<'.$v.'>'
            : $this->literal($v);
    }

    private function angka(string|int|float $v): string
    {
        return rtrim(rtrim(number_format((float) $v, 7, '.', ''), '0'), '.');
    }
}
