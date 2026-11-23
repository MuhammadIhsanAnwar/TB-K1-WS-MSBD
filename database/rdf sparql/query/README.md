# Query SPARQL MedanVisit

File `01`–`11` yang sudah ada adalah contoh query analitik/eksplorasi dataset. File `12`–`22`
mereferensikan query baca yang dipakai runtime atau helper di `app/Support/SparqlService.php`
dan `app/Support/Database3Catalog.php`. File `23`–`25` mendokumentasikan pola SPARQL Update
admin dari `app/Support/Database3CatalogWriter.php`.

Nama file berakhiran `template` memiliki placeholder `{{...}}`; ganti placeholder dengan
nilai yang sudah divalidasi dan diserialisasi aplikasi sebelum dieksekusi. File tersebut
bukan query siap jalan tanpa substitusi. `cariTempat()` dan query statistik digunakan oleh
Tanya Mas Nonov; beberapa helper lain saat ini hanya tersedia di service dan belum dipanggil
oleh route web. Katalog JSON-LD dibangun dari hasil pemetaan RDF, bukan query SPARQL terpisah.
