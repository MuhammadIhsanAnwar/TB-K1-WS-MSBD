# Pola operasi Database3CatalogWriter::create().
# Nilai literal/IRI harus diserialisasi lewat helper writer; jangan substitusi mentah input pengguna.
# Dalam request aktual, operasi dasar, penghapusan penawaran lama, dan INSERT penawaran
# (bila ada) dikirim sebagai satu SPARQL Update request dengan pemisah semicolon.
PREFIX schema: <https://schema.org/>
PREFIX mv: <https://medanvisit.neoverse.my.id/ontologi#>
PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
PREFIX geo: <http://www.w3.org/2003/01/geo/wgs84_pos#>
PREFIX dcterms: <http://purl.org/dc/terms/>
PREFIX xsd: <http://www.w3.org/2001/XMLSchema#>
PREFIX res: <https://medanvisit.neoverse.my.id/id/>

INSERT DATA {
  res:{{SLUG}} a {{KELAS}} ;
    rdfs:label {{NAMA}} ;
    schema:name {{NAMA}} ;
    schema:description {{DESKRIPSI}} ;
    mv:cerita {{DESKRIPSI}} ;
    geo:lat {{LAT}} ;
    geo:long {{LONG}} ;
    schema:address {{ALAMAT}} ;
    mv:keteranganJam {{JAM}} ;
    dcterms:source {{SUMBER}} ;
    schema:isAccessibleForFree {{GRATIS}} .
  # Bila ada: res:{{SLUG}} mv:beradaDi res:{{KECAMATAN}} .
  # Bila ada: res:{{SLUG}} schema:image <{{URL_FOTO}}>.
  # Bila rentang jam dapat diparsing, tambahkan:
  # res:{{SLUG}} mv:jamBuka "{{BUKA}}"^^xsd:time ;
  #              mv:jamTutup "{{TUTUP}}"^^xsd:time .
};

# replaceOffers() menghapus penawaran sebelumnya beserta propertinya terlebih dahulu.
DELETE {
  res:{{SLUG}} schema:offers ?offer .
  ?offer ?offerPredicate ?offerObject .
}
WHERE {
  OPTIONAL {
    res:{{SLUG}} schema:offers ?offer .
    ?offer ?offerPredicate ?offerObject
  }
};

# Satu pola ini diulang untuk setiap harga yang valid; bila tidak ada harga,
# INSERT DATA berikut tidak dikirim dan isAccessibleForFree bernilai true.
INSERT DATA {
  res:{{SLUG}} schema:offers [
    a schema:Offer ;
    schema:name {{JENIS_TIKET}} ;
    schema:price {{HARGA_INTEGER}} ;
    schema:priceCurrency "IDR"
  ] .
}
