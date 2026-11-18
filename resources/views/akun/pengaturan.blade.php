@extends('layouts.situs')
@section('judul', 'Pengaturan akun')
@section('tanpa-kaki', '1')

@php
    $punyaFoto = ! empty($user['foto_profil']) && is_file(public_path('storage/'.$user['foto_profil']));
    $urlFoto = $punyaFoto ? asset('storage/'.$user['foto_profil']).'?v='.filemtime(public_path('storage/'.$user['foto_profil'])) : null;
    $inisial = mb_strtoupper(mb_substr($user['name'] ?? '?', 0, 1));
    $bergabung = ! empty($user['created_at']) ? \Illuminate\Support\Carbon::parse($user['created_at'])->locale('id')->translatedFormat('j F Y') : null;
    $wisatawan = ($user['peran'] ?? null) === 'wisatawan';
@endphp

@section('isi')
<div class="alat" data-pengaturan>
    <div class="alat__judul">
        <div>
            <h1>Pengaturan akun</h1>
            <p class="mv-meta-garis"><span>Profil, data diri, dan keamanan akun</span></p>
        </div>
        <a class="p-btn" href="{{ route('akun') }}">Kembali ke akun saya</a>
    </div>
    @if ($wisatawan)
        @include('partials.subnav-akun')
    @endif

    <div class="p-grid akun-atur">
        {{-- ringkasan profil --}}
        <aside class="p-panel s4 akun-atur__profil" aria-labelledby="judul-profil">
            <div class="akun-atur__foto">
                @if ($urlFoto)
                    <img src="{{ $urlFoto }}" alt="Foto profil {{ $user['name'] }}" width="96" height="96" style="width:96px;height:96px;aspect-ratio:1;object-fit:cover;border-radius:50%" data-foto-sekarang>
                @else
                    <span class="akun-atur__inisial" aria-hidden="true" data-foto-sekarang>{{ $inisial }}</span>
                @endif
            </div>
            <h2 id="judul-profil">{{ $user['name'] }}</h2>
            <p class="akun-atur__email">{{ $user['email'] }}</p>
            <dl class="akun-atur__fakta">
                <div><dt>Peran</dt><dd>{{ ucfirst($user['peran'] ?? 'wisatawan') }}</dd></div>
                @if ($bergabung)<div><dt>Bergabung</dt><dd>{{ $bergabung }}</dd></div>@endif
                <div><dt>Email</dt><dd>{{ ! empty($user['email_verified_at']) ? 'Terverifikasi' : 'Belum diverifikasi' }}</dd></div>
                <div><dt>Masuk dengan</dt><dd>{{ $akunGoogle ? 'Google' : 'Email dan password' }}</dd></div>
            </dl>
            @if ($wisatawan)
                <div class="akun-atur__angka" aria-label="Aktivitasmu">
                    <a href="{{ route('tiket.saya') }}"><b>{{ $jumlah['pesanan'] }}</b><span>pesanan tiket</span></a>
                    <a href="{{ route('ulasan.saya') }}"><b>{{ $jumlah['ulasan'] }}</b><span>ulasan</span></a>
                    <a href="{{ route('rencana.saya') }}"><b>{{ $jumlah['rencana'] }}</b><span>rencana</span></a>
                </div>
                <p class="akun-atur__bantuan">Ada kendala dengan akunmu? <a class="mv-tautan" href="{{ route('hubungi.admin') }}">Hubungi admin</a></p>
            @endif
        </aside>

        {{-- formulir --}}
        <form method="post" action="{{ route('akun.pengaturan') }}" enctype="multipart/form-data" class="s8 akun-atur__form">
            @csrf
            <section class="p-panel" aria-labelledby="judul-data-diri">
                <header><h2 id="judul-data-diri">Data diri</h2></header>
                <div class="isian">
                    <label for="foto_profil">Foto profil</label>
                    <div class="akun-atur__unggah">
                        <img alt="" width="64" height="64" data-pratinjau-foto hidden>
                        <input id="foto_profil" type="file" name="foto_profil" accept="image/jpeg,image/png,image/gif,image/webp" data-input-foto aria-describedby="bantu-foto" @error('foto_profil') aria-invalid="true" @enderror>
                    </div>
                    <p class="isian__bantuan" id="bantu-foto" data-bantu-foto>JPG, PNG, GIF, atau WEBP; maksimal 2 MB. Pratinjau muncul sebelum disimpan.</p>
                    @error('foto_profil')<p class="isian__galat">{{ $message }}</p>@enderror
                </div>
                <div class="isian">
                    <label for="nama">Nama</label>
                    <input id="nama" type="text" name="nama" value="{{ old('nama', $user['name']) }}" maxlength="60" autocomplete="name" required @error('nama') aria-invalid="true" @enderror>
                    @error('nama')<p class="isian__galat">{{ $message }}</p>@enderror
                </div>
                <div class="akun-atur__dua">
            <div class="isian">
                <label for="password">Password baru</label>
                <input id="password" type="password" name="password" autocomplete="new-password">
                <p class="isian__bantuan">{{ $akunGoogle ? 'Password akun ini dikelola melalui Google dan tidak dapat diubah di sini.' : 'Kosongkan jika tidak ingin mengubah password.' }}</p>
                @error('password')<p class="isian__galat">{{ $message }}</p>@enderror
            </div>
            <div class="isian">
                <label for="password_confirmation">Ulangi password baru</label>
                <input id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password">
            </div>
        </fieldset>
        <button class="mv-btn mv-btn--utama" type="submit" style="margin-top:24px">Simpan perubahan</button>
    </form>
</div>

@if (session('pesan') || session('galat') || $errors->any())
    @php
        $berhasil = session()->has('pesan');
        $pesanStatus = session('pesan') ?? session('galat') ?? $errors->first();
    @endphp
    <dialog class="mv-dialog-pengaturan" data-dialog-pengaturan role="alertdialog" aria-modal="true" aria-labelledby="judul-status-pengaturan" aria-describedby="isi-status-pengaturan">
        <div class="mv-dialog-pengaturan__isi">
            <span class="mv-dialog-pengaturan__ikon {{ $berhasil ? 'mv-dialog-pengaturan__ikon--sukses' : 'mv-dialog-pengaturan__ikon--galat' }}" aria-hidden="true">{{ $berhasil ? '✓' : '!' }}</span>
            <h2 id="judul-status-pengaturan">{{ $berhasil ? 'Perubahan berhasil' : 'Perubahan belum berhasil' }}</h2>
            <p id="isi-status-pengaturan">{{ $pesanStatus }}</p>
            <button type="button" class="mv-btn mv-btn--utama" data-tutup-dialog-pengaturan>OK</button>
        </div>
    </dialog>
@endif
@endsection
