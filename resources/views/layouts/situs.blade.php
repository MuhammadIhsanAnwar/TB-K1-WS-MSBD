<!DOCTYPE html>
<html lang="id" prefix="og: https://ogp.me/ns#">
<head>
    @php
        $judulMeta = trim($__env->yieldContent('judul', 'Jelajahi Kota Medan'));
        $deskripsiMeta = trim($__env->yieldContent('deskripsi', 'Jelajahi wisata Kota Medan melalui MedanVisit. Temukan tempat bersejarah, kuliner, informasi tiket, dan rute perjalanan.'));
        $urlMeta = url()->current();
        $fotoMeta = trim($__env->yieldContent('foto-og')) ?: asset('storage/foto_wisata/kesawan.jpg');
    @endphp
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $judulMeta }} | MedanVisit</title>
    <meta name="description" content="{{ $deskripsiMeta }}">
    <link rel="canonical" href="{{ $urlMeta }}">
    <meta property="og:locale" content="id_ID">
    <meta property="og:site_name" content="MedanVisit">
    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ $judulMeta }} | MedanVisit">
    <meta property="og:description" content="{{ $deskripsiMeta }}">
    <meta property="og:url" content="{{ $urlMeta }}">
    <meta property="og:image" content="{{ $fotoMeta }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $judulMeta }} | MedanVisit">
    <meta name="twitter:description" content="{{ $deskripsiMeta }}">
    <meta name="twitter:image" content="{{ $fotoMeta }}">
    <meta name="theme-color" content="#0f3b2e">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="alternate icon" href="{{ asset('favicon.ico') }}">
    <script>
        document.documentElement.classList.add('js');
    </script>
    @if (request()->routeIs('beranda'))
        <script>
            window.addEventListener('pageshow', (event) => {
                if (event.persisted) window.location.reload();
            });
        </script>
    @endif
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('kepala')
</head>
<body class="situs @yield('kelas-body') @hasSection('tanpa-kaki') situs--alat @endif" vocab="https://schema.org/" typeof="WebPage">
    @if (request()->routeIs('beranda'))
        @include('partials.pemuatan-beranda')
    @endif
    <a class="lewati" href="#isi">Lewati ke isi utama</a>
    @hasSection('tanpa-kepala')
    @else
        @include('partials.kepala')
    @endif
    @unless (request()->routeIs('verifikasi'))
        @unless (request()->routeIs('akun.pengaturan'))
            @include('partials.pesan')
        @endunless
    @endunless
    @include('partials.sambutan-login')
    <main id="isi">
        @yield('isi')
    </main>
    @hasSection('tanpa-kaki')
    @else
        @php
            $fotoKaki = trim($__env->yieldContent('foto-kaki'));
            if (! $fotoKaki) {
                $fotoKaki = collect(\App\Support\Katalog::tempat())
                    ->first(fn ($tempat) => ! empty($tempat['foto']))['foto'] ?? 'kesawan.jpg';
            }
        @endphp
        @include('partials.kaki', ['ringkas' => ! request()->routeIs('beranda'), 'fotoKaki' => $fotoKaki])
    @endif
    @hasSection('tanpa-kepala')
    @else
            @include('partials.dialog-cari')
            {{-- alur tiket tidak memakai widget agar tidak menutupi tombol pembayaran --}}
            @unless (request()->routeIs('tiket.*', 'checkout', 'pesanan*'))
                @include('partials.asisten-melayang')
        @endunless
    @endif
    @include('partials.lightbox-foto')
    @stack('skrip')
</body>
</html>
