<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Mengirim query SPARQL 1.1 ke Database 3 lewat protokol SPARQL W3C
 * dan mengubah hasilnya ke bentuk tabel yang dipakai halaman /data: ['ok', 'kolom', 'baris', 'catatan'].
 */
class SparqlService
{
    private const BATAS_BARIS = 2000;
    private const KELAS_WISATA = [
        'mv:SitusSejarah',
        'mv:Museum',
        'mv:RumahIbadah',
        'mv:TempatKuliner',
        'mv:RuangPublik',
        'mv:PusatBelanja',
        'mv:RekreasiAir',
    ];
    private const KATEGORI_KELAS = [
        'sejarah' => ['mv:SitusSejarah', 'mv:Museum'],
        'museum' => ['mv:Museum'],
        'religi' => ['mv:RumahIbadah'],
        'kuliner' => ['mv:TempatKuliner'],
        'ruang-publik' => ['mv:RuangPublik'],
        'belanja' => ['mv:PusatBelanja'],
        'rekreasi-air' => ['mv:RekreasiAir'],
    ];

    /** IRI panjang disingkat dengan prefix yang sama seperti di file Turtle */
    private const PREFIX = [
        'https://medanvisit.neoverse.my.id/id/' => 'res:',
        'https://medanvisit.neoverse.my.id/ontologi#' => 'mv:',
        'https://schema.org/' => 'schema:',
        'http://www.wikidata.org/entity/' => 'wd:',
        'http://www.w3.org/1999/02/22-rdf-syntax-ns#' => 'rdf:',
        'http://www.w3.org/2000/01/rdf-schema#' => 'rdfs:',
        'http://www.w3.org/2002/07/owl#' => 'owl:',
        'http://www.w3.org/2003/01/geo/wgs84_pos#' => 'geo:',
        'http://purl.org/dc/terms/' => 'dcterms:',
    ];

    public static function endpoint(): ?string
    {
        return config('services.sparql.endpoint') ?: null;
    }

    public static function perbarui(string $update): void
    {
        $endpoint = self::endpoint();
        if ($endpoint === null) {
            throw new \RuntimeException('SPARQL_ENDPOINT belum dikonfigurasi.');
        }

        $http = Http::timeout((int) config('services.sparql.timeout', 30));
        $username = config('services.sparql.username');
        $password = config('services.sparql.password');
        $authUsername = is_string($username) && trim($username) !== '' ? $username : null;
        $authPassword = is_string($password) && trim($password) !== '' ? $password : null;
        $hasUsername = $authUsername !== null;
        $hasPassword = $authPassword !== null;
        if (! $hasUsername || ! $hasPassword) {
            if (app()->environment('production') || $hasUsername !== $hasPassword) {
                throw new \RuntimeException('SPARQL_USERNAME dan SPARQL_PASSWORD wajib dikonfigurasi untuk operasi tulis Database 3.');
            }
        }
        if ($authUsername !== null && $authPassword !== null) {
            $http = $http->withBasicAuth($authUsername, $authPassword);
        }

        $response = $http->asForm()->post($endpoint, ['update' => $update]);
        if (! $response->successful()) {
            throw new \RuntimeException('Database 3 menolak perubahan RDF (HTTP '.$response->status().').');
        }
    }

    private static function singkat(string $iri): string
    {
        foreach (self::PREFIX as $ns => $p) {
            if (str_starts_with($iri, $ns)) {
                return $p.substr($iri, strlen($ns));
            }
        }

        return $iri;
    }

    private static function nilai(array $b): string|int|float
    {
        if ($b['type'] === 'uri') {
            return self::singkat($b['value']);
        }
        $tipe = $b['datatype'] ?? '';
        if (preg_match('/#(integer|int|long|short|positiveInteger|nonNegativeInteger)$/', $tipe)) {
            return (int) $b['value'];
        }
        if (preg_match('/#(decimal|double|float)$/', $tipe)) {
            return (float) $b['value'];
        }

        return $b['value'];
    }

    private static function literal(string $nilai): string
    {
        $nilai = mb_substr($nilai, 0, 120);

        return '"'.addcslashes($nilai, "\\\"\n\r\t").'"';
    }

