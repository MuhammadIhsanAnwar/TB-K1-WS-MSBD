<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class KirimRdf extends Command
{
    protected $signature = 'rdf:kirim {--replace : Hapus seluruh graph Database 3 sebelum mengirim data}';

    protected $description = 'Kirim data wisata RDF ke Database 3 SPARQL';

    public function handle(): int
    {
        $endpoint = (string) config('services.sparql.statements_endpoint');
        $username = config('services.sparql.username');
        $password = config('services.sparql.password');
        $berkas = [
            database_path('rdf sparql/medanvisit-ontologi.ttl'),
            database_path('rdf sparql/medanvisit-data.ttl'),
        ];
        $authUsername = is_string($username) && trim($username) !== '' ? $username : null;
        $authPassword = is_string($password) && trim($password) !== '' ? $password : null;
        $hasUsername = $authUsername !== null;
        $hasPassword = $authPassword !== null;

        if (
            $endpoint === ''
            || (app()->environment('production') && (! $hasUsername || ! $hasPassword))
            || $hasUsername !== $hasPassword
        ) {
            $this->error('Konfigurasi Database 3 belum lengkap.');

            return self::FAILURE;
        }

        foreach ($berkas as $file) {
            if (! is_file($file)) {
                $this->error('File RDF tidak ditemukan: '.basename($file));

                return self::FAILURE;
            }
        }

        $http = Http::timeout((int) config('services.sparql.timeout', 30));
        if ($authUsername !== null && $authPassword !== null) {
            $http = $http->withBasicAuth($authUsername, $authPassword);
        }

        // Simpan salinan lokal sebelum menyentuh Database 3, supaya bisa dikirim ulang kalau gagal.
        $cadangan = database_path('rdf sparql/medanvisit-data.backup-'.date('Ymd-His').'.ttl');
        if (! copy($berkas[1], $cadangan)) {
            $this->error('Gagal membuat cadangan data RDF.');

            return self::FAILURE;
        }

        $this->line('Cadangan: '.basename($cadangan));

        try {
            if ($this->option('replace')) {
                $hapus = $http->delete($endpoint);
                if (! $hapus->successful()) {
                    throw new RuntimeException('Database 3 menolak penghapusan graph lama (HTTP '.$hapus->status().').');
                }
            }

            foreach ($berkas as $file) {
                $jawab = $http->withBody((string) file_get_contents($file), 'text/turtle')->post($endpoint);
                if (! $jawab->successful()) {
                    throw new RuntimeException('Database 3 menolak '.basename($file).' (HTTP '.$jawab->status().'): '.str($jawab->body())->limit(240));
                }
            }
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        Cache::forget('database3.catalog.triples.v3');
        Cache::forget('database3.catalog.triples.v4');
        Cache::forget('database3.catalog.triples.v7');
        Cache::forget('database3.catalog.triples.v8');
        Cache::forget('database3.unavailable');
        $this->info('Ontologi dan data RDF berhasil dikirim ke Database 3.');

        return self::SUCCESS;
    }
}
