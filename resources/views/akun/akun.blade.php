@extends('layouts.situs')
@section('judul', 'Akun')
@section('tanpa-kaki', '1')

@section('isi')
<div class="alat">
    <div class="alat__judul">
        <div style="display:flex;gap:16px;align-items:center">
            @if (!empty($u['foto_profil']) && is_file(public_path('storage/'.$u['foto_profil'])))
                <img src="{{ asset('storage/'.$u['foto_profil']) }}?v={{ filemtime(public_path('storage/'.$u['foto_profil'])) }}"
                     alt="Foto profil {{ $u['nama'] }}" width="64" height="64"
                     style="object-fit:cover;border-radius:50%">
            @else
                <span aria-hidden="true" style="display:grid;place-items:center;width:64px;height:64px;border-radius:50%;background:var(--mv-permukaan-2,#eee);font-size:24px;font-weight:600">{{ mb_strtoupper(mb_substr($u['nama'] ?? '?', 0, 1)) }}</span>
            @endif
            <div>
                <h1>{{ $u['nama'] }}</h1>
                <p class="mv-meta-garis"><span>{{ $u['email'] }}</span>@if (!empty($u['peran']))<span>{{ ucfirst($u['peran']) }}</span>@endif</p>
            </div>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
            <a class="p-btn" href="{{ route('akun.pengaturan') }}">Pengaturan akun</a>
            <form method="post" action="{{ route('keluar') }}">@csrf<button class="p-btn" type="submit">Keluar</button></form>
        </div>
    </div>
    @if (($u['peran'] ?? null) === 'wisatawan')
        @include('partials.subnav-akun')
    @endif
    @if (($u['peran'] ?? null) === 'wisatawan')
    <div class="p-kpi">
        <div><div class="label">Pesanan tiket</div><div class="nilai">{{ $pesanan }}</div><div class="ket">semua status</div></div>
        <div><div class="label">Rencana</div><div class="nilai">{{ $rencana }}</div><div class="ket">tersimpan</div></div>
        <div><div class="label">Ulasan</div><div class="nilai">{{ $ulasan }}</div><div class="ket">yang kamu tulis</div></div>
    </div>
    <div class="p-grid">
        <section class="p-panel s6" aria-labelledby="judul-langkah">
            <header><h2 id="judul-langkah">Yang bisa kamu lakukan</h2></header>
            <div class="p-baris">
                <a href="{{ route('tiket.saya') }}"><span class="dua"><b>Lihat e-tiketmu</b><span>{{ $pesanan }} pesanan</span></span><span class="kanan">Tiket saya</span></a>
                <a href="{{ route('rencana') }}"><span class="dua"><b>Susun rute perjalanan</b><span>Dari minat, waktu, dan cara berpindah</span></span><span class="kanan">Perencana</span></a>
                <a href="{{ route('ulasan.saya') }}"><span class="dua"><b>Ulasan yang kamu tulis</b><span>{{ $ulasan }} ulasan</span></span><span class="kanan">Ulasan saya</span></a>
                <a href="{{ route('akun.pengaturan') }}"><span class="dua"><b>Perbarui profil</b><span>Nama, foto, nomor HP, password</span></span><span class="kanan">Pengaturan</span></a>
            </div>
        </section>
        <section class="p-panel s6" aria-labelledby="judul-tindakan-akun">
            <header><h2 id="judul-tindakan-akun">Kelola akun</h2></header>
            <p class="isian__bantuan">Setiap tindakan perlu dikonfirmasi dengan kode 6 digit yang dikirim ke email akunmu.</p>
            <div class="p-baris">
                <form method="post" action="{{ route('akun.tindakan.kirim') }}">
                    @csrf
                    <input type="hidden" name="tindakan" value="nonaktifkan">
                    <button class="p-btn" type="submit">Nonaktifkan akun</button>
                    <p class="isian__bantuan">Akun dapat dipulihkan dengan masuk kembali dalam 3 bulan. Setelah itu, akun dihapus permanen.</p>
                </form>
                <form method="post" action="{{ route('akun.tindakan.kirim') }}" onsubmit="return confirm('Akun akan dihapus permanen setelah kode OTP diverifikasi. Tindakan ini tidak dapat dibatalkan. Lanjutkan?')">
                    @csrf
                    <input type="hidden" name="tindakan" value="hapus">
                    <button class="p-btn" type="submit">Hapus akun permanen</button>
                    <p class="isian__bantuan">Profil dan akunmu akan dihapus permanen setelah verifikasi email.</p>
                </form>
            </div>
            @if ($tindakanAkunPending)
                <form method="post" action="{{ route('akun.tindakan.verifikasi') }}" class="isian" style="margin-top:20px">
                    @csrf
                    <label for="kode-tindakan-akun">Kode OTP untuk {{ $tindakanAkunPending['tindakan'] === 'nonaktifkan' ? 'menonaktifkan' : 'menghapus permanen' }} akun</label>
                    <input id="kode-tindakan-akun" name="kode" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required aria-describedby="bantuan-kode-tindakan">
                    <p class="isian__bantuan" id="bantuan-kode-tindakan">Kode berlaku selama 10 menit. Maksimal 5 kali percobaan.</p>
                    @error('kode')<p class="isian__galat">{{ $message }}</p>@enderror
                    <div style="display:flex;gap:8px;flex-wrap:wrap">
                        <button class="mv-btn mv-btn--utama" type="submit">Verifikasi tindakan</button>
                    </div>
                </form>
                <form method="post" action="{{ route('akun.tindakan.batalkan') }}" style="margin-top:8px">
                    @csrf
                    <button class="p-btn" type="submit">Batalkan verifikasi</button>
                </form>
            @endif
            @if (session('pesan') || session('galat'))
                <p role="status" class="isian__bantuan" style="margin-top:12px">{{ session('pesan') ?? session('galat') }}</p>
            @endif
        </section>
    </div>
    @endif
</div>
@endsection
