@extends('layouts.situs')
@section('judul', 'Konfirmasi tindakan akun')

@section('isi')
<div class="alat">
    <section class="p-panel" style="max-width:560px;margin:40px auto">
        <header>
            <h1>{{ $tindakan === 'nonaktifkan' ? 'Nonaktifkan akun' : 'Hapus akun permanen' }}</h1>
        </header>
        @if ($terverifikasi)
            <p>
                @if ($tindakan === 'nonaktifkan')
                    Password terverifikasi. Apakah anda yakin ingin menonaktifkan akun?
                    Kamu dapat mengaktifkannya kembali dengan masuk dalam 3 bulan.
                @else
                    Password terverifikasi. Apakah anda yakin ingin menghapus akun secara permanen?
                    Tindakan ini tidak dapat dibatalkan.
                @endif
            </p>
            <form method="post" action="{{ request()->fullUrl() }}" style="display:flex;gap:8px;flex-wrap:wrap">
                @csrf
                <button class="mv-btn mv-btn--utama" type="submit" name="pilihan" value="ya">
                    Ya, {{ $tindakan === 'nonaktifkan' ? 'nonaktifkan akun' : 'hapus akun permanen' }}
                </button>
                <button class="p-btn" type="submit" name="pilihan" value="tidak">Tidak</button>
            </form>
        @else
            <p>Masukkan password akunmu untuk melanjutkan tindakan ini.</p>
            <form method="post" action="{{ request()->fullUrl() }}" class="isian">
                @csrf
                <input type="hidden" name="pilihan" value="password">
                <label for="password-tindakan-akun">Password akun</label>
                <input id="password-tindakan-akun" name="password" type="password" autocomplete="current-password" required autofocus>
                @error('password')<p class="isian__galat">{{ $message }}</p>@enderror
                @if (session('galat'))
                    <p role="alert" class="isian__galat">{{ session('galat') }}</p>
                @endif
                <button class="mv-btn mv-btn--utama" type="submit">Lanjutkan</button>
            </form>
        @endif
    </section>
</div>
@endsection
