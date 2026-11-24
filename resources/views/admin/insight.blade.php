@extends('layouts.panel')
@php
    use App\Support\Katalog;
    use App\Support\Penyimpanan;
    $grafikHarian = [
        'jenis' => 'garis',
        'label' => collect(array_keys($kunjungan['harian']))
            ->map(fn ($tanggal) => \Illuminate\Support\Carbon::parse($tanggal)->locale('id')->translatedFormat('j M'))
            ->values(),
        'nama' => 'Tayangan',
        'nilai' => array_values($kunjungan['harian']),
    ];
    $totalLunas = $pesanan->where('status', 'lunas');
    $namaRute = ['beranda' => 'Beranda', 'cari' => 'Pencarian', 'peta' => 'Peta', 'rencana' => 'Rencana', 'tanya' => 'Tanya'];
    $namaFokus = $fokus ? (Katalog::get($fokus)['nama'] ?? $fokus) : null;
    $rating = $fokus ? (Penyimpanan::ringkasanRatingTempat()[$fokus] ?? null) : null;
    $dikunjungi = $kunjunganTempat->where('jumlah', '>', 0);
    $belumDikunjungi = $kunjunganTempat->count() - $dikunjungi->count();
    // superadmin yang sedang melihat satu tempat tetap membawa pilihan itu saat ganti periode
    $tautanPeriode = fn ($p) => route('admin.insight', array_filter(['periode' => $p, 'tempat' => $admin ? null : $fokus]));
@endphp
@section('judul', 'Insight')

@section('isi')
<div class="p-judul">
    <div>
        <h1>{{ $namaFokus ? 'Insight '.$namaFokus : 'Insight' }}</h1>
        <p class="sub">
            Dihitung dari pembukaan halaman HTML yang berhasil, di luar panel admin. Angka ini adalah tayangan halaman, bukan jumlah orang unik.
            @if ($fokus && ! $admin)<a class="p-tautan-kartu" href="{{ route('admin.insight', ['periode' => $periode]) }}">Kembali ke semua tempat</a>@endif
        </p>
    </div>
    <nav class="p-seg" aria-label="Periode insight">
        @foreach ([7, 30, 90] as $pilihan)<a href="{{ $tautanPeriode($pilihan) }}" @if ($periode === $pilihan) aria-current="true" @endif>{{ $pilihan }} hari</a>@endforeach
    </nav>
</div>

<div class="p-kpi">
    @if ($fokus)
        <div><div class="label">Tayangan halaman tempat</div><div class="nilai">{{ number_format($kunjungan['total'], 0, ',', '.') }}</div><div class="ket">pembukaan halaman {{ $namaFokus }} yang berhasil dalam {{ $periode }} hari</div></div>
    @else
        <div><div class="label">Kunjungan website</div><div class="nilai">{{ number_format($kunjungan['total'], 0, ',', '.') }}</div><div class="ket">pembukaan halaman publik yang berhasil dalam {{ $periode }} hari</div></div>
    @endif
    <div><div class="label">Tiket terjual</div><div class="nilai">{{ number_format($totalLunas->sum(fn ($pesanan) => array_sum(array_column($pesanan['item'], 'jumlah'))), 0, ',', '.') }}</div><div class="ket">dari {{ $totalLunas->count() }} pesanan lunas</div></div>
    <div><div class="label">Pendapatan tiket</div><div class="nilai">{{ Katalog::rupiah($totalLunas->sum('total')) }}</div><div class="ket">berdasarkan pesanan lunas</div></div>
    @if ($fokus)
        <div><div class="label">Rating tempat</div><div class="nilai">{{ $rating && $rating['jumlahRating'] ? number_format($rating['rating'], 1, ',', '.') : '-' }}</div><div class="ket">{{ $rating['jumlahRating'] ?? 0 }} rating pengguna</div></div>
    @else
        <div><div class="label">Akun terdaftar</div><div class="nilai">{{ number_format(($akun['admin'] ?? 0) + ($akun['wisatawan'] ?? 0) + ($akun['superadmin'] ?? 0), 0, ',', '.') }}</div><div class="ket">{{ $akun['admin'] ?? 0 }} admin · {{ $akun['superadmin'] ?? 0 }} superadmin · {{ $akun['wisatawan'] ?? 0 }} wisatawan</div></div>
    @endif
