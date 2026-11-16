@extends('layouts.situs')
@section('judul', 'Hubungi Admin')
@section('deskripsi', 'Kirim pertanyaan tentang tempat wisata, akun MedanVisit, atau hal lainnya.')

@section('isi')
<section class="mv-hubungi">
    <div class="mv-wadah">
        <header class="mv-hubungi__kepala">
            <p class="mv-hubungi__label">Bantuan MedanVisit</p>
            <h1>Hubungi Admin</h1>
            <p>Pilih topik pesanmu agar pertanyaan diteruskan kepada admin yang tepat.</p>
        </header>

        <form class="mv-hubungi__form" method="post" action="{{ route('hubungi.admin.kirim') }}" data-hubungi-admin>
            @csrf
            <div class="isian">
                <label for="kategori-pesan">Kategori</label>
                <select id="kategori-pesan" name="kategori" required data-hubungi-kategori>
                    <option value="">Pilih kategori pesan</option>
                    <option value="tempat" @selected(old('kategori') === 'tempat')>Tempat</option>
                    <option value="akun" @selected(old('kategori') === 'akun')>Akun</option>
                    <option value="lainnya" @selected(old('kategori') === 'lainnya')>Lainnya</option>
                </select>
                @error('kategori')<p class="isian__galat">{{ $message }}</p>@enderror
            </div>

            <div class="isian" data-pesan-tempat @if (old('kategori') !== 'tempat') hidden @endif>
                <label for="tempat-pesan">Tempat wisata</label>
                <select id="tempat-pesan" name="tempat_slug" @if (old('kategori') !== 'tempat') disabled @endif data-hubungi-tempat>
                    <option value="">Pilih tempat wisata</option>
                    @foreach ($tempat as $item)
                        <option value="{{ $item['slug'] }}" @selected(old('tempat_slug') === $item['slug'])>{{ $item['nama'] }}</option>
                    @endforeach
                </select>
                <span class="isian__bantuan">Pertanyaan tentang tempat akan diteruskan hanya kepada admin pengelola tempat tersebut.</span>
                @error('tempat_slug')<p class="isian__galat">{{ $message }}</p>@enderror
            </div>

            <p class="mv-hubungi__tujuan" aria-live="polite" data-pesan-tujuan>Pilih kategori untuk melihat tujuan pesan.</p>

            <div class="isian">
                <label for="isi-pesan">Pesan</label>
                <textarea id="isi-pesan" name="pesan" rows="6" minlength="10" maxlength="3000" required placeholder="Jelaskan pertanyaan atau kendalamu...">{{ old('pesan') }}</textarea>
                <span class="isian__bantuan">10–3.000 karakter. Balasan akan dikirim ke email akunmu.</span>
                @error('pesan')<p class="isian__galat">{{ $message }}</p>@enderror
            </div>

            <button class="mv-btn mv-btn--utama" type="submit">Kirim pesan</button>
        </form>
    </div>
</section>
@endsection
