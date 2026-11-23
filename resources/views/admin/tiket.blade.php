@extends('layouts.panel')
@php
    use App\Support\Katalog;
    $hasil = session('hasil');
    $lunas = $pesanan->where('status', 'lunas');
    $ringkasanAktif = $fokusLaporan ? $penjualanPerTempat->get($fokusLaporan) : null;
    $jumlahPesanan = $ringkasanAktif['pesanan'] ?? $penjualanPerTempat->sum('pesanan');
    $jumlahTiket = $ringkasanAktif['tiket'] ?? $penjualanPerTempat->sum('tiket');
    $jumlahPendapatan = $ringkasanAktif['pendapatan'] ?? $penjualanPerTempat->sum('pendapatan');
@endphp
@section('judul', 'Tiket dan transaksi')

@section('isi')
@if ($superadmin)
    <div class="p-judul">
        <div>
            <h1>Tiket dan transaksi</h1>
            <p class="sub">Laporan baca-saja. Penjualan dan pendapatan hanya menghitung pesanan yang sudah lunas.</p>
        </div>
    </div>

    <div class="p-kpi">
        <div><div class="label">Pesanan lunas</div><div class="nilai">{{ number_format($jumlahPesanan, 0, ',', '.') }}</div><div class="ket">{{ $ringkasanAktif ? 'di '.$ringkasanAktif['nama'] : 'semua lokasi' }}</div></div>
        <div><div class="label">Tiket terjual</div><div class="nilai">{{ number_format($jumlahTiket, 0, ',', '.') }}</div><div class="ket">jumlah tiket dari pesanan lunas</div></div>
        <div><div class="label">Pendapatan</div><div class="nilai">{{ Katalog::rupiah($jumlahPendapatan) }}</div><div class="ket">berdasarkan nilai pesanan lunas</div></div>
    </div>

    <div class="p-grid">
        <section class="p-panel s12" aria-labelledby="filter-lokasi">
            <header><h2 id="filter-lokasi">Pilih lokasi</h2><span class="meta">Data seluruh periode</span></header>
            <form method="get" action="{{ route('admin.tiket') }}" class="p-form">
                <div class="p-field">
                    <label for="tempat">Lokasi wisata</label>
                    <select id="tempat" name="tempat">
                        <option value="">Semua lokasi</option>
                        @foreach ($tempatLaporan as $tempat)
                            <option value="{{ $tempat['slug'] }}" @selected($fokusLaporan === $tempat['slug'])>{{ $tempat['nama'] }}</option>
                        @endforeach
                    </select>
                </div>
                <button class="p-btn p-btn--utama" type="submit">Lihat laporan</button>
            </form>
        </section>

        <section class="p-panel s12" aria-labelledby="penjualan-per-lokasi">
            <header><h2 id="penjualan-per-lokasi">Penjualan per lokasi</h2><span class="meta">Hanya pesanan lunas</span></header>
            @if ($penjualanPerTempat->isNotEmpty())
                <div class="p-tabel-bungkus">
                    <table class="p-tabel jadi-daftar">
                        <thead><tr><th>Lokasi</th><th>Harga tiket</th><th class="n">Pesanan lunas</th><th class="n">Tiket terjual</th><th class="n">Pendapatan</th></tr></thead>
                        <tbody>
                            @foreach ($penjualanPerTempat as $penjualan)
                                <tr>
                                    <td><a class="p-tautan-kartu" href="{{ route('admin.tiket', ['tempat' => $penjualan['slug']]) }}">{{ $penjualan['nama'] }}</a></td>
                                    <td class="sekunder">
                                        {{ collect($penjualan['harga'])->map(fn ($harga, $jenis) => $jenis.': '.Katalog::rupiah((int) $harga))->implode(' · ') }}
                                        @if ($penjualan['harga_contoh'])
                                            <span class="sub">Harga contoh, konfirmasi ke pengelola</span>
                                        @endif
                                    </td>
                                    <td class="n">{{ number_format($penjualan['pesanan'], 0, ',', '.') }}</td>
                                    <td class="n">{{ number_format($penjualan['tiket'], 0, ',', '.') }}</td>
                                    <td class="n">{{ Katalog::rupiah($penjualan['pendapatan']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="p-kosong">Belum ada lokasi wisata yang tersedia.</div>
            @endif
        </section>

        @if ($ringkasanAktif)
            <section class="p-panel s6" aria-labelledby="tiket-per-jenis">
                <header><h2 id="tiket-per-jenis">Tiket terjual · {{ $ringkasanAktif['nama'] }}</h2></header>
                @if ($tiketPerJenis->isNotEmpty())
                    <div class="p-baris">
                        @foreach ($tiketPerJenis as $jenis => $angka)
                            <div><span>{{ $jenis }}</span><span class="kanan"><b>{{ number_format($angka['jumlah'], 0, ',', '.') }}</b> · {{ Katalog::rupiah($angka['pendapatan']) }}</span></div>
                        @endforeach
                    </div>
                @else
                    <div class="p-kosong">Belum ada tiket terjual di lokasi ini.</div>
                @endif
            </section>

            <section class="p-panel s6" aria-labelledby="transaksi-lokasi">
                <header><h2 id="transaksi-lokasi">Transaksi lunas · {{ $ringkasanAktif['nama'] }}</h2><span class="meta">{{ $transaksiTerpilih->count() }} transaksi</span></header>
                @if ($transaksiTerpilih->isNotEmpty())
                    <div class="p-tabel-bungkus">
                        <table class="p-tabel jadi-daftar">
                            <thead><tr><th>Tanggal bayar</th><th>Rincian tiket</th><th class="n">Pendapatan</th></tr></thead>
                            <tbody>
                                @foreach ($transaksiTerpilih as $transaksi)
                                    <tr>
                                        <td class="sekunder"><span class="kode">{{ $transaksi['kode'] }}</span><span class="sub">{{ \Illuminate\Support\Carbon::parse($transaksi['waktu'])->locale('id')->translatedFormat('j M Y, H:i') }}</span></td>
                                        <td>{{ collect($transaksi['item'])->map(fn ($item) => number_format($item['jumlah'], 0, ',', '.').' × '.$item['jenis'])->implode(', ') }}</td>
                                        <td class="n">{{ Katalog::rupiah($transaksi['total']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="p-kosong">Belum ada transaksi lunas di lokasi ini.</div>
                @endif
            </section>
        @endif
    </div>
@else
<div class="p-judul"><div><h1>Tiket dan transaksi</h1><p class="sub">{{ $lunas->count() }} pesanan lunas, {{ Katalog::rupiah($lunas->sum('total')) }}. {{ \App\Support\Midtrans::aktif() ? 'Pembayaran lewat Midtrans ('.(\App\Support\Midtrans::produksi() ? 'produksi' : 'sandbox, uji coba tanpa uang sungguhan').').' : 'Pembayaran masih simulasi sampai Midtrans tersambung.' }}</p></div></div>
<div class="p-grid">
    <section class="p-panel s4" aria-labelledby="judul-validasi">
        <header><h2 id="judul-validasi">Validasi di loket</h2></header>
        <form class="p-form" method="post" action="{{ route('admin.tiket.validasi') }}" novalidate>
            @csrf
            <div class="p-field"><label for="kode">Kode e-tiket</label><input id="kode" name="kode" class="kode" placeholder="MV-XXXX-0000" autocomplete="off" value="{{ old('kode') }}" @error('kode') aria-invalid="true" aria-describedby="g-kode" @enderror>@error('kode')<span class="galat" id="g-kode">{{ $message }}</span>@enderror</div>
            <div><button class="p-btn p-btn--utama p-btn--besar" type="submit">Periksa tiket</button></div>
        </form>
        @if ($hasil)<div class="p-hasil {{ $hasil['status'] }}" role="status"><b>{{ $hasil['judul'] }}</b><span>{{ $hasil['teks'] }}</span></div>@endif
        <div class="p-pemisah"></div>
        <h2>Harga tiket</h2>
        <div class="p-baris" style="margin-top:6px">
            @foreach ($bertiket as $t)
                <div><span class="dua"><b>{{ $t['nama'] }}</b><span>{{ collect($t['tiket'])->map(fn ($h, $j) => $j.' '.Katalog::rupiah($h))->implode(', ') }}</span></span><a class="p-tautan-kartu kanan" href="{{ route('admin.entitas.ubah', $t['slug']) }}">Ubah</a></div>
            @endforeach
        </div>
    </section>
    <section class="p-panel s8" aria-labelledby="judul-pesanan">
        <header><h2 id="judul-pesanan">Pesanan</h2><span class="meta">{{ $pesanan->count() }} pesanan</span></header>
        @if ($pesanan->isNotEmpty())
            <table class="p-tabel jadi-daftar">
                <thead><tr><th>Kode</th><th>Pemesan</th><th>Tempat dan tanggal</th><th class="n">Total</th><th class="n">Status</th></tr></thead>
                <tbody>
                    @foreach ($pesanan as $p)
                        @php $st = ! empty($p['dipakai']) ? ['Dipakai', ''] : ['lunas' => ['Lunas', 'pos'], 'menunggu' => ['Menunggu', 'warn'], 'kedaluwarsa' => ['Kedaluwarsa', 'neg'], 'dibatalkan' => ['Dibatalkan', '']][$p['status']]; @endphp
                        <tr>
                            <td><span class="kode">{{ $p['kode'] }}</span></td>
                            <td class="sekunder">{{ $p['pemesan']['nama'] }}<span class="sub">{{ $p['pemesan']['email'] }}</span></td>
                            <td class="sekunder">{{ Katalog::get($p['tempat'])['nama'] }}<span class="sub">{{ \Illuminate\Support\Carbon::parse($p['tanggal'])->locale('id')->translatedFormat('j M Y') }}</span></td>
                            <td class="n">{{ Katalog::rupiah($p['total']) }}</td>
                            <td class="n"><span class="p-pill {{ $st[1] }}">{{ $st[0] }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <div class="p-kosong">Belum ada pesanan tiket.</div>
        @endif
    </section>
</div>
@endif
@endsection
