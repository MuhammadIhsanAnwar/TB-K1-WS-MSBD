@extends('layouts.panel')
@section('judul', 'Kelola akun admin')

@section('isi')
<div class="p-judul">
    <div><h1>Kelola akun</h1><p class="sub">Akun admin ditautkan ke tempat wisata yang mereka kelola. Wisatawan hanya dapat dilihat dan dihapus.</p></div>
</div>
@if ($errors->any())
    <div class="p-pesan p-pesan--galat" role="alert">{{ $errors->first() }}</div>
@endif

<nav class="p-alat" aria-label="Filter peran akun" style="margin-bottom:16px">
    <a class="p-btn {{ $peran === 'admin' ? 'p-btn--utama' : '' }}" href="{{ route('admin.akun', ['peran' => 'admin']) }}"
       @if ($peran === 'admin') aria-current="page" @endif>Akun admin ({{ $jumlah['admin'] ?? 0 }})</a>
    <a class="p-btn {{ $peran === 'wisatawan' ? 'p-btn--utama' : '' }}" href="{{ route('admin.akun', ['peran' => 'wisatawan']) }}"
       @if ($peran === 'wisatawan') aria-current="page" @endif>Akun wisatawan ({{ $jumlah['wisatawan'] ?? 0 }})</a>
</nav>

<div class="p-kpi">
    <div><div class="label">Total akun</div><div class="nilai">{{ number_format(array_sum($jumlah), 0, ',', '.') }}</div><div class="ket">seluruh peran</div></div>
    <div><div class="label">Akun admin</div><div class="nilai">{{ number_format($jumlah['admin'] ?? 0, 0, ',', '.') }}</div><div class="ket">masuk daftar kelola di bawah</div></div>
    <div><div class="label">Superadmin</div><div class="nilai">{{ number_format($jumlah['superadmin'] ?? 0, 0, ',', '.') }}</div><div class="ket">tidak dapat diubah dari halaman ini</div></div>
    <div><div class="label">Wisatawan</div><div class="nilai">{{ number_format($jumlah['wisatawan'] ?? 0, 0, ',', '.') }}</div><div class="ket">tidak dapat diubah dari halaman ini</div></div>
</div>

<section class="p-panel" aria-labelledby="judul-akun-baru">
    <header><h2 id="judul-akun-baru">Buat akun admin</h2><span class="meta">Wajib ditautkan ke tempat wisata</span></header>
    @if ($tempatTersedia->isNotEmpty())
        <form class="p-form" method="post" action="{{ route('admin.akun.buat') }}">
            @csrf
            <div class="p-dua">
                <div class="p-field"><label for="nama-baru">Nama pengguna</label><input id="nama-baru" name="nama" value="{{ old('nama') }}" required maxlength="60" autocomplete="name">@error('nama')<span class="galat">{{ $message }}</span>@enderror</div>
                <div class="p-field"><label for="email-baru">Email</label><input id="email-baru" type="email" name="email" value="{{ old('email') }}" required maxlength="120" autocomplete="email">@error('email')<span class="galat">{{ $message }}</span>@enderror</div>
            </div>
            <div class="p-field"><label for="tempat-baru">Tempat wisata yang dikelola</label><select id="tempat-baru" name="tempat_slug" required><option value="">Pilih tempat wisata</option>@foreach ($tempatTersedia as $tempat)<option value="{{ $tempat['slug'] }}" @selected(old('tempat_slug') === $tempat['slug'])>{{ $tempat['nama'] }}</option>@endforeach</select>@error('tempat_slug')<span class="galat">{{ $message }}</span>@enderror</div>
            <div class="p-dua" style="align-items:start">
                <div class="p-field"><label for="password-baru">Kata sandi sementara</label><input id="password-baru" type="password" name="password" required autocomplete="new-password"><span class="bantu">Minimal 8 karakter: huruf kecil, kapital, angka, dan simbol.</span>@error('password')<span class="galat">{{ $message }}</span>@enderror</div>
                <div class="p-field"><label for="password-confirm">Ulangi kata sandi</label><input id="password-confirm" type="password" name="password_confirmation" required autocomplete="new-password"><span class="bantu">&nbsp;</span></div>
            </div>
            <div><button class="p-btn p-btn--utama" type="submit">Buat akun admin</button></div>
        </form>
    @else
        <div class="p-kosong">Semua tempat wisata sudah ditautkan. <a class="p-tautan-kartu" href="{{ route('admin.tempat.baru') }}">Tambahkan tempat wisata</a> sebelum membuat akun admin baru.</div>
    @endif
