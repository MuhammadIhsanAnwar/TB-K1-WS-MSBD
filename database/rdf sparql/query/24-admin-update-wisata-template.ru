# Pola DELETE/INSERT/WHERE dari Database3CatalogWriter::update().
# Query aktual juga mempertahankan cerita lengkap, memperbarui alamat PostalAddress,
# mengubah jam terstruktur hanya bila keterangan jam berubah, dan mengganti schema:offers.
PREFIX schema: <https://schema.org/>
PREFIX mv: <https://medanvisit.neoverse.my.id/ontologi#>
PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
PREFIX geo: <http://www.w3.org/2003/01/geo/wgs84_pos#>
PREFIX dcterms: <http://purl.org/dc/terms/>
PREFIX res: <https://medanvisit.neoverse.my.id/id/>

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
}
