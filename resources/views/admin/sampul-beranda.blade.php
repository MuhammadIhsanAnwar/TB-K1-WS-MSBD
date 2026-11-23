@extends('layouts.panel')
@section('judul', 'Sampul beranda')

@php
    use App\Support\Foto;
    $cadangan = 'peta-medan-1930-kota-lama.jpg';
    $fotoKecil = fn (?string $berkas) => Foto::url($berkas ?: $cadangan);
    $tempatPerSlug = collect($pilihanTempat)->keyBy('slug');
@endphp

@section('isi')
<div class="p-judul">
    <div>
        <h1>Sampul beranda</h1>
        <p class="sub">Foto besar di bagian atas beranda. Foto berganti otomatis sesuai urutan slot.</p>
    </div>
    <div class="p-judul__aksi">
        <span class="p-pill {{ $diatur ? 'pos' : '' }}">{{ $diatur ? 'Diatur super admin' : 'Otomatis' }}</span>
        <a class="p-btn" href="{{ route('beranda') }}">Lihat beranda</a>
    </div>
</div>

@if ($errors->any())
    <div class="p-pesan p-pesan--galat" role="alert">
        <ul class="sampul-atur__galat">@foreach (array_unique($errors->all()) as $g)<li>{{ $g }}</li>@endforeach</ul>
    </div>
@endif