</div>

<div class="p-grid">
    <section class="p-panel s12" aria-labelledby="kunjungan-harian">
        <header><h2 id="kunjungan-harian">{{ $namaFokus ? 'Kunjungan halaman '.$namaFokus.' per hari' : 'Kunjungan website per hari' }}</h2><span class="meta">{{ $periode }} hari terakhir</span></header>
        <div class="p-grafik" data-grafik="{{ json_encode($grafikHarian) }}" aria-label="Grafik tayangan halaman per hari"></div>
    </section>

    @unless ($fokus)
        <section class="p-panel s6" aria-labelledby="halaman-populer">
            <header><h2 id="halaman-populer">Halaman paling banyak dikunjungi</h2><span class="meta">{{ $periode }} hari</span></header>
            @if ($kunjungan['rute'])
                <div class="p-baris">
                    @foreach (array_slice($kunjungan['rute'], 0, 8, true) as $rute => $jumlah)
                        @php $label = $namaRute[$rute] ?? ($rute === 'tempat' ? 'Detail tempat wisata' : ucfirst(str_replace(['.', '_'], ' ', $rute))); @endphp
                        <div><span>{{ $label }}</span><b class="kanan">{{ number_format($jumlah, 0, ',', '.') }}</b></div>
                    @endforeach
                </div>
            @else
                <div class="p-kosong">Belum ada data kunjungan selama periode ini.</div>
            @endif
        </section>
    @endunless

    @unless ($admin)
        <section class="p-panel {{ $fokus ? 's12' : 's6' }}" aria-labelledby="tempat-populer">
            <header><h2 id="tempat-populer">Kunjungan halaman tempat wisata</h2><span class="meta">{{ $periode }} hari · pilih tempat untuk melihat grafiknya</span></header>
            @if ($dikunjungi->isNotEmpty())
                <div class="p-baris">
                    @foreach ($dikunjungi as $baris)
                        <div>
                            <a class="p-tautan-kartu" href="{{ route('admin.insight', ['periode' => $periode, 'tempat' => $baris['slug']]) }}" @if ($fokus === $baris['slug']) aria-current="true" @endif>{{ $baris['nama'] }}</a>
                            <b class="kanan">{{ number_format($baris['jumlah'], 0, ',', '.') }}</b>
                        </div>
                    @endforeach
                </div>
                @if ($belumDikunjungi)
                    <p class="meta" style="margin-top:10px">{{ $belumDikunjungi }} tempat lain belum dikunjungi dalam periode ini.</p>
                @endif
            @else
                <div class="p-kosong">Belum ada halaman tempat wisata yang dikunjungi.</div>
            @endif
        </section>
    @endunless

    <section class="p-panel s12" aria-labelledby="transaksi-tempat">
        <header><h2 id="transaksi-tempat">{{ $namaFokus ? 'Transaksi '.$namaFokus : 'Transaksi per tempat wisata' }}</h2><span class="meta">{{ $periode }} hari · hanya pesanan lunas dihitung sebagai penjualan</span></header>
        @if ($transaksi->isNotEmpty())
            <div class="p-tabel-bungkus"><table class="p-tabel jadi-daftar">
                <thead><tr><th>Tempat</th><th class="n">Pesanan</th><th class="n">Lunas</th><th class="n">Tiket</th><th class="n">Pendapatan</th></tr></thead>
                <tbody>
                    @foreach ($transaksi as $slug => $angka)
                        <tr><td>{{ Katalog::get($slug)['nama'] ?? $slug }}</td><td class="n">{{ number_format($angka['pesanan'], 0, ',', '.') }}</td><td class="n">{{ number_format($angka['lunas'], 0, ',', '.') }}</td><td class="n">{{ number_format($angka['tiket'], 0, ',', '.') }}</td><td class="n">{{ Katalog::rupiah($angka['pendapatan']) }}</td></tr>
                    @endforeach
                </tbody>
            </table></div>
        @else
            <div class="p-kosong">Belum ada transaksi pada periode ini.</div>
        @endif
    </section>
</div>
@endsection
