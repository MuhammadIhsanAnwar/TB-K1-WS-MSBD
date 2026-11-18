<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Memperbaiki galat 42725 "function sp_buat_pesanan(...) is not unique" saat checkout dan pelunasan.
 *
 * Function pembungkus untuk Supabase REST (migration 2026_11_15_000100) bernama sama dengan
 * procedure-nya. Karena parameter INOUT terakhir procedure punya DEFAULT NULL, pemanggilan function
