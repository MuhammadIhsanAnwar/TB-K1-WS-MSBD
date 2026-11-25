@extends('layouts.panel')
@php use App\Support\Katalog; @endphp
@section('judul', 'Ulasan pengunjung')

@section('isi')
<div class="p-judul"><div><h1>Ulasan pengunjung</h1><p class="sub">Ulasan baru langsung tampil. Admin dan superadmin dapat menghapus ulasan yang tidak sesuai.</p></div></div>
<nav class="p-tabs" aria-label="Status ulasan">
    <a href="{{ route('admin.ulasan') }}" aria-current="true">Tayang<span class="n">{{ $jumlah }}</span></a>
</nav>
<div class="p-ulasan-kontrol">
    <p class="p-ulasan-kontrol__rentang">
        @if ($ulasan->count())
            Menampilkan {{ $ulasan->firstItem() }}&ndash;{{ $ulasan->lastItem() }} dari {{ $ulasan->total() }} ulasan
        @else
            Tidak ada ulasan untuk ditampilkan
        @endif
    </p>
    <form class="p-per-halaman" method="get" action="{{ route('admin.ulasan') }}">
        <label for="ulasan-per-halaman">Tampilkan</label>
        <select id="ulasan-per-halaman" name="per_halaman">
            @foreach ([10, 25, 50, 100] as $opsi)
                <option value="{{ $opsi }}" @selected($perHalaman === $opsi)>{{ $opsi }}</option>
            @endforeach
        </select>
        <span>per halaman</span>
        <button class="p-btn p-btn--teks" type="submit">Terapkan</button>
    </form>
</div>
@if ($ulasan->count())
    <div class="p-baris">
        @foreach ($ulasan as $ul)
            <div style="align-items:flex-start">
                <span class="dua" style="flex:1">
                    <b>{{ Katalog::get($ul['tempat'])['nama'] }} <x-bintang :n="$ul['bintang']" /></b>
                    <span style="font-size:14px;color:var(--p-text);margin-top:6px">{{ $ul['teks'] }}</span>
                    <span>{{ $ul['nama'] }} ({{ $ul['email'] }}), {{ \Illuminate\Support\Carbon::parse($ul['waktu'])->locale('id')->translatedFormat('j M Y, H.i') }}</span>
                </span>
                <form method="post" action="{{ route('admin.ulasan.hapus', $ul['id']) }}" onsubmit="return confirm('Hapus ulasan ini secara permanen?')">
                    @csrf @method('DELETE')
                    <button class="p-btn p-btn--bahaya" type="submit">Hapus ulasan</button>
                </form>
            </div>
        @endforeach
    </div>
    @if ($ulasan->lastPage() > 1)
        <nav class="p-paginasi" aria-label="Halaman ulasan">
            <div class="p-paginasi__arah">
                @if ($ulasan->onFirstPage())
                    <span class="p-btn p-btn--teks" aria-disabled="true">Sebelumnya</span>
                @else
                    <a class="p-btn p-btn--teks" href="{{ $ulasan->previousPageUrl() }}">Sebelumnya</a>
                @endif
            </div>
            <div class="p-paginasi__angka">
                @foreach ($ulasan->getUrlRange(max(1, $ulasan->currentPage() - 2), min($ulasan->lastPage(), $ulasan->currentPage() + 2)) as $nomor => $url)
                    @if ($nomor === $ulasan->currentPage())
                        <span class="p-btn p-btn--utama" aria-current="page">{{ $nomor }}</span>
                    @else
                        <a class="p-btn" href="{{ $url }}">{{ $nomor }}</a>
                    @endif
                @endforeach
            </div>
            <div class="p-paginasi__arah">
                @if ($ulasan->hasMorePages())
                    <a class="p-btn p-btn--teks" href="{{ $ulasan->nextPageUrl() }}">Berikutnya</a>
                @else
                    <span class="p-btn p-btn--teks" aria-disabled="true">Berikutnya</span>
                @endif
            </div>
        </nav>
    @endif
@else
    <div class="p-kosong">Belum ada ulasan yang ditayangkan.</div>
@endif
@endsection
