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