<form method="post" action="{{ route('admin.beranda') }}" enctype="multipart/form-data" data-sampul-atur data-maks-slot="{{ $maksSlot }}">
    @csrf
    <div class="p-grid sampul-atur" data-kumpulan-slot>
        @foreach ($slot as $i => $s)
            @php
                $slugTerpilih = old("slot.$i.slug", $s['slug']);
                $tempatSlot = $slugTerpilih ? $tempatPerSlug->get($slugTerpilih) : null;
                $pratinjau = $s['fotoAda'] && $slugTerpilih === $s['slug'] ? asset($s['fotoAda']) : ($tempatSlot ? $fotoKecil($tempatSlot['foto'] ?? null) : null);
            @endphp
            <section class="p-panel s6" aria-labelledby="slot-{{ $i }}" data-slot>
                <header>
                    <h2 id="slot-{{ $i }}">Slot {{ $i + 1 }}</h2>
                    @if ($i === 0)<span class="meta">tampil pertama</span>@endif
                </header>
                <figure class="sampul-atur__pratinjau">
                    <img src="{{ $pratinjau }}" alt="" width="640" height="360" data-pratinjau @unless ($pratinjau) hidden @endunless>
                    <div class="p-kosong" data-kosong @if ($pratinjau) hidden @endif>Slot kosong</div>
                    <figcaption data-sumber>
                        @if ($s['fotoAda'] && $slugTerpilih === $s['slug']) Foto unggahan
                        @elseif ($tempatSlot) {{ empty($tempatSlot['foto']) ? 'Tempat ini belum punya foto; beranda memakai peta Medan 1930' : 'Foto dari data tempat (Database 3)' }}
                        @endif
                    </figcaption>
                </figure>
                <div class="p-field">
                    <label for="slot-{{ $i }}-slug">Tempat wisata</label>
                    <select id="slot-{{ $i }}-slug" name="slot[{{ $i }}][slug]" data-pilih-tempat @error("slot.$i.slug") aria-invalid="true" @enderror>
                        <option value="" data-foto="">Kosong</option>
                        @foreach ($pilihanTempat as $t)
                            <option value="{{ $t['slug'] }}" data-foto="{{ $fotoKecil($t['foto'] ?? null) }}" data-tanpa-foto="{{ empty($t['foto']) ? '1' : '' }}" @selected($slugTerpilih === $t['slug'])>{{ $t['nama'] }}{{ empty($t['foto']) ? ' (belum ada foto)' : '' }}</option>
                        @endforeach
                    </select>
                    @error("slot.$i.slug")<span class="sampul-atur__salah">{{ $message }}</span>@enderror
                </div>
                <div class="p-field">
                    <label for="slot-{{ $i }}-foto">Ganti dengan foto sendiri (opsional)</label>
                    <input id="slot-{{ $i }}-foto" type="file" name="slot[{{ $i }}][foto]" accept="image/jpeg,image/png,image/webp" data-unggah aria-describedby="slot-{{ $i }}-bantu" @error("slot.$i.foto") aria-invalid="true" @enderror>
                    <span class="bantu" id="slot-{{ $i }}-bantu">JPG, PNG, atau WEBP, minimal 1200 x 600 piksel, maksimal 4 MB. Bagian tengah foto paling aman karena tepinya terpotong di ponsel.</span>
                    @error("slot.$i.foto")<span class="sampul-atur__salah">{{ $message }}</span>@enderror
                </div>
                @if ($s['fotoAda'])
                    <label class="sampul-atur__cek"><input type="checkbox" name="slot[{{ $i }}][hapus_foto]" value="1" @checked(old("slot.$i.hapus_foto"))> Hapus foto unggahan, pakai foto dari data tempat</label>
                @endif
            </section>
        @endforeach
    </div>
    <template data-template-slot>
        <section class="p-panel s6" aria-labelledby="slot-__INDEX__" data-slot>
            <header><h2 id="slot-__INDEX__">Slot __NOMOR__</h2></header>
            <figure class="sampul-atur__pratinjau">
                <img src="" alt="" width="640" height="360" data-pratinjau hidden>
                <div class="p-kosong" data-kosong>Slot kosong</div>
                <figcaption data-sumber></figcaption>
            </figure>
            <div class="p-field">
                <label for="slot-__INDEX__-slug">Tempat wisata</label>
                <select id="slot-__INDEX__-slug" name="slot[__INDEX__][slug]" data-pilih-tempat>
                    <option value="" data-foto="">Kosong</option>
                    @foreach ($pilihanTempat as $t)
                        <option value="{{ $t['slug'] }}" data-foto="{{ $fotoKecil($t['foto'] ?? null) }}" data-tanpa-foto="{{ empty($t['foto']) ? '1' : '' }}">{{ $t['nama'] }}{{ empty($t['foto']) ? ' (belum ada foto)' : '' }}</option>
                    @endforeach
                </select>
            </div>
            <div class="p-field">
                <label for="slot-__INDEX__-foto">Ganti dengan foto sendiri (opsional)</label>
                <input id="slot-__INDEX__-foto" type="file" name="slot[__INDEX__][foto]" accept="image/jpeg,image/png,image/webp" data-unggah aria-describedby="slot-__INDEX__-bantu">
                <span class="bantu" id="slot-__INDEX__-bantu">JPG, PNG, atau WEBP, minimal 1200 x 600 piksel, maksimal 4 MB. Bagian tengah foto paling aman karena tepinya terpotong di ponsel.</span>
            </div>
        </section>
    </template>
    <div class="sampul-atur__aksi">
        <button class="p-btn" type="button" data-tambah-slot>Tambah slot</button>
        <span class="bantu" data-jumlah-slot aria-live="polite">{{ count($slot) }} dari {{ $maksSlot }} slot</span>
        <button class="p-btn p-btn--utama" type="submit">Simpan sampul</button>
        <button class="p-btn" type="submit" name="otomatis" value="1" formnovalidate>Kembalikan ke otomatis</button>
    </div>
</form>

<section class="p-panel" aria-labelledby="judul-tampil">
    <header>
        <h2 id="judul-tampil">Tampil di beranda sekarang</h2>
        <span class="meta">{{ $diatur ? 'pilihan super admin' : 'otomatis, empat tempat pertama yang punya foto' }}</span>
    </header>
    <ol class="sampul-atur__urutan">
        @foreach ($tampilSekarang as $n => $h)
            <li>
                <img src="{{ str_starts_with($h['berkas'], 'storage/') ? asset($h['berkas']) : $fotoKecil($h['berkas']) }}" alt="" width="320" height="180" loading="lazy">
                <b>{{ $n + 1 }}. {{ $h['nama'] }}</b>
            </li>
        @endforeach
    </ol>
</section>
@endsection
