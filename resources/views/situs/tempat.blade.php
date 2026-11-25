
@extends('layouts.situs')
@php
    use App\Support\Foto;
    use App\Support\Katalog;

    $t = $e;
    $u = session('pengguna');
    $akunPengelola = in_array($u['peran'] ?? null, ['admin', 'superadmin'], true);
    $statusOperasional = Katalog::statusOperasional($t);
    $buka = $statusOperasional === true;
    $gagal = session('checkin_gagal');
    $kategori = Katalog::KATEGORI[$t['kategori']];
    $tingkatRamai = $ramai >= 8 ? 'Ramai' : ($ramai >= 3 ? 'Sedang' : ($ramai > 0 ? 'Lengang' : null));
    $kreditUtama = ! empty($t['foto']) ? Katalog::kredit($t['foto']) : null;

    $lokasi = [
        'nama' => $t['nama'],
        'lat' => $t['lat'],
        'lng' => $t['lng'],
        'kategori' => $t['kategori'],
        'radius' => $t['radius_checkin'],
        'sekitar' => collect($dekat)->map(fn ($d) => [
            'nama' => $d['nama'],
            'lat' => $d['lat'],
            'lng' => $d['lng'],
            'kategori' => $d['kategori'],
            'url' => route('tempat', $d['slug']),
            'jarak' => $d['jarak'],
        ])->values(),
    ];

    $osm = 'https://www.openstreetmap.org/?mlat='.$t['lat'].'&mlon='.$t['lng'].'#map=17/'.$t['lat'].'/'.$t['lng'];
@endphp

@section('judul', $t['nama'])
@section('deskripsi', $t['ringkas'])
@section('foto-kaki', $t['foto'] ?: 'storage/logo_app/png/MedanVisit_logo_horizontal_gelap.png')
@section('foto-og', $t['foto'] ? Foto::url($t['foto']) : asset('storage/logo_app/png/MedanVisit_logo_horizontal_gelap.png'))

@php
    $ulasanSaya = collect($ulasan)->firstWhere('id_pengguna', (int) ($u['id'] ?? 0));
