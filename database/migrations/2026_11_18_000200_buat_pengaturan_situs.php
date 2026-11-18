<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DB 1 (akun dan konfigurasi website): pengaturan situs yang diubah super admin dari panel,
 * satu baris per kunci dengan nilai JSON. Kunci pertama: sampul_beranda (foto hero beranda).
 */
return new class extends Migration
{
    protected $connection = 'katalog';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if (! $schema->hasTable('pengaturan_situs')) {
            $schema->create('pengaturan_situs', function (Blueprint $table) {
