@extends('layouts.panel')
@php use App\Support\Katalog; $label = ['menunggu' => 'Belum tayang (lama)', 'tayang' => 'Tayang', 'ditolak' => 'Ditolak (lama)']; @endphp
@section('judul', 'Ulasan pengunjung')

@section('isi')
<div class="p-judul"><div><h1>Ulasan pengunjung</h1><p class="sub">Ulasan baru langsung tampil. Admin dan superadmin dapat menghapus ulasan yang tidak sesuai.</p></div></div>
<nav class="p-tabs" aria-label="Status ulasan">
    @foreach ($label as $k => $l)<a href="{{ route('admin.ulasan', ['tab' => $k]) }}" @if ($tab === $k) aria-current="true" @endif>{{ $l }}<span class="n">{{ $jumlah[$k] ?? 0 }}</span></a>@endforeach
</nav>
@if ($ulasan)
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
@else
    <div class="p-kosong">{{ ['menunggu' => 'Tidak ada ulasan lama yang menunggu penayangan.', 'tayang' => 'Belum ada ulasan yang ditayangkan.', 'ditolak' => 'Belum ada ulasan lama yang ditolak.'][$tab] }}</div>
@endif
@endsection