    private static function kelasUntukKategori(string|array|null $kategori): array
    {
        if ($kategori === null) {
            return self::KELAS_WISATA;
        }

        $kategori = is_array($kategori) ? $kategori : [$kategori];
        $kelas = [];
        foreach ($kategori as $item) {
            if (! isset(self::KATEGORI_KELAS[$item])) {
                throw new \InvalidArgumentException('Kategori wisata tidak dikenal.');
            }
            $kelas = array_merge($kelas, self::KATEGORI_KELAS[$item]);
        }

        return array_values(array_unique($kelas));
    }

    /**
     * Pencarian wisata dengan kelas dan predikat yang ada di medanvisit-data.ttl.
     * Seluruh bagian query yang berasal dari pengguna hanya dapat menjadi literal.
     */
    public static function cariTempat(
        ?string $kataKunci = null,
        string|array|null $kategori = null,
        ?string $kecamatan = null,
        ?string $fasilitas = null,
        int $batas = 8,
        int $offset = 0,
    ): array {
        $kelas = self::kelasUntukKategori($kategori);
        $nilaiKelas = implode(' ', $kelas);
        $filter = '';

        if ($kataKunci !== null && trim($kataKunci) !== '') {
            $literal = self::literal(mb_strtolower(trim($kataKunci)));
            $frasa = mb_strtolower(trim($kataKunci));
            $frasa = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $frasa);
            $frasa = trim(preg_replace('/\s+/u', ' ', $frasa));
            $regex = self::literal('.*'.str_replace(' ', '.*', $frasa).'.*');
            $filter .= "\nFILTER (CONTAINS(LCASE(STR(?nama)), {$literal}) || REGEX(LCASE(STR(?nama)), {$regex}))";
        }
        if ($kecamatan !== null && trim($kecamatan) !== '') {
            $literal = self::literal(mb_strtolower(trim($kecamatan)));
            $filter .= "\nFILTER EXISTS { ?tempat mv:beradaDi ?wilayahFilter . ?wilayahFilter schema:name ?namaWilayah . FILTER(CONTAINS(LCASE(STR(?namaWilayah)), {$literal})) }";
        }
        if ($fasilitas !== null && trim($fasilitas) !== '') {
            $literal = self::literal(mb_strtolower(trim($fasilitas)));
            $filter .= "\nFILTER EXISTS { ?tempat mv:fasilitas ?nilaiFasilitas . FILTER(CONTAINS(LCASE(STR(?nilaiFasilitas)), {$literal})) }";
        }

        $batas = max(1, min($batas, 50));
        $offset = max(0, min($offset, 500));
        $query = <<<SPARQL
            PREFIX mv: <https://medanvisit.neoverse.my.id/ontologi#>
            PREFIX schema: <https://schema.org/>
            PREFIX geo: <http://www.w3.org/2003/01/geo/wgs84_pos#>
            SELECT DISTINCT ?tempat ?kelas ?nama ?deskripsi ?kecamatan ?foto ?lat ?long WHERE {
                VALUES ?kelas { {$nilaiKelas} }
                ?tempat a ?kelas ; schema:name ?nama .
                OPTIONAL { ?tempat schema:description ?deskripsi }
                OPTIONAL { ?tempat mv:beradaDi ?wilayah . ?wilayah schema:name ?kecamatan }
                OPTIONAL { ?tempat schema:image ?foto }
                OPTIONAL { ?tempat geo:lat ?lat }
                OPTIONAL { ?tempat geo:long ?long }
                {$filter}
            }
            ORDER BY LCASE(STR(?nama))
            LIMIT {$batas}
            OFFSET {$offset}
            SPARQL;

