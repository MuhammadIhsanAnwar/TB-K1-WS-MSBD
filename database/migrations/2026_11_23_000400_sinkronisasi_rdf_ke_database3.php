<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Mengirim ontologi dan dataset sumber ke Database 3 (AllegroGraph/SPARQL).
 *
 * Migration ini memakai tabel `migrations` DB 1 sebagai penanda bahwa versi
 * RDF telah dikirim. Setiap perubahan TTL berikutnya memerlukan migration baru
 * atau perintah `php artisan db3:migrate` untuk sinkronisasi ulang.
 */
return new class extends Migration
{
    protected $connection = 'katalog';

    public function up(): void
    {
        $endpoint = (string) config('services.sparql.statements_endpoint');
        $username = config('services.sparql.username');
        $password = config('services.sparql.password');
        $files = [
            database_path('rdf/medanvisit-ontologi.ttl'),
            database_path('rdf/medanvisit-data.ttl'),
        ];

        if ($endpoint === '' || ! filled($username) || ! filled($password)) {
            throw new \RuntimeException('Konfigurasi SPARQL Database 3 belum lengkap.');
        }
        foreach ($files as $file) {
            if (! is_file($file)) {
                throw new \RuntimeException('Berkas RDF tidak ditemukan: '.basename($file));
            }
        }

        $http = Http::timeout((int) config('services.sparql.timeout', 60))
            ->withBasicAuth((string) $username, (string) $password);

        // Dataset adalah salinan penuh sumber repo; kosongkan terlebih dahulu agar
        // triple lama yang telah dihapus dari TTL tidak tertinggal di DB3.
        $hapus = $http->delete($endpoint);
        if (! $hapus->successful()) {
            throw new \RuntimeException('Database 3 menolak penghapusan graph lama (HTTP '.$hapus->status().').');
        }

        foreach ($files as $file) {
            $response = $http
                ->withBody((string) file_get_contents($file), 'text/turtle')
                ->post($endpoint);
            if (! $response->successful()) {
                throw new \RuntimeException('Database 3 menolak '.basename($file).' (HTTP '.$response->status().'): '.str($response->body())->limit(240));
            }
        }

        Cache::forget('database3.catalog.triples.v7');
        Cache::forget('database3.unavailable');
    }

    /** Data RDF tidak dihapus saat rollback agar rollback DB1 tidak mengosongkan DB3. */
    public function down(): void
    {
        // no-op
    }
};
