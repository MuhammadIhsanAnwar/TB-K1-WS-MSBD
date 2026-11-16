@extends('layouts.panel')
@section('judul', 'Detail pesan pengguna')

@section('isi')
<div class="p-judul">
    <div>
        <h1>Pesan dari {{ $pesan['nama_pengguna'] }}</h1>
        <p class="sub">{{ $pesan['email_pengguna'] }} · {{ \App\Support\PesanLayanan::labelKategori($pesan['kategori']) }}@if ($pesan['nama_tempat']) · {{ $pesan['nama_tempat'] }}@endif</p>
    </div>
    <a class="p-btn" href="{{ route('admin.pesan') }}">Kembali ke pesan</a>
</div>

<section class="p-panel" aria-labelledby="pesan-awal">
    <header>
        <h2 id="pesan-awal">Pesan awal</h2>
        <span class="meta">{{ \Illuminate\Support\Carbon::parse($pesan['created_at'])->locale('id')->translatedFormat('j M Y, H.i') }}</span>
    </header>
    <p style="white-space:pre-wrap">{{ $pesan['pesan'] }}</p>
</section>

@if ($pesan['balasan'])
    <section class="p-panel" style="margin-top:16px" aria-labelledby="riwayat-balasan">
        <header><h2 id="riwayat-balasan">Riwayat balasan</h2></header>
        <div class="p-baris">
            @foreach ($pesan['balasan'] as $balasan)
                <div>
                    <span class="dua">
                        <b>{{ $balasan['peran_pengirim'] === 'superadmin' ? 'Superadmin' : 'Admin' }}</b>
                        <span style="white-space:pre-wrap">{{ $balasan['isi'] }}</span>
                        <span class="meta">{{ \Illuminate\Support\Carbon::parse($balasan['created_at'])->locale('id')->translatedFormat('j M Y, H.i') }}</span>
                    </span>
                    <span class="p-pill {{ $balasan['status_email'] === 'terkirim' ? 'pos' : ($balasan['status_email'] === 'gagal' ? 'neg' : 'warn') }}">
                        Email {{ $balasan['status_email'] }}
                    </span>
                </div>
            @endforeach
        </div>
    </section>
@endif

<section class="p-panel" style="margin-top:16px" aria-labelledby="judul-balas">
    <header><h2 id="judul-balas">Balas melalui email</h2><span class="meta">Balasan disimpan dan dikirim ke alamat pengguna di atas.</span></header>
    <form class="p-form" method="post" action="{{ route('admin.pesan.balas', $pesan['id']) }}">
        @csrf
        <div class="p-field">
            <label for="balasan-pesan">Isi balasan</label>
            <textarea id="balasan-pesan" name="balasan" rows="6" minlength="2" maxlength="3000" required>{{ old('balasan') }}</textarea>
            @error('balasan')<span class="galat">{{ $message }}</span>@enderror
        </div>
        <div><button class="p-btn p-btn--utama" type="submit">Kirim balasan</button></div>
    </form>
</section>
@endsection