        return self::jalankan($query, $batas);
    }

    public static function hitungTempat(string|array|null $kategori = null): array
    {
        $kelas = self::kelasUntukKategori($kategori);
        $nilaiKelas = implode(' ', $kelas);
        $query = <<<SPARQL
            PREFIX mv: <https://medanvisit.neoverse.my.id/ontologi#>
            PREFIX schema: <https://schema.org/>
            SELECT ?kelas (COUNT(DISTINCT ?tempat) AS ?jumlah) WHERE {
                VALUES ?kelas { {$nilaiKelas} }
                ?tempat a ?kelas ; schema:name ?nama .
            }
            GROUP BY ?kelas
            HAVING (COUNT(DISTINCT ?tempat) > 0)
            ORDER BY DESC(?jumlah)
            SPARQL;

        return self::jalankan($query, count($kelas));
    }

    public static function rekomendasikanSatuKecamatan(string $slug, int $batas = 8): array
    {
        $iri = self::iriTempat($slug);
        $kelas = implode(' ', self::KELAS_WISATA);
        $batas = max(1, min($batas, 30));
        $query = <<<SPARQL
            PREFIX mv: <https://medanvisit.neoverse.my.id/ontologi#>
            PREFIX schema: <https://schema.org/>
            PREFIX geo: <http://www.w3.org/2003/01/geo/wgs84_pos#>
            SELECT DISTINCT ?tempat ?kelas ?nama ?kecamatan ?lat ?long ?dasar WHERE {
                <{$iri}> mv:beradaDi ?wilayah .
                VALUES ?kelas { {$kelas} }
                ?tempat mv:beradaDi ?wilayah ; a ?kelas ; schema:name ?nama .
                FILTER (?tempat != <{$iri}>)
                ?wilayah schema:name ?kecamatan .
                OPTIONAL { ?tempat geo:lat ?lat }
                OPTIONAL { ?tempat geo:long ?long }
                BIND("kecamatan yang sama" AS ?dasar)
            }
            ORDER BY LCASE(STR(?nama))
            LIMIT {$batas}
            SPARQL;

        return self::jalankan($query, $batas);
    }

    public static function auditTempatTanpaDeskripsi(int $batas = 50): array
    {
        $kelas = implode(' ', self::KELAS_WISATA);
        $batas = max(1, min($batas, 100));
        $query = <<<SPARQL
            PREFIX mv: <https://medanvisit.neoverse.my.id/ontologi#>
            PREFIX schema: <https://schema.org/>
            SELECT DISTINCT ?tempat ?nama WHERE {
                VALUES ?kelas { {$kelas} }
                ?tempat a ?kelas ; schema:name ?nama .
                FILTER NOT EXISTS { ?tempat schema:description ?deskripsi }
            }
            ORDER BY LCASE(STR(?nama))
            LIMIT {$batas}
            SPARQL;

        return self::jalankan($query, $batas);
    }

    public static function statistikHargaTiket(): array
    {
        $kelas = implode(' ', self::KELAS_WISATA);
        $query = <<<SPARQL
            PREFIX mv: <https://medanvisit.neoverse.my.id/ontologi#>
            PREFIX schema: <https://schema.org/>
            SELECT (COUNT(?harga) AS ?jumlah) (SUM(?harga) AS ?total) (AVG(?harga) AS ?rataRata)
                   (MIN(?harga) AS ?minimum) (MAX(?harga) AS ?maksimum) WHERE {
                VALUES ?kelas { {$kelas} }
                ?tempat a ?kelas ; schema:offers/schema:price ?harga .
            }
            SPARQL;

        return self::jalankan($query, 1);
    }

    public static function adaTempat(string $slug): array
    {
        $iri = self::iriTempat($slug);
        $kelas = implode(' ', self::KELAS_WISATA);
        $query = <<<SPARQL
            PREFIX mv: <https://medanvisit.neoverse.my.id/ontologi#>
            ASK { <{$iri}> a ?kelas . VALUES ?kelas { {$kelas} } }
            SPARQL;

        return self::jalankan($query);
    }

    public static function deskripsikanTempat(string $slug, int $batas = 100): array
    {
        $iri = self::iriTempat($slug);
        $query = <<<SPARQL
            DESCRIBE <{$iri}>
            SPARQL;

        return self::jalankan($query, max(1, min($batas, 200)));
    }

    public static function relasiTempat(string $slug, int $batas = 30): array
    {
        $iri = self::iriTempat($slug);
        $batas = max(1, min($batas, 100));
        $query = <<<SPARQL
            PREFIX mv: <https://medanvisit.neoverse.my.id/ontologi#>
            PREFIX schema: <https://schema.org/>
            SELECT DISTINCT ?tujuan ?nama WHERE {
                <{$iri}> (mv:dekatDengan|mv:beradaDi|mv:bagianDari|mv:berkaitanDengan) ?tujuan .
                ?tujuan schema:name ?nama .
            }
            ORDER BY LCASE(STR(?nama))
            LIMIT {$batas}
            SPARQL;

        return self::jalankan($query, $batas);
    }

    public static function grafikTempat(string $slug, int $batas = 100): array
    {
        $iri = self::iriTempat($slug);
        $batas = max(1, min($batas, 200));
        $query = <<<SPARQL
            CONSTRUCT { ?s ?p ?o } WHERE {
                { BIND(<{$iri}> AS ?s) ?s ?p ?o }
                UNION
                { ?s ?p <{$iri}> . BIND(<{$iri}> AS ?o) }
            }
            LIMIT {$batas}
            SPARQL;

        return self::jalankan($query, $batas);
    }

    private static function iriTempat(string $slug): string
    {
        if (! preg_match('/^[a-z0-9-]{1,80}$/', $slug)) {
            throw new \InvalidArgumentException('Slug tempat wisata tidak valid.');
        }

        return 'https://medanvisit.neoverse.my.id/id/'.$slug;
    }

    /**
     * @throws ConnectionException bila Database 3 tidak bisa dihubungi
     */
    public static function jalankan(string $query, int $batas = self::BATAS_BARIS): array
    {
        $batas = max(1, $batas);
        $jenis = preg_match('/^\s*(?:(?:PREFIX|BASE)\s[^\n]*\n|#[^\n]*\n|\s)*(SELECT|ASK|CONSTRUCT|DESCRIBE)\b/i', $query, $m) ? strtoupper($m[1]) : null;
        if ($jenis === null) {
            return ['ok' => false, 'pesan' => 'Endpoint ini hanya menerima query SELECT, ASK, CONSTRUCT, atau DESCRIBE.'];
        }
        $queryTanpaLiteral = preg_replace('/"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'|<[^>]*>|#[^\r\n]*/s', ' ', $query);
        if (
            ! is_string($queryTanpaLiteral)
            || preg_match('/\b(?:INSERT|DELETE|LOAD|CLEAR|CREATE|DROP|COPY|MOVE|ADD|WITH)\b/i', $queryTanpaLiteral)
        ) {
            return ['ok' => false, 'pesan' => 'Endpoint ini hanya menerima satu query SPARQL baca-saja.'];
        }
        if (self::endpoint() === null) {
            return ['ok' => false, 'pesan' => 'SPARQL_ENDPOINT belum dikonfigurasi.'];
        }

        $grafik = in_array($jenis, ['CONSTRUCT', 'DESCRIBE'], true);
        $http = Http::timeout((int) config('services.sparql.timeout', 30));
        $username = config('services.sparql.username');
        $password = config('services.sparql.password');
        if ($username !== null && $password !== null) {
            $http = $http->withBasicAuth($username, $password);
        }

        $jawab = $http
            ->accept($grafik ? 'application/n-triples' : 'application/sparql-results+json')
            ->asForm()
            ->post(self::endpoint(), ['query' => $query]);

        if (! $jawab->successful()) {
            $pesan = trim(strtok($jawab->body(), "\n")) ?: 'Database 3 menolak query ini.';

            return ['ok' => false, 'pesan' => 'Query tidak valid: '.mb_substr($pesan, 0, 300)];
        }

        $catatan = 'dijalankan di Database 3 '.config('services.sparql.driver').' ('.parse_url(self::endpoint(), PHP_URL_HOST).').';

        if ($grafik) {
            $baris = [];
            foreach (preg_split('/\R/', trim($jawab->body())) as $tripel) {
                if (preg_match('/^(\S+)\s+(\S+)\s+(.+?)\s*\.$/', $tripel, $t)) {
                    // objek literal ("nilai"^^<tipe> atau "nilai"@bahasa) cukup ditampilkan nilainya
                    $objek = preg_match('/^"(.*)"(?:\^\^<[^>]+>|@[\w-]+)?$/s', $t[3], $l) ? stripcslashes($l[1]) : self::singkat(trim($t[3], '<>'));
                    $baris[] = [self::singkat(trim($t[1], '<>')), self::singkat(trim($t[2], '<>')), $objek];
                }
            }

            return ['ok' => true, 'kolom' => ['subjek', 'predikat', 'objek'], 'baris' => array_slice($baris, 0, $batas), 'catatan' => $catatan];
        }

        $hasil = $jawab->json();
        if ($jenis === 'ASK') {
            return ['ok' => true, 'kolom' => ['jawaban'], 'baris' => [[$hasil['boolean'] ? 'ya (true)' : 'tidak (false)']], 'catatan' => $catatan];
        }

        $kolom = $hasil['head']['vars'] ?? [];
        $baris = array_map(
            fn ($b) => array_map(fn ($k) => isset($b[$k]) ? self::nilai($b[$k]) : '', $kolom),
            array_slice($hasil['results']['bindings'] ?? [], 0, $batas),
        );

        return ['ok' => true, 'kolom' => $kolom, 'baris' => $baris, 'catatan' => $catatan];
    }
}
