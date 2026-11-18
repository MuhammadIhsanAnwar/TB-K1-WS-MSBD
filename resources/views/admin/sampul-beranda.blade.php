@extends('layouts.panel')
@section('judul', 'Sampul beranda')

@php
    use App\Support\Foto;
    $cadangan = 'peta-medan-1930-kota-lama.jpg';
    $fotoKecil = fn (?string $berkas) => Foto::url('kecil/'.pathinfo($berkas ?: $cadangan, PATHINFO_FILENAME).'.jpg');
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

<form method="post" action="{{ route('admin.beranda') }}" enctype="multipart/form-data" data-sampul-atur>
    @csrf
    <div class="p-grid sampul-atur">
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