</section>

@if ($peran === 'wisatawan')
<section class="p-panel" style="margin-top:24px" aria-labelledby="judul-daftar-wisatawan">
    <header><h2 id="judul-daftar-wisatawan">Akun wisatawan</h2><span class="meta">{{ $akun->count() }} akun · nama dan email</span></header>
    @forelse ($akun as $user)
        <details class="p-baris" style="padding:12px 0">
            <summary style="cursor:pointer">
                <b>{{ $user['name'] }}</b>
                <span class="meta">{{ $user['email'] }}@if (! empty($user['email_verified_at'])) · terverifikasi @endif @if ($user['ditangguhkan']) · <b style="color:var(--p-negative)">ditangguhkan</b> @endif</span>
            </summary>
            <div style="display:grid;gap:16px;padding:16px 0">
                @if ($user['ditangguhkan'])
                    <div class="p-pesan p-pesan--info" role="status">
                        Akun ditangguhkan sejak {{ \Carbon\Carbon::parse($user['ditangguhkan_pada'])->format('d/m/Y H:i') }}.
                        @if (! empty($user['alasan_tangguh'])) Alasan: {{ $user['alasan_tangguh'] }}@endif
                    </div>
                @endif
                <form method="post" action="{{ route('admin.akun.tangguhkan', $user['id']) }}" style="display:grid;gap:8px">
                    @csrf @method('PATCH')
                    @if (! $user['ditangguhkan'])
                        <div class="p-field"><label for="alasan-{{ $user['id'] }}">Alasan penangguhan (opsional)</label><input id="alasan-{{ $user['id'] }}" name="alasan" maxlength="240" placeholder="Contoh: menyalahgunakan fitur ulasan"></div>
                        <div><button class="p-btn p-btn--bahaya" type="submit" onclick="return confirm('Tangguhkan akun wisatawan {{ $user['email'] }}?')">Tangguhkan akun</button></div>
                    @else
                        <div><button class="p-btn p-btn--utama" type="submit">Buka penangguhan</button></div>
                    @endif
                </form>
                <form method="post" action="{{ route('admin.akun.hapus', $user['id']) }}"
                      onsubmit="return confirm('Hapus akun wisatawan {{ $user['email'] }}? Tindakan ini tidak dapat dibatalkan.')">
                    @csrf @method('DELETE')
                    <button class="p-btn p-btn--bahaya" type="submit">Hapus akun wisatawan</button>
                </form>
            </div>
        </details>
    @empty
        <div class="p-kosong">Belum ada akun wisatawan.</div>
    @endforelse
