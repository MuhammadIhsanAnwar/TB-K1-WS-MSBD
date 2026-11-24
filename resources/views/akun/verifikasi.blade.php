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
            @if ($email)
                <form method="post" action="{{ route('verifikasi.kirim') }}" style="margin-top:24px" data-verifikasi-form>
                    @csrf
                    <input type="hidden" name="email" value="{{ $email }}">
                    <button class="mv-btn mv-btn--utama mv-btn--lebar" type="submit" data-verifikasi-kirim @disabled($cooldownVerifikasi > 0)>Kirim ulang email verifikasi</button>
                </form>
                @if ($cooldownVerifikasi > 0)
                    <p class="mv-otentik__hitung-mundur" role="status" aria-live="off" data-verifikasi-hitung data-sisa="{{ $cooldownVerifikasi }}">
                        Tombol aktif kembali dalam <span data-verifikasi-sisa>{{ $labelCooldownVerifikasi }}</span>.
                    </p>
                @endif
            @endif
            @if (session('pesan_sukses'))
                <div class="mv-otentik__pesan mv-otentik__pesan--sukses" role="status">{{ session('pesan_sukses') }}</div>
            @endif
            @if (session('pesan'))
                <div class="mv-otentik__pesan" role="status">{{ session('pesan') }}</div>
            @endif
            <p style="margin-top:20px"><a class="mv-tautan" href="{{ route('masuk') }}">Kembali ke halaman masuk</a></p>
        </div>
    </div>
    <div class="mv-otentik__visual">
        <x-foto :berkas="$tempatSorotan[0]['foto'] ?? 'kesawan.jpg'" :alt="$tempatSorotan[0]['nama'] ?? 'Medan'" :utama="true" />
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

@push('skrip')
    @if ($email)
        <script>
            (() => {
                const form = document.querySelector('[data-verifikasi-form]');
                const button = document.querySelector('[data-verifikasi-kirim]');
                const countdown = document.querySelector('[data-verifikasi-hitung]');

                if (form && button) {
                    form.addEventListener('submit', () => {
                        button.disabled = true;
                    });
                }

                if (!button || !countdown) return;

                const label = countdown.querySelector('[data-verifikasi-sisa]');
                const deadline = Date.now() + Number(countdown.dataset.sisa) * 1000;

                const tick = () => {
                    const remaining = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
                    if (remaining <= 0) {
                        button.disabled = false;
                        countdown.remove();
                        return;
                    }

                    const hours = Math.floor(remaining / 3600);
                    const minutes = Math.floor((remaining % 3600) / 60);
                    const seconds = remaining % 60;
                    label.textContent = hours > 0
                        ? `${hours} jam ${minutes} menit ${seconds} detik`
                        : minutes > 0
                            ? `${minutes} menit ${seconds} detik`
                            : `${seconds} detik`;

                    window.setTimeout(tick, 1000);
                };

                tick();
            })();
        </script>
    @endif
@endpush
