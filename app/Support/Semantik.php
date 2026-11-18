<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;

/** Query SPARQL dan keluaran JSON-LD yang dibangun dari data Database 3. */
class Semantik
{
    public const BASIS = 'https://medanvisit.neoverse.my.id/';

    /** Jalankan query hanya di Database 3; salinan lokal tidak digunakan sebagai sumber jawaban. */
    public static function jalankan(string $query): array
    {
        if (! SparqlService::endpoint()) {
            return ['ok' => false, 'pesan' => 'Endpoint SPARQL Database 3 belum diatur.'];
        }

        try {
            return SparqlService::jalankan($query);
        } catch (ConnectionException $e) {
            return ['ok' => false, 'pesan' => 'Database 3 tidak bisa dihubungi: '.$e->getMessage()];
        }
    }

    public static function jsonld(): array
    {
        $graph = [];
        foreach (Katalog::tempat() as $slug => $t) {
            $n = [
                '@type' => 'TouristAttraction',
                '@id' => self::BASIS.'id/'.$slug,
                'name' => $t['nama'],
                'description' => $t['ringkas'],
                'address' => ['@type' => 'PostalAddress', 'streetAddress' => $t['alamat'], 'addressLocality' => 'Medan', 'addressRegion' => 'Sumatera Utara', 'addressCountry' => 'ID'],
                'geo' => ['@type' => 'GeoCoordinates', 'latitude' => $t['lat'], 'longitude' => $t['lng']],
                'isAccessibleForFree' => empty($t['tiket']),
            ];
            if (! empty($t['tiket'])) {
                $n['offers'] = array_map(fn ($jenis, $harga) => ['@type' => 'Offer', 'name' => $jenis, 'price' => $harga, 'priceCurrency' => 'IDR'], array_keys($t['tiket']), $t['tiket']);
            }
            $graph[] = $n;
        }

        return ['@context' => 'https://schema.org', '@graph' => $graph];
    }

    /** JSON-LD satu tempat, untuk disematkan di halaman detail. */
    /**
     * JSON-LD schema.org satu tempat. Rating (dari ulasan di DB 2) ditambahkan sebagai
     * schema:aggregateRating supaya mesin pencari ikut membaca penilaian pengunjung.
     */
    public static function jsonldTempat(array $t, ?array $rating = null): array
    {
        foreach (self::jsonld()['@graph'] as $n) {
            if ($n['@id'] === self::BASIS.'id/'.$t['slug']) {
                if ($rating && ($rating['jumlah'] ?? 0) > 0) {
                    $n['aggregateRating'] = [
                        '@type' => 'AggregateRating',
                        'ratingValue' => round((float) $rating['rata'], 1),
                        'reviewCount' => (int) $rating['jumlah'],
                        'bestRating' => 5,
                        'worstRating' => 1,
                return ['@context' => 'https://schema.org'] + $n;
            }
        }

        return [];
    }
}
