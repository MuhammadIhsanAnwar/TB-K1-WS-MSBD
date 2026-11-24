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

    public function test_uploaded_photo_normalizes_legacy_filename_extension_to_jpg(): void
    {
        $this->assertPlacePhotoCanBeUploadedBy('superadmin', false, 'png');
    }

    private function assertPlacePhotoCanBeUploadedBy(string $role, bool $failUpdate = false, string $oldExtension = 'jpg'): void
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
        $oldFilename = $slug.'-lama.'.$oldExtension;
        $targetFilename = pathinfo($oldFilename, PATHINFO_FILENAME).'.jpg';
        $oldPath = public_path('storage/foto_wisata/'.$oldFilename);
        $targetPath = public_path('storage/foto_wisata/'.$targetFilename);
        $sourcePhoto = public_path('storage/foto_wisata/kesawan.jpg');
        $uploadSource = storage_path('app/'.$slug.'-unggahan.jpg');
        File::copy($sourcePhoto, $oldPath);
        $gambarBaru = imagecreatetruecolor(8, 8);
        $merah = imagecolorallocate($gambarBaru, 220, 30, 30);
        imagefill($gambarBaru, 0, 0, $merah);
        imagepng($gambarBaru, $uploadSource);
        imagedestroy($gambarBaru);
        $isiFotoLama = File::get($oldPath);
        $this->fotoUji = [$oldPath, $targetPath, $uploadSource];

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

        $this->withSession(['pengguna' => $pengguna])
            ->get('/admin/entitas/'.$slug)
            ->assertOk()
            ->assertSee('Status buka/tutup')
            ->assertSee(route('admin.entitas.status-operasional', $slug), false);

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
                'foto' => new UploadedFile($uploadSource, 'unggahan.png', 'image/png', null, true),
            ]);

        if ($failUpdate) {
            $response->assertInternalServerError();
            $this->assertFileExists($oldPath);
            $this->assertSame($isiFotoLama, File::get($oldPath));
            if ($targetPath !== $oldPath) {
                $this->assertFileDoesNotExist($targetPath);
            }

            return;
        }

        $response->assertRedirect()->assertSessionHas('pesan');

        $this->assertFileExists($targetPath);
        $this->assertNotSame($isiFotoLama, File::get($targetPath));
        $this->assertSame('image/jpeg', getimagesize($targetPath)['mime']);
        if ($targetPath !== $oldPath) {
            $this->assertFileDoesNotExist($oldPath);
        }
        Http::assertSent(fn (ClientRequest $request): bool =>
            str_contains(urldecode($request->body()), 'schema:image <'.$base.'storage/foto_wisata/'.$targetFilename.'>')
        );

        $this->withSession(['pengguna' => $pengguna])
            ->post('/admin/entitas/'.$slug.'/status-operasional', ['status_operasional' => 'tutup'])
            ->assertRedirect()
            ->assertSessionHas('pesan', 'Status tempat ditetapkan Tutup sekarang, tanpa mengikuti jam operasional.');
        Http::assertSent(fn (ClientRequest $request): bool =>
            $request->method() === 'POST'
            && str_contains($request->url(), '/rest/v1/pengaturan_situs?')
            && $request['nilai']['slug'] === $slug
            && $request['nilai']['status'] === 'tutup'
        );
    }
}
