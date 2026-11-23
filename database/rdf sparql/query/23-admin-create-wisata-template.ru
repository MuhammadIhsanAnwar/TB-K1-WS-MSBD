# Template INSERT yang dibentuk Database3CatalogWriter::create().
# Nilai literal/IRI harus diserialisasi lewat helper writer; jangan substitusi mentah input pengguna.
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
  # Writer menambahkan mv:beradaDi, schema:image, mv:jamBuka/mv:jamTutup bila tersedia.
}
