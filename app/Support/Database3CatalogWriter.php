<?php

namespace App\Support;

use RuntimeException;

final class Database3CatalogWriter
{
    private const PREFIXES = <<<'SPARQL'
        PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
        PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
        PREFIX schema: <https://schema.org/>
        PREFIX geo: <http://www.w3.org/2003/01/geo/wgs84_pos#>
        PREFIX mv: <https://medanvisit.neoverse.my.id/ontologi#>
        PREFIX dcterms: <http://purl.org/dc/terms/>
        PREFIX xsd: <http://www.w3.org/2001/XMLSchema#>
        PREFIX res: <https://medanvisit.neoverse.my.id/id/>
        SPARQL;

    private const CATEGORY_TYPES = [
        'sejarah' => 'mv:SitusSejarah',
        'museum' => 'mv:Museum',
        'religi' => 'mv:RumahIbadah',
        'kuliner' => 'mv:TempatKuliner',
        'ruang-publik' => 'mv:RuangPublik',
        'belanja' => 'mv:PusatBelanja',
        'rekreasi-air' => 'mv:RekreasiAir',
    ];

    public static function create(string $slug, array $data, array $harga): void
    {
        $subjek = self::subjek($slug);
        $triples = [
            "$subjek a ".self::tipe($data['kategori']),
            "$subjek rdfs:label ".self::literal($data['nama']),
            "$subjek schema:name ".self::literal($data['nama']),
            "$subjek schema:description ".self::literal($data['ringkas']),
            "$subjek mv:cerita ".self::literal($data['ringkas']),
            "$subjek geo:lat ".self::angka($data['lat']),
            "$subjek geo:long ".self::angka($data['lng']),
            "$subjek schema:address ".self::literal($data['alamat']),
            "$subjek mv:keteranganJam ".self::literal($data['jam']),
            "$subjek dcterms:source ".self::sumber($data['sumber']),
        ];
        if ($data['kecamatan'] !== '') {
            $triples[] = "$subjek mv:beradaDi ".self::subjek($data['kecamatan']);
        }
        if ($data['foto'] !== '') {
            $triples[] = "$subjek schema:image <".self::urlFoto($data['foto']).'>';
        }
        if ($rentang = self::rentangJam($data['jam'])) {
            $triples[] = "$subjek mv:jamBuka \"{$rentang[0]}\"^^xsd:time";
            $triples[] = "$subjek mv:jamTutup \"{$rentang[1]}\"^^xsd:time";
        }

        self::replaceOffers('', $slug, $harga, implode(" .\n", $triples).'.');
    }

