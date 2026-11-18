<?php

namespace App\Support;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Pembayaran tiket lewat Midtrans Snap. order_id Midtrans = kode pesanan di DB 2.
 *
 * Pesanan ditandai lunas hanya setelah status transaksi ditanyakan langsung ke API Midtrans,
