<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Mengunduh foto berlisensi bebas dari Wikimedia Commons untuk tempat yang belum
 * mempunyai schema:image. Kandidat yang tidak cukup cocok sengaja dilewati.
 */
class UnduhFotoWisata extends Command
{
    protected $signature = 'tempat:unduh-foto
                            {--limit=0 : Maksimum tempat yang diproses (0 = semua)}
                            {--dry-run : Tampilkan kandidat tanpa menyimpan apa pun}';

    protected $description = 'Unduh foto Wikimedia Commons untuk tempat wisata yang belum memiliki foto RDF';

    private const BASE_URL = 'https://medanvisit.neoverse.my.id/storage/foto_wisata/';

    public function handle(): int
    {
        $ttlPath = database_path('rdf sparql/medanvisit-data.ttl');
        $folder = public_path('storage/foto_wisata');
        $ttl = (string) file_get_contents($ttlPath);
        $missing = $this->placesWithoutImage($ttl);
        $limit = max(0, (int) $this->option('limit'));

        if ($limit > 0) {
            $missing = array_slice($missing, 0, $limit, true);
        }

        if (! is_dir($folder) && ! $this->option('dry-run')) {
            mkdir($folder, 0755, true);
        }

        $creditsPath = $folder.'/credits.json';
        $credits = is_file($creditsPath) ? json_decode((string) file_get_contents($creditsPath), true) : [];
        $credits = is_array($credits) ? $credits : [];
        $done = 0;
        $skipped = [];

        foreach ($missing as $slug => $name) {
            try {
                $candidate = $this->candidate($name);
            } catch (\Throwable) {
                $skipped[] = $slug.' (pencarian Commons gagal/timeout)';
                continue;
            }
            if ($candidate === null) {
                $skipped[] = $slug.' (tidak ada kandidat Commons yang cocok)';
                continue;
            }

            $filename = $slug.'.'.$candidate['extension'];
            $this->line($slug.' ← '.$candidate['title']);

            if (! $this->option('dry-run')) {
                $response = Http::withUserAgent('MedanVisit/1.0 (tourism dataset; contact: admin@medanvisit.neoverse.my.id)')
                    ->connectTimeout(30)
                    ->timeout(60)
                    ->get($candidate['url']);
                if (! $response->successful() || ! str_starts_with((string) $response->header('Content-Type'), 'image/')) {
                    $skipped[] = $slug.' (unduhan gambar gagal)';
                    continue;
                }
                file_put_contents($folder.'/'.$filename, $response->body());
                $ttl = $this->insertImage($ttl, $slug, $filename);
                $credits[] = [
                    'file' => $filename,
                    'title' => $candidate['title'],
                    'creator' => $candidate['creator'],
                    'source' => $candidate['page'],
                    'license' => $candidate['license'],
                ];
                // Simpan per foto agar timeout layanan eksternal tidak menghilangkan
                // hasil yang sudah berhasil diunduh.
                file_put_contents($ttlPath, $ttl);
                file_put_contents($creditsPath, json_encode($credits, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
            }
            $done++;
        }

        if (! $this->option('dry-run')) {
            file_put_contents($ttlPath, $ttl);
            file_put_contents($creditsPath, json_encode($credits, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
        }

        $this->info("Berhasil dipasang: {$done}");
        $this->warn('Dilewati: '.count($skipped));
        foreach ($skipped as $reason) {
            $this->line('  - '.$reason);
        }

        return self::SUCCESS;
    }

    /** @return array<string, string> slug => nama */
    private function placesWithoutImage(string $ttl): array
    {
        preg_match_all('/(?ms)^res:([a-z0-9-]+)\s+a\s+mv:(?:SitusSejarah|Museum|RumahIbadah|TempatKuliner|RuangPublik|PusatBelanja|RekreasiAir)\s*;(.*?)(?=^res:|\z)/', $ttl, $blocks, PREG_SET_ORDER);
        $places = [];
        foreach ($blocks as $block) {
            if (str_contains($block[0], 'schema:image')) {
                continue;
            }
            if (preg_match('/schema:name\s+"([^"]+)"/u', $block[0], $name)) {
                $places[$block[1]] = $name[1];
            }
        }

        return $places;
    }

    /** @return array{title:string,url:string,page:string,extension:string,creator:string,license:string}|null */
    private function candidate(string $name): ?array
    {
        try {
            $commons = $this->commonsCandidate($name);
            if ($commons !== null) {
                return $commons;
            }
        } catch (\Throwable) {
            // Lanjutkan ke indeks Creative Commons lain saat Commons timeout.
        }

        return $this->openverseCandidate($name);
    }

    /** @return array{title:string,url:string,page:string,extension:string,creator:string,license:string}|null */
    private function commonsCandidate(string $name): ?array
    {
        $response = Http::acceptJson()
            ->withUserAgent('MedanVisit/1.0 (tourism dataset; contact: admin@medanvisit.neoverse.my.id)')
            ->connectTimeout(30)
            ->timeout(30)
            ->get('https://commons.wikimedia.org/w/api.php', [
                'action' => 'query',
                'format' => 'json',
                'generator' => 'search',
                'gsrnamespace' => 6,
                'gsrlimit' => 10,
                'gsrsearch' => $name.' Medan',
                'prop' => 'imageinfo',
                'iiprop' => 'url|extmetadata',
                'iiurlwidth' => 1600,
            ]);

        if (! $response->successful()) {
            return null;
        }

        $best = null;
        foreach ((array) data_get($response->json(), 'query.pages', []) as $page) {
            $info = data_get($page, 'imageinfo.0');
            if (! is_array($info) || ! isset($info['thumburl'], $page['title'])) {
                continue;
            }
            $metadata = (array) ($info['extmetadata'] ?? []);
            $license = strip_tags((string) data_get($metadata, 'LicenseShortName.value', ''));
            if (! preg_match('/CC|Public domain|PD/i', $license)) {
                continue;
            }
            $score = $this->score($name, (string) $page['title']);
            if ($score < $this->minimumScore($name) || ($best !== null && $score <= $best['score'])) {
                continue;
            }
            $extension = strtolower(pathinfo((string) parse_url($info['thumburl'], PHP_URL_PATH), PATHINFO_EXTENSION));
            if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                continue;
            }
            $best = [
                'score' => $score,
                'title' => preg_replace('/^File:/', '', (string) $page['title']),
                'url' => (string) $info['thumburl'],
                'page' => 'https://commons.wikimedia.org/wiki/'.rawurlencode(str_replace(' ', '_', (string) $page['title'])),
                'extension' => $extension === 'jpeg' ? 'jpg' : $extension,
                'creator' => trim(strip_tags((string) data_get($metadata, 'Artist.value', ''))),
                'license' => $license,
            ];
        }

        return $best === null ? null : collect($best)->except('score')->all();
    }

    /** @return array{title:string,url:string,page:string,extension:string,creator:string,license:string}|null */
    private function openverseCandidate(string $name): ?array
    {
        $response = Http::acceptJson()
            ->withUserAgent('MedanVisit/1.0 (tourism dataset; contact: admin@medanvisit.neoverse.my.id)')
            ->connectTimeout(30)
            ->timeout(30)
            ->get('https://api.openverse.org/v1/images', [
                'q' => $name.' Medan',
                'page_size' => 20,
            ]);

        if (! $response->successful()) {
            return null;
        }

        $best = null;
        foreach ((array) $response->json('results', []) as $image) {
            $license = strtolower((string) ($image['license'] ?? ''));
            if (! in_array($license, ['by', 'by-sa', 'zero', 'pdm'], true)) {
                continue;
            }
            $score = $this->score($name, (string) ($image['title'] ?? ''));
            if ($score < $this->minimumScore($name) || ($best !== null && $score <= $best['score'])) {
                continue;
            }
            $extension = strtolower((string) ($image['filetype'] ?? 'jpg'));
            if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true) || empty($image['url'])) {
                continue;
            }
            $best = [
                'score' => $score,
                'title' => (string) $image['title'],
                'url' => (string) $image['url'],
                'page' => (string) ($image['foreign_landing_url'] ?? $image['detail_url']),
                'extension' => $extension === 'jpeg' ? 'jpg' : $extension,
                'creator' => (string) ($image['creator'] ?? ''),
                'license' => strtoupper($license).(empty($image['license_version']) ? '' : ' '.$image['license_version']),
            ];
        }

        return $best === null ? null : collect($best)->except('score')->all();
    }

    private function score(string $name, string $title): int
    {
        $normalise = static fn (string $value): array => array_filter(explode(' ', preg_replace('/[^a-z0-9]+/i', ' ', strtolower($value))));
        $ignored = ['medan', 'gereja', 'masjid', 'vihara', 'kuil', 'katolik', 'paroki', 'rumah', 'ibadah', 'the'];
        $needles = array_diff($normalise($name), $ignored);
        $haystack = $normalise($title);

        return count(array_intersect($needles, $haystack));
    }

    private function minimumScore(string $name): int
    {
        $words = preg_split('/[^a-z0-9]+/i', strtolower($name), -1, PREG_SPLIT_NO_EMPTY);
        $ignored = ['medan', 'gereja', 'masjid', 'vihara', 'kuil', 'katolik', 'paroki', 'rumah', 'ibadah', 'the'];
        $distinctive = array_diff($words ?: [], $ignored);

        return count($distinctive) <= 1 ? 1 : 2;
    }

    private function insertImage(string $ttl, string $slug, string $filename): string
    {
        $url = self::BASE_URL.$filename;
        $pattern = '/^(res:'.preg_quote($slug, '/').'\s+a\s+mv:(?:(?!\nres:).)*?)(\n\s*\.)/ms';
        $updated = (string) preg_replace($pattern, '1 ;'.PHP_EOL.'    schema:image <'.$url.'>2', $ttl, 1);
        if ($updated !== $ttl) {
            return $updated;
        }

        // Sebagian blok mengakhiri pernyataan terakhir dengan " ." pada baris
        // yang sama, bukan dengan titik pada baris tersendiri.
        $pattern = '/^(res:'.preg_quote($slug, '/').'\s+a\s+mv:(?:(?!\nres:).)*?)(\s*\.\s*)$/ms';

        return (string) preg_replace($pattern, '1 ;'.PHP_EOL.'    schema:image <'.$url.'>2', $ttl, 1);
    }
}
