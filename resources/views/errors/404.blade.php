@extends('layouts.situs')
@php
    $populer = array_slice(array_values(\App\Support\Katalog::tempat()), 0, 4);
@endphp
@section('judul', 'Belum ada di peta')
@section('foto-kaki', 'kesawan.jpg')

@section('isi')
<header class="kepala-halaman kepala-halaman--besar">
    <h1>Belum ada di peta</h1>
    <p>Halaman yang kamu cari tidak ditemukan. Mungkin alamatnya salah ketik, atau tempatnya belum masuk MedanVisit.</p>
</header>
<div class="isi-halaman">
    <h2 class="judul" style="font-size:2rem">Tempat yang paling sering dibuka</h2>
    <div class="tempat-populer" style="margin-top:24px">
        @foreach ($populer as $t)
            <a href="{{ route('tempat', $t['slug']) }}">
                <x-foto :berkas="$t['foto'] ?: 'kesawan.jpg'" :alt="$t['nama']" :kecil="true" :segera="true" />
                <b>{{ $t['nama'] }}</b>
                <span class="kecil redup">{{ \App\Support\Katalog::KATEGORI[$t['kategori']] }}, {{ $t['kecamatan'] }}</span>
            </a>
        @endforeach
    </div>
    <p style="margin-top:40px"><a class="tombol tombol--hijau" href="{{ route('beranda') }}">Kembali ke beranda</a></p>
</div>
@endsection
