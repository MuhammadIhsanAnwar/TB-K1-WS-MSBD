@extends('layouts.panel')
@section('judul', 'Pesan pengguna')

@section('isi')
<div class="p-judul">
    <div><h1>Pesan pengguna</h1><p class="sub">Pesan hanya tampil kepada admin yang dituju atau superadmin sesuai kategorinya.</p></div>
</div>

<form class="p-alat" method="get" action="{{ route('admin.pesan') }}" role="search">
    <label class="sr-only" for="pesan-q">Cari nama, email, tempat, kategori, atau isi pesan</label>
    <input id="pesan-q" type="search" name="q" value="{{ $q }}" placeholder="Cari pesan…">
    <button class="p-btn p-btn--teks" type="submit">Cari</button>
    <span class="hitung">{{ $pesan->count() }} pesan</span>
</form>

@if ($pesan->isNotEmpty())
    <div class="p-baris">
        @foreach ($pesan as $item)
            <a href="{{ route('admin.pesan.detail', $item['id']) }}" aria-label="Buka pesan dari {{ $item['nama_pengguna'] }}">
                <span class="num">{{ $loop->iteration }}</span>
                <span class="dua">
                    <b>{{ $item['nama_pengguna'] }} <span class="meta">· {{ $item['email_pengguna'] }}</span></b>
                    <span>{{ \Illuminate\Support\Str::limit($item['pesan'], 180) }}</span>
                    <span class="meta">
                        {{ \App\Support\PesanLayanan::labelKategori($item['kategori']) }}
                        @if ($item['nama_tempat']) · {{ $item['nama_tempat'] }} @endif
                        · {{ \Illuminate\Support\Carbon::parse($item['created_at'])->locale('id')->translatedFormat('j M Y, H.i') }}
                    </span>
                </span>
                <span class="p-pill {{ $item['status'] === 'dibalas' ? 'pos' : 'warn' }}">
                    {{ $item['status'] === 'dibalas' ? 'Sudah dibalas' : 'Baru' }}
                    @if (count($item['balasan'])) · {{ count($item['balasan']) }} balasan @endif
                </span>
            </a>
        @endforeach
    </div>
@else
    <div class="p-kosong">{{ $q === '' ? 'Belum ada pesan yang menjadi tanggung jawab akun ini.' : 'Tidak ada pesan yang cocok dengan pencarian.' }}</div>
@endif
@endsection
