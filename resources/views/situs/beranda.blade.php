@extends('layouts.situs')

@php
    use App\Support\Katalog;
    use App\Support\Penyimpanan;
    $akunPengelola = in_array(session('pengguna.peran'), ['admin', 'superadmin'], true);
    $semuaTempat = Katalog::tempat();
    $ringkasanUlasan = Penyimpanan::ringkasanUlasan();
    foreach ($semuaTempat as &$tempatStat) {
        $ringkasan = $ringkasanUlasan[$tempatStat['slug']] ?? ['rating' => 0, 'jumlahUlasan' => 0];
        $tempatStat['rating'] = $ringkasan['rating'];
        $tempatStat['jumlahUlasan'] = $ringkasan['jumlahUlasan'];
        $tempatStat['jumlahSuka'] = $jumlahSukaTempat[$tempatStat['slug']] ?? 0;
    }
    unset($tempatStat);
    $jumlahKecamatan = collect($semuaTempat)->pluck('kecamatan')->unique()->count();
    // foto sampul: pilihan super admin (DB 1, panel Sampul beranda), atau otomatis 4 tempat berfoto
    $heroSlide = \App\Support\SampulBeranda::slide($semuaTempat);
    $unggulan = array_column(array_slice($heroSlide, 0, 3), 'slug');
    $pusatPeta = array_key_first($semuaTempat) ?? '';
    $fotoPilihan = collect($semuaTempat)->filter(fn ($t) => ! empty($t['foto']))->values();
    $saranCari = collect($semuaTempat)->take(3);
    $potretTiket = collect($bertiket)->first() ?? collect($semuaTempat)->first() ?? [];
    $petaJson = collect($petaTempat)->map(fn ($t) => [
        'slug' => $t['slug'],
        'nama' => $t['nama'],
        'lat' => $t['lat'],
        'lng' => $t['lng'],
        'kategori' => $t['kategori'],
        'url' => route('tempat', $t['slug']),
        'harga' => Katalog::labelHarga($t),
        'tiket' => ! $akunPengelola && ! empty($t['tiket']) ? route('tiket.pilih', $t['slug']) : null,
    ])->values();
    // Tiga pilihan sampul selalu muncul lebih dulu, lalu enam destinasi berikutnya
    // melengkapi halaman unggulan pertama.
    $urutTempat = collect($unggulan)
        ->map(fn ($slug) => $semuaTempat[$slug] ?? null)
        ->filter()
        ->concat(collect($semuaTempat)->reject(fn ($t) => in_array($t['slug'], $unggulan, true))->sortBy('nama'))
        ->values();
    $perHalamanTempat = 9;
    $jumlahGaleri = $urutTempat->countBy('kategori');
    // bahan saran "Dekat denganmu": jarak dihitung di peramban dari lokasi pengunjung
    $dekatJson = collect($semuaTempat)->filter(fn ($t) => ($t['lat'] ?? 0) > 0)->map(fn ($t) => [
        'nama' => $t['nama'],
        'url' => route('tempat', $t['slug']),
        'kategori' => Katalog::KATEGORI[$t['kategori']] ?? $t['kategori'],
        'lat' => $t['lat'],
        'lng' => $t['lng'],
        'buka' => Katalog::jadwal($t),
    ])->values();
@endphp

@section('isi')
{{-- 1. HERO: Small name, open sky --}}
<section class="mv-hero" data-hero-crossfade aria-labelledby="judul-utama">
    <div class="mv-hero__panggung" aria-hidden="true">
        @foreach ($heroSlide as $idx => $s)
            <figure class="mv-hero__slide {{ $idx === 0 ? 'aktif' : '' }}" data-hero-slide data-nama="{{ $s['nama'] }}" data-sub="{{ $s['sub'] }}">
                <x-foto :berkas="$s['berkas']" :alt="$s['alt']" :utama="$idx === 0" :segera="$idx === 0" />
            </figure>
        @endforeach
        <div class="mv-hero__lapisan"></div>
    </div>

    <div class="mv-hero__isi">
        <div class="mv-hero__utama">
            <h1 id="judul-utama" data-naik style="--i:0">
    Jelajahi Medan dan temukan ceritanya
</h1>

<p class="mv-hero__sub" data-naik style="--i:1">
    Temukan berbagai tempat wisata, tokoh sejarah, budaya, dan kuliner khas Kota Medan. Cari destinasi, kenali kisahnya, dan rencanakan kunjunganmu dengan mudah.