@endphp
@push('kepala')
    <script type="application/ld+json">{!! json_encode(\App\Support\Semantik::jsonldTempat($t, $ratingTempat), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
@endpush

@section('isi')
<section class="mv-tempat-atas" aria-labelledby="judul-tempat-detail" property="mainEntity" typeof="TouristAttraction" resource="{{ \App\Support\Semantik::BASIS.'id/'.$t['slug'] }}">
    <div class="mv-wadah">
        <nav class="mv-remah" aria-label="Jejak halaman">
            <a href="{{ route('beranda') }}">Beranda</a>
            <span aria-hidden="true">&gt;</span>
            <a href="{{ route('peta') }}">Tempat &amp; Peta</a>
            <span aria-hidden="true">&gt;</span>
            <span>{{ $kategori }}</span>
        </nav>

        <div class="mv-tempat-kepala">
            <div class="mv-tempat-kepala__teks">
                <h1 id="judul-tempat-detail" property="name">{{ $t['nama'] }}</h1>
                <p class="mv-tempat-kepala__ringkas" property="description">{{ $t['ringkas'] }}</p>
                <div class="mv-tempat-tanda">
                    <x-kelas jenis="tempat" :label="$kategori" />
                    <span class="kecil">{{ implode(', ', array_filter([$t['kelurahan'], $t['kecamatan']])) }}</span>
                    @if ($statusOperasional !== null)
                        <span class="mv-lencana-jam {{ $buka ? 'mv-lencana-jam--buka' : 'mv-lencana-jam--tutup' }}">{{ $buka ? 'Buka sekarang' : 'Tutup sekarang' }}</span>
                    @endif
                </div>
            </div>

            <div class="mv-tempat-kepala__meta">
                <div class="mv-tempat-statistik" aria-label="Statistik tempat">
                    <span><b>{{ number_format($jumlahSuka, 0, ',', '.') }}</b> like</span>
                    <span><b>{{ number_format($ratingTempat['rata'], 1, ',', '.') }}</b> rating</span>
                    <span><b>{{ count($ulasan) }}</b> ulasan</span>
                </div>
                @if ($t['tiket'] && ! $akunPengelola)
                    <a class="mv-btn mv-btn--utama" href="{{ route('tiket.pilih', $t['slug']) }}">Pesan tiket mulai {{ Katalog::rupiah(Katalog::hargaTermurah($t)) }}</a>
                @endif
                @unless ($akunPengelola)
                    <a class="mv-btn mv-btn--garis" href="{{ route('rencana', ['minat' => ['sejarah', 'religi', 'kuliner'], 'durasi' => 4, 'moda' => ['jalan', 'motor'], 'mulai' => $t['slug']]) }}">Rute dari sini</a>
                @endunless
                <form method="POST" action="{{ route('tempat.sukai', $t['slug']) }}">
                    @csrf
                    <button type="submit" class="mv-btn mv-btn--garis mv-btn--suka {{ $sudahDisukai ? 'mv-btn--suka-aktif' : '' }}" aria-pressed="{{ $sudahDisukai ? 'true' : 'false' }}">
                        <svg aria-hidden="true" focusable="false" width="18" height="18" viewBox="0 0 24 24"
                            fill="{{ $sudahDisukai ? 'currentColor' : 'none' }}" stroke="currentColor"
                            stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8l1.1 1.1L12 21l7.8-7.5 1.1-1.1a5.5 5.5 0 0 0-.1-7.8Z"/>
                        </svg>
                        {{ $sudahDisukai ? 'Batal suka' : 'Suka tempat ini' }}
                        ({{ number_format($jumlahSuka, 0, ',', '.') }})
                    </button>
                </form>
            </div>
        </div>

        <div class="mv-tempat-media">
            @if (! empty($t['foto_lama']))
                <div class="lorong" data-lorong>
                    <x-foto :berkas="$t['foto']" :alt="$t['nama'].' masa kini'" :utama="true" style="object-position: {{ $t['foto_posisi'] ?? '50% 50%' }}" />
                    <x-foto :berkas="$t['foto_lama']" :alt="$t['nama'].' tahun '.$t['tahun_foto_lama']" class="lorong__lama" :utama="true" style="object-position: {{ $t['foto_lama_posisi'] ?? '50% 50%' }}" />
                    <span class="lorong__label lorong__label--kiri">Dulu, {{ $t['tahun_foto_lama'] }}</span>
                    <span class="lorong__label lorong__label--kanan">Kini</span>
                    <label class="sr-only" for="geser-lorong">Geser untuk membandingkan foto dulu dan kini</label>
                    <input class="lorong__geser" id="geser-lorong" type="range" min="0" max="100" value="50">
                    <span class="lorong__pegangan" aria-hidden="true"></span>
                </div>

                <div class="mv-tempat-media__bilah">
                    <span>Lorong Waktu: geser pengatur di tengah foto untuk membandingkan wajah {{ $t['tahun_foto_lama'] }} dan masa kini</span>
                    <button type="button" class="mv-perbesar-btn" data-buka-lightbox data-src="{{ Foto::url($t['foto']) }}" data-judul="{{ $t['nama'] }}" data-kredit="{{ $kreditUtama ? 'Foto: '.$kreditUtama : '' }}">Perbesar foto</button>
                </div>
            @elseif ($t['foto'])
                <x-foto :berkas="$t['foto']" :alt="$t['foto_keterangan'] ?? $t['nama']" :utama="true" style="object-position: {{ $t['foto_posisi'] ?? '50% 50%' }}" />

                <div class="mv-tempat-media__bilah">
                    <span>{{ $t['foto_keterangan'] ?? $t['nama'] }}, {{ $t['kecamatan'] }}</span>
                    <button type="button" class="mv-perbesar-btn" data-buka-lightbox data-src="{{ Foto::url($t['foto']) }}" data-judul="{{ $t['nama'] }}" data-kredit="{{ $kreditUtama ? 'Foto: '.$kreditUtama : '' }}">Perbesar foto</button>
                </div>
            @endif

            <div class="mv-tempat-peta" data-ui data-peta data-peta-mode="lokasi" data-lokasi="{{ json_encode($lokasi) }}" tabindex="0" aria-label="Peta lokasi {{ $t['nama'] }} dan tempat di sekitarnya">
                <p class="peta-diam">Peta lokasi dimuat di sini.</p>
            </div>

            <div class="mv-tempat-media__bilah">
                <span>Lingkaran kuning: area check-in {{ $t['radius_checkin'] }} m dari {{ $t['nama'] }}.@if (! empty($t['koordinat_perkiraan'])) Titiknya masih perkiraan.@endif</span>
                <a class="mv-perbesar-btn" href="{{ $osm }}" rel="noopener">Buka di OpenStreetMap</a>
            </div>
        </div>
    </div>
</section>

<nav class="mv-lompat-seksi tidak-dicetak" data-ui aria-label="Lompat ke bagian halaman">
    <div class="mv-wadah mv-lompat-seksi__dalam">
        <a href="#info">Info kunjungan</a>
        <a href="#dekat">Dekat dari sini</a>
        <a href="#ulasan">Ulasan</a>
    </div>
</nav>

<div class="mv-wadah mv-tempat-isi">
    <article class="mv-tempat-kolom">
        <section class="mv-seksi-detail" id="info" aria-labelledby="judul-info">
            <h2 id="judul-info">Info kunjungan</h2>
            <dl class="mv-fakta-grid">
                <div class="mv-fakta-kotak">
                    <dt>Alamat lengkap</dt>
                    <dd>{{ implode(', ', array_filter([$t['alamat'], $t['kelurahan'], $t['kecamatan']])) }}</dd>
                </div>
                <div class="mv-fakta-kotak">
                    <dt>Jam operasional</dt>
                    <dd>{{ $t['jam'] }} @if (! empty($t['jam_contoh']))<span class="contoh">contoh</span>@endif</dd>
                </div>
                @if ($t['fasilitas'])
                    <div class="mv-fakta-kotak">
                        <dt>Fasilitas tersedia</dt>
                        <dd>{{ implode(', ', $t['fasilitas']) }}</dd>
                    </div>
                @endif
                @if ($t['cara'])
                    <div class="mv-fakta-kotak">
                        <dt>Cara menuju lokasi</dt>
                        <dd>{{ implode('. ', $t['cara']) }}.</dd>
                    </div>
                @endif
            </dl>

            <div class="ramai" style="margin-top:20px;padding:18px 20px;background:var(--mv-putih)">
                <div class="ramai__judul">
                    <span>Kepadatan pengunjung saat ini</span>
                    <span>{{ $tingkatRamai ?? 'Belum ada catatan jam ini' }}</span>
                </div>
                <div class="ramai__trek" aria-hidden="true">
                    <div class="ramai__isi" style="width: {{ min(100, $ramai * 10) }}%"></div>
                </div>
                <p class="kecil redup"><span class="nums">{{ $ramai }}</span> check-in tercatat lewat MedanVisit dalam 3 jam terakhir.</p>
            </div>
        </section>

        <section class="mv-seksi-detail" id="dekat" aria-labelledby="judul-dekat">
            <h2 id="judul-dekat">Dekat dari sini</h2>
            <div class="rekomendasi">
                @foreach ($dekat as $d)
                    <a href="{{ route('tempat', $d['slug']) }}" style="background:var(--mv-putih);padding:14px">
                        @if ($d['foto'])
                            <x-foto :berkas="$d['foto']" :alt="$d['nama']" :kecil="true" :segera="true" />
                        @else
                            <span class="tanpa-foto" aria-hidden="true"></span>
                        @endif
                        <span>
                            <b>{{ $d['nama'] }}</b>
                            <span class="redup nums">{{ number_format($d['jarak'], 0, ',', '.') }} m, sekitar {{ max(1, round($d['jarak'] / 75)) }} menit jalan kaki</span>
                            <span class="redup">{{ Katalog::KATEGORI[$d['kategori']] }} di {{ $d['kecamatan'] }}</span>
                        </span>
                    </a>
                @endforeach
            </div>
        </section>

        <section class="mv-seksi-detail" id="ulasan" aria-labelledby="judul-ulasan">
            <h2 id="judul-ulasan">Ulasan pengunjung</h2>
            @if ($ratingTempat['jumlah'])
                <p class="mv-rating-ringkas">
                    <x-bintang :n="(int) round($ratingTempat['rata'])" />
                    <b>{{ number_format($ratingTempat['rata'], 1, ',', '.') }}</b> dari 5, dari {{ $ratingTempat['jumlah'] }} rating
                </p>
            @endif
            @if ($ulasan)
                <div class="ulasan-daftar">
                    @foreach ($ulasan as $ul)
                        <article style="background:var(--mv-putih);padding:18px 20px">
                            <p><b>{{ $ul['nama'] }}</b> <x-bintang :n="$ul['bintang']" /></p>
                            <p>{{ $ul['teks'] }}</p>
                            <p class="kecil redup">{{ \Illuminate\Support\Carbon::parse($ul['waktu'])->locale('id')->translatedFormat('j F Y') }}</p>
                        </article>
                    @endforeach
                </div>
            @else
                <div class="kosong" style="background:var(--mv-putih);padding:22px 24px">
                    <p>Belum ada ulasan untuk {{ $t['nama'] }}. Jadilah pengunjung pertama yang membagikan pengalaman.</p>
                </div>
            @endif

            @if (($u['peran'] ?? null) === 'wisatawan')
                <form class="form-ulasan" method="post" action="{{ route('ulasan.tulis', $t['slug']) }}" novalidate style="margin-top:24px;background:var(--mv-putih);padding:24px">
                    @csrf
                    @if ($ulasanSaya)
                        <p class="kecil redup" style="margin-bottom:10px">Kamu sudah mengulas tempat ini. Mengirim lagi akan memperbarui ulasanmu, bukan menambah ulasan baru.</p>
                    @endif
                    <fieldset class="isian">
                        <legend>{{ $ulasanSaya ? 'Perbarui penilaianmu' : 'Penilaian kunjunganmu' }}</legend>
                        <div class="pilih-banyak" style="margin-top:8px">
                            @for ($b = 1; $b <= 5; $b++)
                                <label><input type="radio" name="bintang" value="{{ $b }}" @checked(old('bintang', $ulasanSaya['bintang'] ?? null) == $b)>{{ $b }} bintang</label>
                            @endfor
                        </div>
                        @error('bintang')<p class="isian__galat">{{ $message }}</p>@enderror
                    </fieldset>
                    <div class="isian">
                        <label for="teks-ulasan">Ceritakan pengalamanmu di sini</label>
                        <textarea id="teks-ulasan" name="teks" maxlength="600" placeholder="Tips waktu berkunjung, suasana, atau hal menarik yang kamu temukan&hellip;" @error('teks') aria-invalid="true" aria-describedby="galat-teks" @enderror>{{ old('teks', $ulasanSaya['teks'] ?? '') }}</textarea>
                        @error('teks')<p class="isian__galat" id="galat-teks">{{ $message }}</p>@enderror
                    </div>
                    <button class="mv-btn mv-btn--hijau" type="submit" style="margin-top:18px">{{ $ulasanSaya ? 'Perbarui ulasan' : 'Kirim ulasan' }}</button>
                </form>
            @elseif (! $u)
                <p style="margin-top:18px">
                    <a class="mv-tautan" href="{{ route('masuk', ['kembali' => route('tempat', $t['slug'], false).'#ulasan']) }}">Masuk untuk menulis ulasan kunjungan</a>
                </p>
            @endif
        </section>
    </article>

    <aside class="mv-kartu-samping" data-ui aria-labelledby="judul-samping-kunjungan">
        <h2 id="judul-samping-kunjungan">Tiket &amp; check-in</h2>

        @if ($t['tiket'])
            <div class="mv-kartu-samping__harga">
                <p class="mv-kartu-samping__angka">Mulai {{ Katalog::rupiah(Katalog::hargaTermurah($t)) }}</p>
                <div class="mv-tarif-samping">
                    @foreach ($t['tiket'] as $jenis => $harga)
                        <div class="mv-tarif-samping__baris"><span>{{ $jenis }}</span><b class="nums">{{ Katalog::rupiah($harga) }}</b></div>
                    @endforeach
                </div>
            </div>
            @unless ($akunPengelola)
                <a class="mv-btn mv-btn--utama mv-btn--lebar" href="{{ route('tiket.pilih', $t['slug']) }}">Pesan tiket masuk</a>
            @endunless
        @else
            @php
                [$judulMasuk, $ketMasuk] = match ($t['kategori']) {
                    'kuliner', 'belanja' => ['Tanpa tiket masuk', 'Bayar sesuai yang dibeli di tempat.'],
                    'religi' => ['Masuk gratis', 'Datang di luar jam ibadah dan berpakaian sopan.'],
                    'ruang-publik' => ['Masuk gratis', 'Ruang terbuka untuk umum.'],
                    default => ['Tidak dijual di sini', 'Biaya masuk, bila ada, dibayar di lokasi.'],
                };
            @endphp
            <div class="mv-kartu-samping__harga">
                <p class="mv-kartu-samping__angka">{{ $judulMasuk }}</p>
                <p class="kecil redup" style="margin-top:4px">{{ $ketMasuk }}</p>
            </div>
        @endif

        <div class="mv-kartu-samping__blok">
            <a class="mv-btn mv-btn--garis mv-btn--kecil mv-btn--lebar" href="https://www.openstreetmap.org/?mlat={{ $t['lat'] }}&amp;mlon={{ $t['lng'] }}#map=18/{{ $t['lat'] }}/{{ $t['lng'] }}" rel="noopener">Petunjuk arah di peta</a>
        </div>
    </aside>
</div>
@endsection
