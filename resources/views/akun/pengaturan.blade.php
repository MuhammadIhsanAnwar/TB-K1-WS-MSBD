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
            @endif
            <input id="foto_profil" type="file" name="foto_profil" accept="image/*" @error('foto_profil') aria-invalid="true" @enderror>
            <p class="isian__bantuan">JPG, PNG, GIF, atau WEBP; maksimal 2 MB.</p>
            @error('foto_profil')<p class="isian__galat">{{ $message }}</p>@enderror
        </div>
        <div class="isian">
            <label for="nama">Nama</label>
            <input id="nama" type="text" name="nama" value="{{ old('nama', $user['name']) }}" maxlength="60" required>
            @error('nama')<p class="isian__galat">{{ $message }}</p>@enderror
        </div>
        <div class="isian">
            <label for="tanggal_lahir">Tanggal lahir</label>
            <input id="tanggal_lahir" type="date" name="tanggal_lahir" value="{{ old('tanggal_lahir', $user['tanggal_lahir'] ?? '') }}" required>
            @error('tanggal_lahir')<p class="isian__galat">{{ $message }}</p>@enderror
        </div>
        <div class="isian">
            <label for="no_hp">Nomor HP</label>
            <input id="no_hp" type="tel" name="no_hp" value="{{ old('no_hp', $user['no_hp'] ?? '') }}" inputmode="numeric" pattern="08[0-9]{8,13}" maxlength="15" required>
            <p class="isian__bantuan">Hanya angka dan harus diawali 08.</p>
            @error('no_hp')<p class="isian__galat">{{ $message }}</p>@enderror
        </div>
        <fieldset @disabled($akunGoogle) style="border:0;padding:0;margin:24px 0 0">
            <legend>Password (opsional)</legend>
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
