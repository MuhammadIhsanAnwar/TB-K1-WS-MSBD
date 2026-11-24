<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/** Memeriksa kelengkapan identitas destinasi sebelum data RDF dikirim ke DB 3. */
class ValidasiDataWisataRdf extends Command
{
    protected $signature = 'db3:validasi {--expect=198 : Jumlah tempat wisata yang diharapkan} {--ringkas : Hanya tampilkan ringkasan}';

    protected $description = 'Validasi identitas lengkap tempat wisata pada medanvisit-data.ttl';

    private const JENIS_TEMPAT = [
        'mv:SitusSejarah', 'mv:Museum', 'mv:RumahIbadah', 'mv:TempatKuliner',
        'mv:RuangPublik', 'mv:PusatBelanja', 'mv:RekreasiAir',
    ];

    public function handle(): int
    {
        $path = database_path('rdf sparql/medanvisit-data.ttl');
        if (! is_file($path)) {
            $this->error('Berkas RDF tidak ditemukan: '.$path);

            return self::FAILURE;
        }

        $ttl = (string) file_get_contents($path);
        $places = $this->places($ttl);
        $expected = max(0, (int) $this->option('expect'));
        $issues = [];

        foreach ($places as $slug => $place) {
            $missing = [];
            foreach (['nama', 'deskripsi', 'alamat', 'lat', 'lng', 'jam', 'gratis', 'foto', 'sumber', 'kecamatan'] as $field) {
                if (! $place[$field]) {
                    $missing[] = $field;
                }
            }
            if ($place['jumlah_tiket'] > 1) {
                $missing[] = 'lebih dari satu tarif tiket';
            }
            if ($place['harga_contoh']) {
                $missing[] = 'harga tiket contoh';
            }
            if ($missing !== []) {
                $issues[$slug] = $missing;
            }
        }

        $this->table(['Pemeriksaan', 'Hasil'], [
            ['Jumlah tempat di TTL', count($places)],
            ['Jumlah yang diharapkan', $expected],
            ['Data bermasalah', count($issues)],
        ]);

        if ($issues !== []) {
            $ringkasan = [];
            foreach ($issues as $missing) {
                foreach ($missing as $field) {
                    $ringkasan[$field] = ($ringkasan[$field] ?? 0) + 1;
                }
            }
            ksort($ringkasan);
            $this->table(['Masalah data', 'Jumlah tempat'], collect($ringkasan)
                ->map(fn (int $count, string $field): array => [$field, $count])
                ->values()
                ->all());
        }

        if (! $this->option('ringkas') && $issues !== []) {
            $this->newLine();
            $this->warn('Tempat dengan data yang perlu diperbaiki:');
            foreach ($issues as $slug => $missing) {
                $this->line("- {$slug}: ".implode(', ', $missing));
            }
        }

        if (count($places) !== $expected || $issues !== []) {
            $this->error('Validasi gagal. Lengkapi TTL sebelum dimigrasikan ke Database 3.');

            return self::FAILURE;
        }

        $this->info('Validasi berhasil: identitas wajib lengkap, tanpa tarif tiket ganda atau harga contoh.');

        return self::SUCCESS;
    }

    /** @return array<string, array<string, bool>> */
    private function places(string $ttl): array
    {
        $places = [];
        preg_match_all('/(?ms)^res:([a-z0-9-]+)\s+a\s+([^;]+);(.*?)(?=^res:|\z)/', $ttl, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            [$all, $slug, $type, $body] = $match;
            if (! in_array(trim($type), self::JENIS_TEMPAT, true)) {
                continue;
            }
            preg_match('/schema:image\s+<https?:\/\/[^>]+\/storage\/foto_wisata\/([^>]+)>/u', $all, $image);
            $fileFoto = $image[1] ?? null;
            $places[$slug] = [
                'nama' => preg_match('/schema:name\s+"/u', $all) === 1,
                'deskripsi' => preg_match('/schema:description\s+"/u', $all) === 1,
                'alamat' => preg_match('/(?:schema:streetAddress|schema:address)\s+(?:\[\s+a\s+schema:PostalAddress\s*;\s*)?"/u', $all) === 1,
                'lat' => preg_match('/(?:geo:lat|schema:latitude)\s+"/u', $all) === 1,
                'lng' => preg_match('/(?:geo:long|schema:longitude)\s+"/u', $all) === 1,
                'jam' => preg_match('/(?:mv:keteranganJam|schema:openingHours)\s+"/u', $all) === 1,
                'gratis' => preg_match('/schema:isAccessibleForFree\s+(?:true|false)/u', $all) === 1,
                'foto' => $fileFoto !== null && is_file(public_path('storage/foto_wisata/'.basename($fileFoto))),
                'sumber' => preg_match('/(?:dcterms:source|prov:wasDerivedFrom)\s+</u', $all) === 1,
                // Relasi kecamatan didefinisikan setelah blok ABox tempat.
                'kecamatan' => preg_match('/^res:'.preg_quote($slug, '/').'\s+mv:beradaDi\s+res:[a-z0-9-]+\s*\./mu', $ttl) === 1,
                'jumlah_tiket' => preg_match_all('/schema:offers\s+\[/u', $all),
                'harga_contoh' => preg_match('/mv:hargaContoh\s+true/u', $all) === 1,
            ];
        }

        return $places;
    }
}
