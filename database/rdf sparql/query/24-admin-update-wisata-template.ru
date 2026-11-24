# Pola operasi berurutan dari Database3CatalogWriter::update().
# Query aktual mempertahankan mv:cerita, memperbarui alamat, jam terstruktur,
# serta penawaran. Placeholder adalah notasi dokumentasi, bukan sintaks SPARQL.
PREFIX schema: <https://schema.org/>
PREFIX mv: <https://medanvisit.neoverse.my.id/ontologi#>
PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
PREFIX geo: <http://www.w3.org/2003/01/geo/wgs84_pos#>
PREFIX dcterms: <http://purl.org/dc/terms/>
PREFIX xsd: <http://www.w3.org/2001/XMLSchema#>
PREFIX res: <https://medanvisit.neoverse.my.id/id/>

# 1. Jam bertipe diperbarui hanya jika teks keterangan jam berubah.
# INSERT untuk jamBuka/jamTutup tidak dikirim bila rentang baru tidak dapat diparsing.
DELETE {
  res:{{SLUG}} mv:jamBuka ?buka ;
               mv:jamTutup ?tutup .
}
INSERT {
  res:{{SLUG}} mv:jamBuka "{{JAM_BUKA}}"^^xsd:time ;
               mv:jamTutup "{{JAM_TUTUP}}"^^xsd:time .
}
WHERE {
  FILTER NOT EXISTS {
    res:{{SLUG}} mv:keteranganJam ?jamLama .
    FILTER (STR(?jamLama) = {{JAM_BARU_LITERAL}})
  }
  OPTIONAL { res:{{SLUG}} mv:jamBuka ?buka }
  OPTIONAL { res:{{SLUG}} mv:jamTutup ?tutup }
};

# 2. Ganti atribut yang dikelola formulir; mv:cerita tidak disentuh.
DELETE {
  res:{{SLUG}} a mv:SitusSejarah, mv:Museum, mv:RumahIbadah,
    mv:TempatKuliner, mv:RuangPublik, mv:PusatBelanja, mv:RekreasiAir ;
    rdfs:label ?label ;
    schema:name ?name ;
    schema:description ?description ;
    geo:lat ?lat ;
    geo:long ?long ;
    mv:keteranganJam ?hours ;
    schema:image ?image ;
    dcterms:source ?source ;
    mv:beradaDi ?district ;
    schema:isAccessibleForFree ?gratis .
}
INSERT {
  res:{{SLUG}} a {{KELAS}} ;
    rdfs:label {{NAMA}} ;
    schema:name {{NAMA}} ;
    schema:description {{DESKRIPSI}} ;
    geo:lat {{LAT}} ;
    geo:long {{LONG}} ;
    mv:keteranganJam {{JAM}} ;
    dcterms:source {{SUMBER}} ;
    schema:isAccessibleForFree {{GRATIS}} .
  # Bila foto tersedia, tambahkan: res:{{SLUG}} schema:image <{{URL_FOTO}}> .
  # Bila kecamatan tersedia, tambahkan: res:{{SLUG}} mv:beradaDi res:{{KECAMATAN}} .
}
WHERE {
  OPTIONAL { res:{{SLUG}} a mv:SitusSejarah }
  OPTIONAL { res:{{SLUG}} a mv:Museum }
  OPTIONAL { res:{{SLUG}} a mv:RumahIbadah }
  OPTIONAL { res:{{SLUG}} a mv:TempatKuliner }
  OPTIONAL { res:{{SLUG}} a mv:RuangPublik }
  OPTIONAL { res:{{SLUG}} a mv:PusatBelanja }
  OPTIONAL { res:{{SLUG}} a mv:RekreasiAir }
  OPTIONAL { res:{{SLUG}} rdfs:label ?label }
  OPTIONAL { res:{{SLUG}} schema:name ?name }
  OPTIONAL { res:{{SLUG}} schema:description ?description }
  OPTIONAL { res:{{SLUG}} geo:lat ?lat }
  OPTIONAL { res:{{SLUG}} geo:long ?long }
  OPTIONAL { res:{{SLUG}} mv:keteranganJam ?hours }
  OPTIONAL { res:{{SLUG}} schema:image ?image }
  OPTIONAL { res:{{SLUG}} dcterms:source ?source }
  OPTIONAL { res:{{SLUG}} mv:beradaDi ?district }
  OPTIONAL { res:{{SLUG}} schema:isAccessibleForFree ?gratis }
};

# 3. Bila schema:address menunjuk node alamat, ubah schema:streetAddress di node tersebut.
DELETE { ?alamat schema:streetAddress ?jalan }
INSERT { ?alamat schema:streetAddress {{ALAMAT}} }
WHERE {
  res:{{SLUG}} schema:address ?alamat .
  FILTER (!isLiteral(?alamat))
  OPTIONAL { ?alamat schema:streetAddress ?jalan }
};

# 4. Bila schema:address lama berupa literal, ganti nilai literalnya.
DELETE { res:{{SLUG}} schema:address ?alamat }
INSERT { res:{{SLUG}} schema:address {{ALAMAT}} }
WHERE {
  res:{{SLUG}} schema:address ?alamat .
  FILTER (isLiteral(?alamat))
};

# 5. Bila resource belum mempunyai schema:address, tambahkan alamat.
INSERT { res:{{SLUG}} schema:address {{ALAMAT}} }
WHERE {
  FILTER NOT EXISTS { res:{{SLUG}} schema:address ?apaPun }
};

# 6. replaceOffers() menghapus schema:offers lama beserta propertinya,
# lalu mengirim INSERT DATA terpisah untuk setiap harga yang valid.
