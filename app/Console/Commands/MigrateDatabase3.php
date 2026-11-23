<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/** Alur aman: validasi sumber RDF, lalu ganti isi DB 3 dari sumber tersebut. */
class MigrateDatabase3 extends Command
{
    protected $signature = 'db3:migrate
                            {--expect=196 : Jumlah tempat wisata yang diharapkan}
                            {--force : Kirim meski validasi sumber gagal}
                            {--no-replace : Tambahkan data tanpa menghapus graph lama}';

    protected $description = 'Validasi lalu migrasikan ontologi dan data wisata RDF ke Database 3';

    public function handle(): int
    {
        $this->info('Memeriksa sumber RDF sebelum migrasi Database 3...');
        $valid = $this->call('db3:validasi', [
            '--expect' => $this->option('expect'),
            '--ringkas' => true,
        ]);

        if ($valid !== self::SUCCESS && ! $this->option('force')) {
            $this->error('Migrasi dibatalkan karena sumber RDF belum lengkap. Gunakan --force hanya untuk pemulihan yang telah ditinjau.');

            return self::FAILURE;
        }

        if ($valid !== self::SUCCESS) {
            $this->warn('Validasi dilewati atas permintaan --force.');
        }

        $this->info('Mengirim ontologi dan data RDF ke Database 3...');

        return $this->call('rdf:kirim', [
            '--replace' => ! $this->option('no-replace'),
        ]);
    }
}
