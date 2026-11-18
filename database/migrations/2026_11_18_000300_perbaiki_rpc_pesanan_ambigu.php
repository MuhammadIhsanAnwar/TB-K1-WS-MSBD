<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Memperbaiki galat 42725 "function sp_buat_pesanan(...) is not unique" saat checkout dan pelunasan.
 *
 * Function pembungkus untuk Supabase REST (migration 2026_11_15_000100) bernama sama dengan
 * procedure-nya. Karena parameter INOUT terakhir procedure punya DEFAULT NULL, pemanggilan function
 * dengan 8 argumen (sp_buat_pesanan) atau 2 argumen (sp_bayar_pesanan) juga cocok dengan procedure,
 * sehingga PostgreSQL tidak bisa memilih. Procedure dibuat ulang tanpa DEFAULT; isinya tidak berubah
 * dan function pembungkus tetap memanggilnya dengan argumen lengkap.
 */
return new class extends Migration
{
    protected $connection = 'transaksi';

    private const PERAN = 'PUBLIC, anon, authenticated, service_role';

    private const ISI_BUAT = <<<'SQL'
        LANGUAGE plpgsql AS $$
        DECLARE
            v_id bigint;
        BEGIN
