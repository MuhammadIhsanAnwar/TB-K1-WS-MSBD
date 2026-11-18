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
