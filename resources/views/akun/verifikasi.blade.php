@extends('layouts.situs')
@section('judul', 'Verifikasi email')
@section('tanpa-kepala', '1')
@section('tanpa-kaki', '1')

@section('isi')
<div class="mv-otentik">
    <div class="mv-otentik__panel">
        <div class="mv-otentik__kotak">
            <h1>Verifikasi email</h1>
            <p class="mv-otentik__pengantar">{{ $pesan }}</p>
            @if (session('pesan'))
                <div class="mv-otentik__pesan" role="status">{{ session('pesan') }}</div>
            @endif
            @if ($email)
                <form method="post" action="{{ route('verifikasi.kirim') }}" style="margin-top:24px">
                    @csrf
                    <input type="hidden" name="email" value="{{ $email }}">
                    <button class="mv-btn mv-btn--utama mv-btn--lebar" type="submit">Kirim ulang email verifikasi</button>
                </form>
            @endif
            <p style="margin-top:20px"><a class="mv-tautan" href="{{ route('masuk') }}">Kembali ke halaman masuk</a></p>
        </div>
    </div>
    <div class="mv-otentik__visual">
        <x-foto :berkas="$tempatSorotan[0]['foto'] ?? 'peta-medan-1930-kota-lama.jpg'" :alt="$tempatSorotan[0]['nama'] ?? 'Medan'" :utama="true" />
        <div class="mv-otentik__lapisan" aria-hidden="true"></div>

        <div class="mv-otentik__visual-atas" data-naik style="--i:0">
            <a class="mv-otentik__logo" href="{{ route('beranda') }}" aria-label="MedanVisit, kembali ke beranda">
                <img src="{{ asset('storage/logo_app/svg/MedanVisit_logo_horizontal_gelap.svg') }}" alt="MedanVisit" width="736" height="256">
            </a>
        </div>

        <div class="mv-otentik__visual-bawah">
            <p class="mv-otentik__tajuk-sisi" data-naik style="--i:1">Satu akun wisata Medan.</p>
            <ul class="mv-otentik__manfaat" data-naik style="--i:2">
                <li>Simpan e-tiket ber-QR dan rencana perjalanan di MedanVisit</li>
            </ul>
            <div class="mv-otentik__galeri" data-naik style="--i:3" aria-label="Tempat wisata di MedanVisit">
                @foreach ($tempatSorotan as $tempat)
                    @if (!empty($tempat['foto']))
                        <figure>
                            <x-foto :berkas="$tempat['foto']" :alt="$tempat['nama']" :kecil="true" />
                            <figcaption>{{ $tempat['nama'] }}</figcaption>
                        </figure>
                    @endif
                @endforeach
            </div>
        </div>
    </div>
</div>
@endsection