    /**
     * Ubah tempat wisata. Cerita lengkap (mv:cerita) tidak disentuh karena formulir tidak memuatnya;
     * alamat diperbarui di dalam simpul schema:PostalAddress yang sudah ada; jam buka terstruktur
     * (mv:jamBuka dan mv:jamTutup, xsd:time) ikut diperbarui kalau keterangan jam berubah.
     */
    public static function update(string $slug, array $data, array $harga): void
    {
        $subjek = self::subjek($slug);
        $delete = [
            "$subjek a mv:SitusSejarah",
            "$subjek a mv:Museum",
            "$subjek a mv:RumahIbadah",
            "$subjek a mv:TempatKuliner",
            "$subjek a mv:RuangPublik",
            "$subjek a mv:PusatBelanja",
            "$subjek a mv:RekreasiAir",
            "$subjek rdfs:label ?label",
            "$subjek schema:name ?name",
            "$subjek schema:description ?description",
            "$subjek geo:lat ?lat",
            "$subjek geo:long ?long",
            "$subjek mv:keteranganJam ?hours",
            "$subjek schema:image ?image",
            "$subjek dcterms:source ?source",
            "$subjek mv:beradaDi ?district",
        ];
        $insert = [
            "$subjek a ".self::tipe($data['kategori']),
            "$subjek rdfs:label ".self::literal($data['nama']),
            "$subjek schema:name ".self::literal($data['nama']),
            "$subjek schema:description ".self::literal($data['ringkas']),
            "$subjek geo:lat ".self::angka($data['lat']),
            "$subjek geo:long ".self::angka($data['lng']),
            "$subjek mv:keteranganJam ".self::literal($data['jam']),
            "$subjek dcterms:source ".self::sumber($data['sumber']),
        ];
        if ($data['foto'] !== '') {
            $insert[] = "$subjek schema:image <".self::urlFoto($data['foto']).'>';
        }
        if ($data['kecamatan'] !== '') {
            $insert[] = "$subjek mv:beradaDi ".self::subjek($data['kecamatan']);
        }

        $where = [
            "OPTIONAL { $subjek a mv:SitusSejarah }",
            "OPTIONAL { $subjek a mv:Museum }",
            "OPTIONAL { $subjek a mv:RumahIbadah }",
            "OPTIONAL { $subjek a mv:TempatKuliner }",
            "OPTIONAL { $subjek a mv:RuangPublik }",
            "OPTIONAL { $subjek a mv:PusatBelanja }",
            "OPTIONAL { $subjek a mv:RekreasiAir }",
            "OPTIONAL { $subjek rdfs:label ?label }",
            "OPTIONAL { $subjek schema:name ?name }",
            "OPTIONAL { $subjek schema:description ?description }",
            "OPTIONAL { $subjek geo:lat ?lat }",
            "OPTIONAL { $subjek geo:long ?long }",
            "OPTIONAL { $subjek mv:keteranganJam ?hours }",
            "OPTIONAL { $subjek schema:image ?image }",
            "OPTIONAL { $subjek dcterms:source ?source }",
            "OPTIONAL { $subjek mv:beradaDi ?district }",
        ];
        // jam terstruktur dipakai saringan "buka sekarang": diganti hanya kalau keterangan jam berubah,
        // supaya jam yang sudah dikurasi tidak tertimpa. Operasi ini jalan sebelum mv:keteranganJam
        // diganti, jadi perbandingannya masih dengan teks lama.
        $jamBaru = json_encode(trim($data['jam']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $rentang = self::rentangJam($data['jam']);
        $operasi = ["DELETE { $subjek mv:jamBuka ?buka . $subjek mv:jamTutup ?tutup }\n".
            ($rentang ? "INSERT { $subjek mv:jamBuka \"{$rentang[0]}\"^^xsd:time . $subjek mv:jamTutup \"{$rentang[1]}\"^^xsd:time }\n" : '').
            "WHERE { FILTER NOT EXISTS { $subjek mv:keteranganJam ?jamLama . FILTER (STR(?jamLama) = $jamBaru) }\n".
            "OPTIONAL { $subjek mv:jamBuka ?buka } OPTIONAL { $subjek mv:jamTutup ?tutup } }"];

        $operasi[] = 'DELETE { '.implode(" .\n", $delete)." }\nINSERT { ".implode(" .\n", $insert)." }\nWHERE { ".implode(" .\n", $where).' }';

        // alamat: di data asli berupa simpul schema:PostalAddress; tempat baru dari admin berupa teks
        $alamat = self::literal($data['alamat']);
        $operasi[] = "DELETE { ?alamat schema:streetAddress ?jalan } INSERT { ?alamat schema:streetAddress $alamat }\n".
            "WHERE { $subjek schema:address ?alamat . FILTER (!isLiteral(?alamat)) OPTIONAL { ?alamat schema:streetAddress ?jalan } }";
        $operasi[] = "DELETE { $subjek schema:address ?alamat } INSERT { $subjek schema:address $alamat }\n".
            "WHERE { $subjek schema:address ?alamat . FILTER (isLiteral(?alamat)) }";
        $operasi[] = "INSERT { $subjek schema:address $alamat } WHERE { FILTER NOT EXISTS { $subjek schema:address ?apaPun } }";

        self::replaceOffers(implode(";\n", $operasi), $slug, $harga);
    }

    /**
     * Jam buka dan tutup dari keterangan seperti "Setiap hari, 08.00–17.00" atau
     * "Senin–Sabtu 06.00–11.00 dan 17.00–20.00" (diambil buka paling awal dan tutup paling akhir).
     * "24 jam" menjadi 00.00 sampai 23.59. Tanpa rentang jam yang bisa dibaca, hasilnya null.
     */
    public static function rentangJam(string $keterangan): ?array
    {
        if (preg_match('/24\s*jam/i', $keterangan)) {
            return ['00:00:00', '23:59:00'];
        }
        preg_match_all('/(\d{1,2})[.:](\d{2})\s*[–-]\s*(\d{1,2})[.:](\d{2})/u', $keterangan, $m, PREG_SET_ORDER);
        if (! $m) {
    }

    public static function delete(string $slug): void
    {
        $subjek = self::subjek($slug);
        SparqlService::perbarui(self::PREFIXES."\n".
            "DELETE { ?related ?predicate $subjek . $subjek ?outPredicate ?outObject . ?statement ?statementPredicate ?statementObject }\n".
            "WHERE {\n".
            "  OPTIONAL { ?related ?predicate $subjek }\n".
            "  OPTIONAL { $subjek ?outPredicate ?outObject }\n".
            "  OPTIONAL { ?statement rdf:type rdf:Statement ; rdf:subject|rdf:object $subjek ; ?statementPredicate ?statementObject }\n".
            "}");
        Katalog::forgetCache();
    }

    private static function replaceOffers(string $update, string $slug, array $harga, string $insertData = ''): void
    {
        $subjek = self::subjek($slug);
        $operations = [];
        if ($update !== '') {
            $operations[] = $update;
        } elseif ($insertData !== '') {
            $operations[] = 'INSERT DATA { '.$insertData.' }';
        }

        $operations[] = "DELETE { $subjek schema:offers ?offer . ?offer ?offerPredicate ?offerObject }\n".
            "WHERE { OPTIONAL { $subjek schema:offers ?offer . ?offer ?offerPredicate ?offerObject } }";
        $offers = [];
        foreach ($harga as $nama => $nilai) {
            if ($nilai === null || $nilai === '') {
                continue;
            }
            $offers[] = "$subjek schema:offers [ a schema:Offer ; schema:name ".self::literal((string) $nama).
                ' ; schema:price '.(int) $nilai.' ; schema:priceCurrency "IDR" ]';
        }
        if ($offers) {
            $operations[] = 'INSERT DATA { '.implode(" .\n", $offers).' }';
        }

        SparqlService::perbarui(self::PREFIXES."\n".implode(";\n", $operations));
        Katalog::forgetCache();
    }

    private static function tipe(string $category): string
    {
        return self::CATEGORY_TYPES[$category] ?? throw new RuntimeException('Kategori tempat tidak valid.');
    }

    private static function subjek(string $slug): string
    {
        return 'res:'.$slug;
    }

    private static function literal(string $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).'@id';
    }

    private static function sumber(string $value): string
    {
        $scheme = parse_url($value, PHP_URL_SCHEME);

        return filter_var($value, FILTER_VALIDATE_URL) && in_array(strtolower((string) $scheme), ['http', 'https'], true)
            ? '<'.$value.'>'
            : self::literal($value);
    }

    private static function angka(string|int|float $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 7, '.', ''), '0'), '.');
    }

    private static function urlFoto(string $file): string
    {
        return Semantik::BASIS.'images/foto/'.$file;
    }
}