</section>
@else
<section class="p-panel" style="margin-top:24px" aria-labelledby="judul-daftar-admin">
    <header><h2 id="judul-daftar-admin">Akun admin</h2><span class="meta">{{ $akun->count() }} akun · nama, email, dan tempat kelolaan</span></header>
    @forelse ($akun as $user)
        <details class="p-baris" style="padding:12px 0">
            <summary style="cursor:pointer">
                <b>{{ $user['name'] }}</b>
                <span class="meta">{{ $user['email'] }} · {{ $user['tempat']->count() }} tempat @if ($user['ditangguhkan']) · <b style="color:var(--p-negative)">ditangguhkan</b> @endif</span>
            </summary>
            <div style="display:grid;gap:16px;padding:16px 0">
                <div>
                    <b>Tempat wisata yang dikelola</b>
                    @if ($user['tempat']->isNotEmpty())
                        <div class="p-baris">
                            @foreach ($user['tempat'] as $tempat)
                                <div>
                                    <a class="p-tautan-kartu" href="{{ route('admin.entitas.ubah', $tempat['slug']) }}">{{ $tempat['nama'] }}</a>
                                    @if ($user['tempat']->count() > 1)
                                        <form method="post" action="{{ route('admin.akun.tempat.lepas', [$user['id'], $tempat['slug']]) }}">
                                            @csrf @method('DELETE')
                                            <button class="p-btn p-btn--bahaya" type="submit">Lepas</button>
                                        </form>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="meta">Belum ada tempat yang ditautkan. Tautkan tempat agar akun ini dapat mengelolanya.</p>
                    @endif
                </div>

                @if ($tempatTersedia->isNotEmpty())
                    <form class="p-alat" method="post" action="{{ route('admin.akun.tempat.tautkan', $user['id']) }}">
                        @csrf
                        <label class="sr-only" for="tempat-{{ $user['id'] }}">Tautkan tempat wisata</label>
                        <select id="tempat-{{ $user['id'] }}" name="tempat_slug" required>
                            <option value="">Tautkan tempat wisata lain</option>
                            @foreach ($tempatTersedia as $tempat)
                                <option value="{{ $tempat['slug'] }}">{{ $tempat['nama'] }}</option>
                            @endforeach
                        </select>
                        <button class="p-btn" type="submit">Tautkan</button>
                    </form>
                @endif

                <form class="p-form" method="post" action="{{ route('admin.akun.ubah', $user['id']) }}" style="max-width:none">
                    @csrf @method('PATCH')
                    <div class="p-dua" style="align-items:start">
                        <div class="p-field"><label for="password-{{ $user['id'] }}">Kata sandi admin baru</label><input id="password-{{ $user['id'] }}" type="password" name="password" required autocomplete="new-password"><span class="bantu">Minimal 8 karakter: huruf kecil, kapital, angka, dan simbol.</span></div>
                        <div class="p-field"><label for="password-confirm-{{ $user['id'] }}">Ulangi kata sandi baru</label><input id="password-confirm-{{ $user['id'] }}" type="password" name="password_confirmation" required autocomplete="new-password"><span class="bantu">&nbsp;</span></div>
                    </div>
                    <div><button class="p-btn p-btn--utama" type="submit">Ubah kata sandi admin</button></div>
                </form>

                <form method="post" action="{{ route('admin.akun.tangguhkan', $user['id']) }}" style="display:grid;gap:8px">
                    @csrf @method('PATCH')
                    @if (! $user['ditangguhkan'])
                        <div class="p-field"><label for="alasan-admin-{{ $user['id'] }}">Alasan penangguhan (opsional)</label><input id="alasan-admin-{{ $user['id'] }}" name="alasan" maxlength="240" placeholder="Contoh: tidak aktif mengelola tempat"></div>
                        <div><button class="p-btn p-btn--bahaya" type="submit" onclick="return confirm('Tangguhkan akun admin {{ $user['email'] }}?')">Tangguhkan akun</button></div>
                    @else
                        <div class="p-pesan p-pesan--info" role="status">Ditangguhkan sejak {{ \Carbon\Carbon::parse($user['ditangguhkan_pada'])->format('d/m/Y H:i') }}.@if (! empty($user['alasan_tangguh'])) Alasan: {{ $user['alasan_tangguh'] }}@endif</div>
                        <div><button class="p-btn p-btn--utama" type="submit">Buka penangguhan</button></div>
                    @endif
                </form>

                <form method="post" action="{{ route('admin.akun.hapus', $user['id']) }}"
                      onsubmit="return confirm('Hapus akun admin {{ $user['email'] }}? Tindakan ini tidak dapat dibatalkan.')">
                    @csrf @method('DELETE')
                    <button class="p-btn p-btn--bahaya" type="submit">Hapus akun admin</button>
                </form>
            </div>
        </details>
    @empty
        <div class="p-kosong">Belum ada akun admin.</div>
    @endforelse
</section>
@endif
@endsection
