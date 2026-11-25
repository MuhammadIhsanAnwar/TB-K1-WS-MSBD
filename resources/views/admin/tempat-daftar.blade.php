@extends('layouts.panel')
@php use App\Support\Katalog; @endphp
@section('judul', 'Kelola tempat wisata')

@section('isi')
<div class="p-judul">
    <div>
        <h1>Kelola tempat wisata</h1>
        <p class="sub">Daftar ini mengikuti data tempat wisata pada katalog Database 3.</p>
    </div>
    <a class="p-btn p-btn--utama" href="{{ route('admin.tempat.baru') }}">Tambah tempat wisata</a>
</div>

<form class="p-alat" action="{{ route('admin.tempat.daftar') }}" method="get" role="search">
    <label class="sr-only" for="tempat-q">Cari nama tempat</label>
    <input id="tempat-q" type="search" name="q" value="{{ $q }}" placeholder="Cari nama tempat…">
    <label for="tempat-kategori">Kategori</label>
    <select id="tempat-kategori" name="kategori">
        <option value="">Semua kategori</option>
        @foreach (Katalog::KATEGORI as $nilai => $label)
            <option value="{{ $nilai }}" @selected($kategori === $nilai)>{{ $label }}</option>
        @endforeach
    </select>
    <label for="tempat-per-halaman">Tampilkan</label>
    <select id="tempat-per-halaman" name="per_halaman">
        @foreach ([10, 25, 50, 100] as $opsi)
            <option value="{{ $opsi }}" @selected($perHalaman === $opsi)>{{ $opsi }}</option>
        @endforeach
    </select>
    <span>baris per halaman</span>
    <button class="p-btn p-btn--teks" type="submit">Terapkan</button>
</form>

<p class="p-ulasan-kontrol__rentang">
    @if ($tempat->count())
        Menampilkan {{ $tempat->firstItem() }}&ndash;{{ $tempat->lastItem() }} dari {{ $tempat->total() }} tempat wisata
    @else
        Tidak ada tempat wisata yang sesuai dengan filter.
    @endif
</p>

@if ($tempat->count())
    <div class="p-tabel-bungkus">
        <table class="p-tabel jadi-daftar">
            <thead>
                <tr><th>Nama tempat wisata</th><th>Kategori</th><th>Kecamatan</th><th>Jam operasional</th><th class="n"><span class="sr-only">Aksi</span></th></tr>
            </thead>
            <tbody>
                @foreach ($tempat as $item)
                    <tr>
                        <td><a class="nama" href="{{ route('admin.entitas.ubah', $item['slug']) }}">{{ $item['nama'] }}</a></td>
                        <td class="sekunder">{{ Katalog::KATEGORI[$item['kategori'] ?? ''] ?? 'Tidak dikategorikan' }}</td>
                        <td class="sekunder">{{ $item['kecamatan'] ?: 'Tidak tercatat' }}</td>
                        <td class="sekunder">{{ $item['jam'] ?: 'Belum diatur' }}</td>
                        <td class="n"><a class="p-btn" href="{{ route('admin.entitas.ubah', $item['slug']) }}">Kelola</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @if ($tempat->lastPage() > 1)
        <nav class="p-paginasi" aria-label="Halaman tempat wisata">
            <div class="p-paginasi__arah">
                @if ($tempat->onFirstPage())
                    <span class="p-btn p-btn--teks" aria-disabled="true">Sebelumnya</span>
                @else
                    <a class="p-btn p-btn--teks" href="{{ $tempat->previousPageUrl() }}">Sebelumnya</a>
                @endif
            </div>
            <div class="p-paginasi__angka">
                @foreach ($tempat->getUrlRange(max(1, $tempat->currentPage() - 2), min($tempat->lastPage(), $tempat->currentPage() + 2)) as $nomor => $url)
                    @if ($nomor === $tempat->currentPage())
                        <span class="p-btn p-btn--utama" aria-current="page">{{ $nomor }}</span>
                    @else
                        <a class="p-btn" href="{{ $url }}">{{ $nomor }}</a>
                    @endif
                @endforeach
            </div>
            <div class="p-paginasi__arah">
                @if ($tempat->hasMorePages())
                    <a class="p-btn p-btn--teks" href="{{ $tempat->nextPageUrl() }}">Berikutnya</a>
                @else
                    <span class="p-btn p-btn--teks" aria-disabled="true">Berikutnya</span>
                @endif
            </div>
        </nav>
    @endif
@endif
@endsection