</p>
            <div class="mv-hero__cari-bungkus" data-naik style="--i:2">
                <form action="{{ route('cari') }}" method="get" role="search" data-cari-cepat>
                    <div class="mv-hero__cari">
                        <label class="sr-only" for="input-cari-beranda">Cari tempat, tokoh, atau kuliner di Medan</label>
                        <input id="input-cari-beranda" type="search" name="q" placeholder="Cari tempat, tokoh, atau kuliner (coba: soto dekat istana)" autocomplete="off" required>
                        <button class="mv-btn mv-btn--utama" type="submit">Cari</button>
                    </div>
                    <div class="mv-saran-kotak" data-ui data-saran-hasil role="region" aria-live="polite" hidden></div>
                </form>
                <div class="mv-hero__saran-cepat">
                    <span>Coba ketik:</span>
                    @foreach ($saranCari as $tempat)
                        <a href="{{ route('cari', ['q' => $tempat['nama']]) }}">{{ $tempat['nama'] }}</a>
                    @endforeach
                    <a href="{{ route('tanya') }}">Tanya asisten</a>
                </div>
            </div>

            <div class="mv-hero__aksi" data-naik style="--i:3">
                <a class="mv-btn mv-btn--utama" href="#tempat">Lihat tempat wisata</a>
                <a class="mv-tautan mv-tautan--terang" href="#tiket">Pesan tiket masuk</a>
            </div>
        </div>

        <div class="mv-hero__info-foto" data-naik style="--i:2">
            <div class="mv-hero__label-foto">
                <b data-hero-nama>{{ $heroSlide[0]['nama'] }}</b>
                <span data-hero-sub>{{ $heroSlide[0]['sub'] }}</span>
            </div>
            <div class="mv-hero__kontrol-slide" role="group" aria-label="Kontrol foto sampul">
                @foreach ($heroSlide as $idx => $s)
                    <button type="button" class="mv-hero__titik nums" data-hero-ke="{{ $idx }}" aria-label="Foto {{ $s['nama'] }}" aria-current="{{ $idx === 0 ? 'true' : 'false' }}">{{ $idx + 1 }}</button>
                @endforeach
            </div>
        </div>
    </div>
</section>

{{-- 2. GALLERY: Photo tiles with label strips --}}
<section class="mv-seksi-tempat" id="tempat" aria-labelledby="judul-tempat">
    <div class="mv-wadah">
        <div class="mv-kepala-seksi">
            <h2 id="judul-tempat">Tempat pilihan</h2>
            <p>Jelajahi berbagai tempat wisata dari seluruh kecamatan Medan. <a class="mv-tautan" href="{{ route('peta') }}">Lihat semua di peta</a></p>
        </div>

        <div class="mv-dekat" data-dekat="{{ json_encode($dekatJson) }}" hidden>
            <div class="mv-dekat__kepala">
                <h3>Dekat denganmu</h3>
                <p data-dekat-status role="status" aria-live="polite"></p>
            </div>
            <ol class="mv-dekat__daftar" data-dekat-daftar hidden></ol>
            <div class="mv-dekat__aksi">
                <button class="mv-btn mv-btn--kecil" type="button" data-dekat-minta hidden>Tampilkan tempat terdekat</button>
                <a class="mv-tautan" href="{{ route('peta') }}" data-dekat-peta hidden>Buka peta dengan lokasimu</a>
            </div>
        </div>

        <div class="mv-saring-baris" data-saring-beranda role="group" aria-label="Saring kategori tempat">
            <button type="button" data-filter="semua" aria-pressed="true">Semua</button>
            @foreach ($kategori as $k => $grup)
                @continue(! ($jumlahGaleri[$k] ?? 0))
                <button type="button" data-filter="{{ $k }}" aria-pressed="false">{{ $grup['label'] }}</button>
            @endforeach
        </div>

        <div class="mv-saring-baris" role="group" aria-label="Urutkan tempat wisata">
            <label class="mv-label-urut" for="urut-tempat-beranda">Urutkan:</label>
            <select id="urut-tempat-beranda" data-urut-beranda>
                <option value="bawaan">Urutan utama</option>
                <option value="suka">Like terbanyak</option>
                <option value="ulasan">Ulasan terbanyak</option>
                <option value="rating-tertinggi">Rating tertinggi</option>
                <option value="rating-terendah">Rating terendah</option>
            </select>
        </div>

        <div class="mv-grid-tempat" data-grid-beranda aria-live="polite">
            @foreach ($urutTempat as $idx => $t)
                @php
                    $adalahLebar = in_array($t['slug'], $unggulan, true);
                    $nadaPita = ['hijau', 'krem', 'kuning'][$idx % 3];
                    $sedangBuka = Katalog::bukaSekarang($t);
                    $berkasFoto = $t['foto'] ?: 'kesawan.jpg';
                @endphp
                <a class="mv-kartu-tempat {{ $adalahLebar ? 'mv-kartu-tempat--lebar' : '' }}"
                   href="{{ route('tempat', $t['slug']) }}"
                   data-kategori="{{ $t['kategori'] }}"
                   data-rating="{{ $t['rating'] }}"
                   data-ulasan="{{ $t['jumlahUlasan'] }}"
                   data-suka="{{ $t['jumlahSuka'] }}"
                   @if ($idx >= $perHalamanTempat) hidden @endif>
                    <div class="mv-kartu-tempat__media">
                        <x-foto :berkas="$berkasFoto" :alt="$t['nama']" :kecil="true" />
                    </div>
                    <div class="mv-kartu-tempat__pita mv-kartu-tempat__pita--{{ $nadaPita }}">
                        <span>{{ Katalog::KATEGORI[$t['kategori']] }}</span>
                        <span>{{ $t['kecamatan'] }}</span>
                    </div>
                    <div class="mv-kartu-tempat__isi">
                        <strong class="mv-kartu-tempat__nama">{{ $t['nama'] }}</strong>
                        <span class="mv-kartu-tempat__meta">{{ \Illuminate\Support\Str::limit($t['ringkas'], $adalahLebar ? 115 : 80) }}</span>
                        <div class="mv-kartu-tempat__statistik" aria-label="Statistik {{ $t['nama'] }}">
                            <span><b>{{ number_format($t['jumlahSuka'], 0, ',', '.') }}</b> like</span>
                            <span><b>★ {{ number_format($t['rating'], 1, ',', '.') }}</b> ({{ $t['jumlahUlasan'] }} ulasan)</span>
                        </div>
                        <div class="mv-kartu-tempat__kaki">
                            <span><x-harga :tempat="$t" /></span>
                            @if (Katalog::jadwal($t))
                                <span class="mv-lencana-jam {{ $sedangBuka ? 'mv-lencana-jam--buka' : 'mv-lencana-jam--tutup' }}">{{ $sedangBuka ? 'Buka' : 'Tutup' }}</span>
                            @endif
                        </div>
                    </div>
                </a>
            @endforeach
        </div>
        @if ($urutTempat->count() > $perHalamanTempat)
            <nav class="mv-paginasi-tempat" data-paginasi-beranda aria-label="Halaman tempat wisata">
                <button class="mv-btn mv-btn--garis mv-btn--kecil" type="button" data-halaman-sebelum disabled>← Sebelumnya</button>
                <p class="mv-paginasi-tempat__status" data-halaman-status>Menampilkan 1–{{ min($perHalamanTempat, $urutTempat->count()) }} dari {{ $urutTempat->count() }} tempat</p>
                <button class="mv-btn mv-btn--garis mv-btn--kecil" type="button" data-halaman-sesudah>Berikutnya →</button>
            </nav>
        @endif
    </div>
