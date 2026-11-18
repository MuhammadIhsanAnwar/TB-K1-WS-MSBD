<?php

namespace App\Http\Controllers;

use App\Support\Midtrans;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Notifikasi pembayaran dari Midtrans (HTTP POST, tanpa sesi dan tanpa token CSRF).
 * Alamatnya diisi di dashboard Midtrans: Settings > Payment > Notification URL.
 */
class MidtransController extends Controller
{
    public function notifikasi(Request $request)
    {
