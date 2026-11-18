@extends('layouts.panel')
@section('judul', 'Sampul beranda')

@php
    use App\Support\Foto;
    $cadangan = 'peta-medan-1930-kota-lama.jpg';
    $fotoKecil = fn (?string $berkas) => Foto::url('kecil/'.pathinfo($berkas ?: $cadangan, PATHINFO_FILENAME).'.jpg');
    $tempatPerSlug = collect($pilihanTempat)->keyBy('slug');
@endphp