</section>

{{-- 4. PRICING: Prices like a menu (Tiket tanpa antre) --}}
<section class="mv-seksi-tiket" id="tiket" aria-labelledby="judul-tiket">
    <div class="mv-wadah">
        <div class="mv-menu-tiket">
            <div class="mv-menu-tiket__kiri">
                <h2 id="judul-tiket" data-muncul>Tiket masuk tanpa antre</h2>
                <p style="color:var(--mv-redup)" data-muncul style="--i:1">Pilih tanggal kunjungan dan jumlah pengunjung, bayar dengan QRIS atau virtual account, lalu tunjukkan e-tiket ber-QR di loket masuk.</p>
                <figure class="mv-menu-tiket__potret" data-muncul style="--i:2">
                    <x-foto :berkas="$potretTiket['foto'] ?? 'kesawan.jpg'" :alt="$potretTiket['nama'] ?? 'Kawasan Kota Lama Kesawan'" :kecil="true" />
                </figure>
            </div>

            {{-- daftar harga ala menu: satu baris per tempat, dikelompokkan per kategori; rincian tarif ada di halaman tiket --}}
            <div class="mv-menu-tiket__daftar">
                @foreach (collect($bertiket)->groupBy('kategori') as $kode => $grup)
                    <section class="mv-menu-harga" aria-labelledby="harga-{{ $kode }}">
                        <h3 id="harga-{{ $kode }}">{{ Katalog::KATEGORI[$kode] }}</h3>
                        <ul>
                            @foreach ($grup->sortBy(fn ($t) => Katalog::hargaTermurah($t)) as $t)
                                <li class="mv-menu-harga__baris">
                                    <span class="mv-menu-harga__nama">
                                        <a href="{{ route('tempat', $t['slug']) }}">{{ $t['nama'] }}</a>
                                        <span>{{ $t['kecamatan'] }}, {{ collect($t['tiket'])->map(fn ($h, $j) => $j.' '.Katalog::rupiah($h))->implode(' · ') }}</span>
                                    </span>
                                    <span class="mv-menu-harga__harga nums">{{ Katalog::labelHarga($t) }}</span>
                                    @unless ($akunPengelola)
                                        <a class="mv-btn mv-btn--utama mv-btn--kecil" href="{{ route('tiket.pilih', $t['slug']) }}" aria-label="Pesan tiket {{ $t['nama'] }}">Pesan</a>
                                    @endunless
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endforeach
            </div>
        </div>
    </div>
