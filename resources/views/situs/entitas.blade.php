@extends('layouts.situs')
@php
    use App\Support\Katalog;
    $kelas = Katalog::KELAS[$e['jenis']];
@endphp
@section('judul', $e['nama'])
@section('deskripsi', $e['ringkas'])

@section('isi')
<div class="detail">
    <article class="detail__baca">
        <header class="detail__kepala">
            <nav class="remah" aria-label="Remah roti">
                <a href="{{ route('beranda') }}">Medan</a><span aria-hidden="true">›</span>
                <span>{{ $kelas }}</span>
            </nav>
            <h1>{{ $e['nama'] }}</h1>
            <p class="detail__ringkas">{{ $e['ringkas'] }}</p>
            <div class="detail__tanda">
                <x-kelas :jenis="$e['jenis']" />
                @if (! empty($e['masa']))<span class="kecil">{{ $e['masa'] }}</span>@endif
            </div>
            <div class="detail__aksi">
                <a class="tombol tombol--garis" href="{{ route('tanya', ['q' => $e['nama']]) }}">Tanya soal {{ \Illuminate\Support\Str::limit($e['nama'], 18) }}</a>
            </div>
        </header>

        @if (! empty($e['foto']) && \App\Support\Katalog::kredit($e['foto']))
            <p class="kecil redup detail__seksi">Foto: {{ \App\Support\Katalog::kredit($e['foto']) }}</p>
        @endif
    </article>

    <div class="detail__layar">
        @if (! empty($e['foto']))
            <x-foto :berkas="$e['foto']" :alt="$e['nama']" :utama="true" class="geser-pelan" />
            <div class="detail__bilah"><span>{{ $e['nama'] }}</span><span>{{ $kelas }}</span></div>
        @else
            <div style="display:grid;place-content:center;gap:12px;padding:24px;text-align:center">
                <x-kelas :jenis="$e['jenis']" />
                <h2>{{ $e['nama'] }}</h2>
                @if (! empty($e['masa']))<p class="redup">{{ $e['masa'] }}</p>@endif
            </div>
            <div class="detail__bilah"><span>{{ $e['nama'] }}</span><span>{{ $kelas }}</span></div>
        @endif
    </div>
</div>
@endsection
