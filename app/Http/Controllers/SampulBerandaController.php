<?php

namespace App\Http\Controllers;

use App\Support\Katalog;
use App\Support\SampulBeranda;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

/** Panel super admin: memilih foto sampul (hero) beranda. Disimpan di DB 1 tabel pengaturan_situs. */
class SampulBerandaController extends Controller