</section>

{{-- 5. Rencana perjalanan --}}
<section class="mv-seksi-rencana" aria-labelledby="judul-rencana">
    <div class="mv-wadah">
        <div class="mv-rencana-ajak" data-muncul>
            <div>
                <h2 id="judul-rencana">Rencana perjalanan</h2>
                <p>Susun rute wisata sesuai minat, waktu kunjungan, dan cara berpindahmu.</p>
            </div>
            @unless ($akunPengelola)
                <a class="mv-btn mv-btn--utama" href="{{ route('rencana') }}">Buat rencana</a>
            @endunless
        </div>
    </div>
</section>

{{-- 6. FEATURES: Peta kota --}}
<section class="mv-seksi-paspor" aria-labelledby="judul-peta-paspor">
    <div class="mv-wadah">
        <div class="mv-paspor-grid">
            <div class="mv-paspor-peta">
                <h2 id="judul-peta-paspor">Peta kota</h2>
                <p style="color:var(--mv-redup)">Jelajahi persebaran tempat wisata di seluruh Kota Medan.</p>
                <div class="mv-paspor-peta__kanvas" data-peta data-peta-mode="ringkas" data-pusat="{{ $pusatPeta }}" data-tempat="{{ json_encode($petaJson) }}">
                    <p class="peta-diam" style="padding:20px">Peta interaktif tempat wisata Medan dimuat di sini.</p>
                </div>
                <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:center">
                    <a class="mv-btn mv-btn--hijau" href="{{ route('peta') }}">Buka peta lengkap</a>
                </div>
            </div>

            <div class="mv-paspor-baris">
                <div class="mv-paspor-butir">
                    <div>
                        <h3>Peta Wisata Medan</h3>
                        <p>Saring berdasarkan kategori, tempat yang sedang buka sekarang, ketersediaan tiket, atau urutkan dari jarak terdekat.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

{{-- 7. FAQ: Questions beside a tall photograph --}}
<section class="mv-seksi-faq" aria-labelledby="judul-tanya">
    <div class="mv-wadah">
        <div class="mv-faq-grid">
            <div class="mv-faq-kiri">
                <h2 id="judul-tanya" data-muncul>Sebelum berangkat</h2>
                <ul class="mv-faq-fakta" data-muncul style="--i:1">
                    <li>Pembayaran tiket mendukung QRIS, virtual account, dan dompet digital.</li>
                    <li>Masjid dan rumah ibadah dikunjungi dengan pakaian sopan di luar waktu ibadah.</li>
                </ul>

                <div class="mv-faq-daftar">
                    <details>
                        <summary>Bagaimana cara memesan tiket masuk?</summary>
                        <p>Buka halaman tempat bertiket, pilih tanggal dan jumlah pengunjung, isi nama serta email pemesan, lalu selesaikan pembayaran. E-tiket ber-QR langsung tersimpan di menu Tiket saya.</p>
                    </details>
                    <details>
                        <summary>Apakah e-tiket tetap bisa dibuka saat sinyal lemah?</summary>
                        <p>Bisa. Pada halaman e-tiket, tekan tombol <strong>Simpan PDF</strong> sebelum berangkat agar kode QR tersimpan di ponsel dan siap dipindai tanpa koneksi internet.</p>
                    </details>
                </div>

                <div style="display:flex;flex-wrap:wrap;gap:14px 20px;align-items:center">
                    <a class="mv-btn mv-btn--hijau" href="{{ route('tanya') }}">Tanya asisten MedanVisit</a>
                    <a class="mv-tautan" href="{{ route('ketentuan') }}">Ketentuan pembelian</a>
                </div>
            </div>

            <div class="mv-faq-kanan">
                <x-foto :berkas="$fotoPilihan->get(0)['foto'] ?? 'kesawan.jpg'" :alt="$fotoPilihan->get(0)['nama'] ?? 'Kawasan Kota Lama Kesawan'" class="mv-faq-kanan__tinggi" />
                <x-foto :berkas="$fotoPilihan->get(1)['foto'] ?? ($fotoPilihan->get(0)['foto'] ?? 'kesawan.jpg')" :alt="$fotoPilihan->get(1)['nama'] ?? ($fotoPilihan->get(0)['nama'] ?? 'Kawasan Kota Lama Kesawan')" class="mv-faq-kanan__kecil" />
            </div>
        </div>
    </div>
</section>
@endsection
