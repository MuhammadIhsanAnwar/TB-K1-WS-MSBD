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
            'slot.*.hapus_foto' => ['nullable', 'boolean'],
        ], [
            'slot.*.slug.in' => 'Tempat di slot :position tidak ada di katalog.',
            'slot.*.slug.distinct' => 'Satu tempat hanya boleh dipakai di satu slot.',
            'slot.*.slug.required_with' => 'Pilih tempat untuk slot :position sebelum mengunggah foto.',
            'slot.*.foto.image' => 'Foto slot :position harus berupa gambar.',
            'slot.*.foto.mimes' => 'Foto slot :position harus JPG, PNG, atau WEBP.',
            'slot.*.foto.max' => 'Foto slot :position maksimal 4 MB.',
            'slot.*.foto.dimensions' => 'Foto slot :position minimal 1200 x 600 piksel supaya tidak pecah di layar lebar.',
        ]);

        $lama = SampulBeranda::pengaturan();
        $baru = [];
        $unggahan = [];
        foreach (range(0, SampulBeranda::SLOT - 1) as $i) {
            $slug = $request->input("slot.$i.slug");
            if (! $slug) {
                continue;
            }
            // foto unggahan lama ikut slot hanya kalau tempatnya tidak diganti
            $foto = ($lama[$i]['slug'] ?? null) === $slug && ! $request->boolean("slot.$i.hapus_foto")
                ? SampulBeranda::fotoAda($lama[$i]['foto'] ?? null)
                : null;
            if ($berkas = $request->file("slot.$i.foto")) {
                $folder = public_path(SampulBeranda::FOLDER);
                File::ensureDirectoryExists($folder);
                $nama = Str::slug($slug).'-'.Str::lower(Str::random(8)).'.'.$berkas->extension();
                $berkas->move($folder, $nama);
                $foto = SampulBeranda::FOLDER.'/'.$nama;
                $unggahan[] = $foto;
            }
            $baru[] = ['slug' => $slug, 'foto' => $foto];
        }
