<?php

namespace Tests\Feature;

use Database\Seeders\AdminTempatSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminTempatSeederTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.katalog' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        DB::purge('katalog');

        Schema::connection('katalog')->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('peran');
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
        });

        Schema::connection('katalog')->create('admin_tempat', function (Blueprint $table): void {
            $table->unsignedBigInteger('id_admin');
            $table->string('tempat_slug', 120)->unique();
            $table->timestamps();
            $table->primary(['id_admin', 'tempat_slug']);
        });
    }

    public function test_it_links_each_seeded_admin_to_its_place_and_is_idempotent(): void
    {
        $seeder = app(AdminTempatSeeder::class);
        $seeder->run();

        $admins = DB::connection('katalog')->table('users')->where('peran', 'admin')->get();
        $tautan = DB::connection('katalog')->table('admin_tempat')->get();
        $this->assertNotEmpty($admins);
        $this->assertCount($admins->count(), $tautan);

        foreach ($admins as $admin) {
            $relasi = $tautan->firstWhere('id_admin', $admin->id);
            $this->assertNotNull($relasi);
            $this->assertSame(
                $relasi->tempat_slug.'@medanvisit.neoverse.my.id',
                $admin->email
            );
        }

        $seeder->run();

        $this->assertSame($admins->count(), DB::connection('katalog')->table('users')->where('peran', 'admin')->count());
        $this->assertSame($tautan->count(), DB::connection('katalog')->table('admin_tempat')->count());
    }
}
