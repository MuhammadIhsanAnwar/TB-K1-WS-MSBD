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
{
    public function tampil()
    {
        $tempat = Katalog::tempat();

        return view('admin.sampul-beranda', [
            'slot' => collect(range(0, SampulBeranda::SLOT - 1))
                ->map(fn ($i) => SampulBeranda::pengaturan()[$i] ?? ['slug' => null, 'foto' => null])
                ->map(fn ($s) => $s + ['fotoAda' => SampulBeranda::fotoAda($s['foto'])])
                ->all(),
            'pilihanTempat' => collect($tempat)->sortBy('nama')->values()->all(),
            'tampilSekarang' => SampulBeranda::slide($tempat),
            'diatur' => SampulBeranda::diatur($tempat),
        ]);
    }

    public function simpan(Request $request)
    {
        if ($request->boolean('otomatis')) {
            return $this->tulis($request, [], [], 'Sampul beranda kembali otomatis: empat tempat pertama yang punya foto.');
        }

        $request->validate([
            'slot' => ['required', 'array', 'max:'.SampulBeranda::SLOT],
            'slot.*.slug' => ['nullable', 'string', Rule::in(array_keys(Katalog::tempat())), 'distinct', 'required_with:slot.*.foto'],
            'slot.*.foto' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096', 'dimensions:min_width=1200,min_height=600'],
