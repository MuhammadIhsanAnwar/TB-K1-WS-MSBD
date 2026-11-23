<?php

namespace Tests\Feature;

use App\Support\Katalog;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminEntitasFotoTest extends TestCase
{
    private array $fotoUji = [];

    protected function tearDown(): void
    {
        foreach ($this->fotoUji as $path) {
            File::delete($path);
        }
        Cache::setDefaultDriver('array');
        Cache::store('array')->flush();
        Katalog::forgetCache();

        parent::tearDown();
    }

    public function test_superadmin_can_upload_a_place_photo_and_replace_the_old_file(): void
    {
        $this->assertPlacePhotoCanBeUploadedBy('superadmin');
    }

    public function test_admin_can_upload_a_photo_for_a_managed_place(): void
    {
        $this->assertPlacePhotoCanBeUploadedBy('admin');
    }

    public function test_uploaded_photo_is_rolled_back_if_database_update_fails(): void
    {
        $this->assertPlacePhotoCanBeUploadedBy('superadmin', true);
    }

    private function assertPlacePhotoCanBeUploadedBy(string $role, bool $failUpdate = false): void
    {
        Cache::setDefaultDriver('array');
        Cache::store('array')->flush();
        Katalog::forgetCache();
        config([
            'services.sparql.endpoint' => 'https://sparql.test/query',
            'services.supabase.katalog.url' => 'https://katalog.test',
            'services.supabase.katalog.publishable_key' => 'pub-key',
            'services.supabase.katalog.secret_key' => 'secret-key',
        ]);

        $base = 'https://medanvisit.neoverse.my.id/';
        $slug = 'tempat-uji-foto';
        $name = 'Tempat Uji Foto';
        $district = $base.'id/kec-medan-barat';
        $oldFilename = $slug.'-lama.jpg';
        $oldPath = public_path('storage/foto_wisata/'.$oldFilename);
        $newPath = public_path('storage/foto_wisata/'.$slug.'.jpg');
        $sourcePhoto = public_path('storage/foto_wisata/kesawan.jpg');
        $uploadSource = storage_path('app/'.$slug.'-unggahan.jpg');
        File::copy($sourcePhoto, $oldPath);
        File::copy($sourcePhoto, $uploadSource);
        $this->fotoUji = [$oldPath, $newPath, $uploadSource];

        $bindings = [];
        $addTriple = static function (string $subject, string $predicate, string $value, string $type = 'literal', ?string $datatype = null) use (&$bindings): void {
            $object = ['type' => $type, 'value' => $value];
            if ($datatype !== null) {
                $object['datatype'] = $datatype;
            }
            $bindings[] = [
                's' => ['type' => 'uri', 'value' => $subject],
                'p' => ['type' => 'uri', 'value' => $predicate],
                'o' => $object,
            ];
        };
        $entity = $base.'id/'.$slug;
        $ontology = $base.'ontologi#';
        $addTriple($entity, 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type', $ontology.'SitusSejarah', 'uri');
        $addTriple($entity, 'http://www.w3.org/2000/01/rdf-schema#label', $name);
        $addTriple($entity, 'https://schema.org/name', $name);
        $addTriple($entity, 'https://schema.org/description', 'Deskripsi tempat uji.');
        $addTriple($entity, 'http://www.w3.org/2003/01/geo/wgs84_pos#lat', '3.58', 'literal', 'http://www.w3.org/2001/XMLSchema#decimal');
        $addTriple($entity, 'http://www.w3.org/2003/01/geo/wgs84_pos#long', '98.67', 'literal', 'http://www.w3.org/2001/XMLSchema#decimal');
        $addTriple($entity, 'https://schema.org/address', 'Jalan Uji');
        $addTriple($entity, $ontology.'keteranganJam', 'Setiap hari, 08.00–17.00');
        $addTriple($entity, 'http://purl.org/dc/terms/source', 'https://example.test/sumber');
        $addTriple($entity, $ontology.'beradaDi', $district, 'uri');
        $addTriple($entity, 'https://schema.org/image', $base.'storage/foto_wisata/'.$oldFilename, 'uri');
        $addTriple($entity, 'https://schema.org/isAccessibleForFree', 'true');
        $addTriple($district, 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type', $ontology.'Kecamatan', 'uri');
        $addTriple($district, 'https://schema.org/name', 'Medan Barat');

        Http::fake(function (ClientRequest $request) use ($bindings, $slug, $failUpdate) {
            if (str_contains($request->url(), 'sparql.test')) {
                if ($failUpdate && str_contains(urldecode($request->body()), 'update=')) {
                    return Http::response([], 500);
                }

                return Http::response([
                    'head' => ['vars' => ['s', 'p', 'o']],
                    'results' => ['bindings' => $bindings],
                ]);
            }
            if (str_contains($request->url(), '/rest/v1/admin_tempat')) {
                return Http::response([['tempat_slug' => $slug]]);
            }

            return Http::response([]);
        });

        $pengguna = [
            'id' => 7,
            'nama' => ucfirst($role),
            'email' => $role.'@example.test',
            'peran' => $role,
        ];
        if ($role === 'admin') {
            $pengguna['tempat_slugs'] = [$slug];
        }

        $response = $this->withSession(['pengguna' => $pengguna])
            ->post('/admin/entitas/'.$slug, [
                'nama' => $name,
                'ringkas' => 'Deskripsi diperbarui.',
                'kategori' => 'sejarah',
                'lat' => '3.58',
                'lng' => '98.67',
                'alamat' => 'Jalan Uji',
                'jam' => 'Setiap hari, 08.00–17.00',
                'sumber' => 'https://example.test/sumber',
                'kecamatan' => 'kec-medan-barat',
                'foto' => new UploadedFile($uploadSource, 'unggahan.jpg', 'image/jpeg', null, true),
            ]);

        if ($failUpdate) {
            $response->assertInternalServerError();
            $this->assertFileExists($oldPath);
            $this->assertFileDoesNotExist($newPath);

            return;
        }

        $response->assertRedirect()->assertSessionHas('pesan');

        $this->assertFileExists($newPath);
        $this->assertFileDoesNotExist($oldPath);
        Http::assertSent(fn (ClientRequest $request): bool =>
            str_contains(urldecode($request->body()), 'schema:image <'.$base.'storage/foto_wisata/'.$slug.'.jpg>')
        );
    }
}
