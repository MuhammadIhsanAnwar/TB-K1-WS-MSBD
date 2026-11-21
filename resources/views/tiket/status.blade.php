@extends('layouts.situs')
@php
    use App\Support\Katalog;
    use App\Support\Midtrans;
    $tgl = \Illuminate\Support\Carbon::parse($p['tanggal'])->locale('id');
    $vaNomor = '8808'.substr(preg_replace('/\D/', '', $p['kode']), 0, 4).substr(preg_replace('/\D/', '', $p['pemesan']['hp']), -6);
    $midtrans = $p['status'] === 'menunggu' && Midtrans::aktif();
@endphp
@if ($midtrans)
    @push('kepala')
        <script src="{{ Midtrans::urlSnapJs() }}" data-client-key="{{ Midtrans::clientKey() }}" defer></script>
    @endpush
@endif
@section('judul', 'Pesanan '.$p['kode'])
@section('tanpa-kaki', '1')
@if ($p['status'] === 'lunas') @section('tanpa-kepala', '1') @endif

@section('isi')
@if ($p['status'] === 'lunas')
    @include('tiket._sukses', ['siap' => true])
@else
@if ($midtrans)
<div class="mv-tiket-ruang" data-tiket="status">
    <div class="alat__judul">
        <div>
            <h1>Pesanan {{ $p['kode'] }}</h1>
            <p>{{ $t['nama'] }}, {{ $tgl->translatedFormat('l, j F Y') }}</p>
        </div>
    </div>

    <ol class="mv-langkah-tiket" aria-label="Tahap pemesanan tiket">
        <li class="selesai"><span class="mv-langkah-tiket__no">1</span><span>Pilih tiket</span></li>
        <li class="selesai"><span class="mv-langkah-tiket__no">2</span><span>Data pemesan</span></li>
        <li aria-current="step"><span class="mv-langkah-tiket__no">3</span><span>Pembayaran</span></li>
        <li><span class="mv-langkah-tiket__no">4</span><span>E-tiket terbit</span></li>
    </ol>

    <div class="tiket-alur">
        <section class="p-panel" aria-labelledby="judul-bayar">
            @if ($p['status'] === 'menunggu')
                <header>
                    <h2 id="judul-bayar">Selesaikan pembayaran</h2>
                    <span class="p-pill warn">Menunggu pembayaran</span>
                </header>
                <p>Selesaikan sebelum batas waktu berakhir agar pesanan tidak dibatalkan otomatis.</p>
                <p class="hitung-mundur nums" data-batas="{{ $p['batas_bayar'] }}" role="timer" aria-live="off" style="margin:12px 0 20px">15:00</p>

                @if ($midtrans)
                    <div class="bayar-midtrans"
                    data-bayar-midtrans
                    data-cek-url="{{ route('pesanan.cek', $p['kode']) }}"
                    data-status-url="{{ route('pesanan.status-lokal', $p['kode']) }}">
                        <p>Tekan <b>Bayar</b>, lalu pilih cara bayar di jendela Midtrans: QRIS, virtual account bank, dompet digital, atau kartu. Nominalnya {{ Katalog::rupiah($p['total']) }}. Untuk virtual account, nomornya muncul setelah bank dipilih.</p>
                        @unless (Midtrans::produksi())
                            <div class="p-pesan p-pesan--info" style="margin-top:16px;display:block">
                                Mode uji coba (sandbox): tidak ada uang yang ditarik. Salin nomor virtual account atau QRIS-nya, lalu bayar di
                                <a class="mv-tautan" href="https://simulator.sandbox.midtrans.com" target="_blank" rel="noopener">simulator Midtrans</a>
                                dengan bank yang sama. Untuk kartu, pakai kartu uji 4811 1111 1111 1114, CVV 123, OTP 112233.
                                Halaman ini diperbarui sendiri setelah pembayaran diterima.
                            </div>
                        @endunless
                        <p class="bayar-midtrans__kabar" data-kabar-bayar role="status" aria-live="polite" hidden></p>
                        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:16px">
                            <form method="post" action="{{ route('pesanan.bayar', $p['kode']) }}" data-form-bayar>
                                @csrf
                                <button class="mv-btn mv-btn--utama" type="submit">Bayar {{ Katalog::rupiah($p['total']) }}</button>
                            </form>
                            <form method="post" action="{{ route('pesanan.batal', $p['kode']) }}">
                                @csrf
                                <button class="mv-btn mv-btn--bahaya" type="submit">Batalkan pesanan</button>
                            </form>
                        </div>
                    </div>
                @elseif ($p['metode'] === 'qris')
                    <div style="display:grid;justify-items:start;gap:10px">
                        <div class="mv-etiket__qr" data-qr="QRIS-PROTOTIPE-{{ $p['kode'] }}-{{ $p['total'] }}" data-qr-label="Kode QRIS contoh untuk pesanan {{ $p['kode'] }}"></div>
                        <p class="kecil redup">Pindai melalui aplikasi bank atau dompet digital. Kode QRIS ini merupakan simulasi prototipe dan tidak menarik saldo nyata.</p>
                    </div>
                @elseif ($p['metode'] === 'va')
                    <div class="p-field">
                        <span class="label" style="font-weight:600">Nomor virtual account <span class="contoh">contoh</span></span>
                        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:6px">
                            <span class="mv-etiket__kode">{{ $vaNomor }}</span>
                            <button class="mv-btn mv-btn--garis mv-btn--kecil" type="button" data-salin="{{ $vaNomor }}">Salin nomor</button>
                        </div>
                    </div>
                    <details class="lipat" style="margin-top:16px">
                        <summary>Petunjuk transfer virtual account</summary>
                        <p>Buka menu Transfer Virtual Account di aplikasi m-banking, masukkan nomor di atas, lalu periksa nama penerima MedanVisit beserta nominal tagihan sebelum konfirmasi.</p>
                    </details>
                @else
                    <p>Lanjutkan pembayaran melalui aplikasi {{ $p['metode'] === 'ewallet' ? 'dompet digital' : 'bank penerbit kartu' }} sesuai nominal pesanan.</p>
                @endif

                @unless ($midtrans)
                    <div class="p-pesan p-pesan--info" style="margin-top:20px">Mode prototipe: klik tombol simulasi di bawah untuk menerbitkan e-tiket secara langsung.</div>

                    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:16px">
                        <form method="post" action="{{ route('pesanan.simulasi', $p['kode']) }}">
                            @csrf
                            <button class="mv-btn mv-btn--utama" type="submit">Simulasikan pembayaran berhasil</button>
                        </form>
                        <form method="post" action="{{ route('pesanan.batal', $p['kode']) }}">
                            @csrf
                            <button class="mv-btn mv-btn--bahaya" type="submit">Batalkan pesanan</button>
                        </form>
                    </div>
                @endunless
            @elseif ($p['status'] === 'kedaluwarsa')
                <header>
                    <h2 id="judul-bayar">Batas waktu pembayaran berakhir</h2>
                    <span class="p-pill neg">Kedaluwarsa</span>
                </header>
                <p>Pesanan melewati batas 15 menit sehingga dibatalkan otomatis tanpa ada dana yang ditarik.</p>
                <a class="mv-btn mv-btn--utama" style="margin-top:16px" href="{{ route('tiket.pilih', $t['slug']) }}">Buat pesanan baru</a>
            @else
                <header>
                    <h2 id="judul-bayar">Pesanan telah dibatalkan</h2>
                    <span class="p-pill">Dibatalkan</span>
                </header>
                <p>Pesanan ini dibatalkan atas permintaanmu sebelum pembayaran selesai.</p>
                <a class="mv-btn mv-btn--utama" style="margin-top:16px" href="{{ route('tiket.pilih', $t['slug']) }}">Pesan ulang tiket</a>
            @endif
        </section>

        <aside class="p-panel ringkasan-pesanan" aria-labelledby="judul-ringkasan">
            <header>
                <h2 id="judul-ringkasan">Ringkasan</h2>
                <span class="meta">{{ $midtrans ? 'Dipilih di Midtrans' : $metode[$p['metode']] }}</span>
            </header>
            <div class="p-baris nums">
                @foreach ($p['item'] as $i)
                    <div><span>{{ $i['jumlah'] }} × {{ $i['jenis'] }}</span><span class="kanan">{{ Katalog::rupiah($i['subtotal']) }}</span></div>
                @endforeach
            </div>
            <div class="total nums"><span>Total bayar</span><b>{{ Katalog::rupiah($p['total']) }}</b></div>
            <p class="kecil redup" style="margin-top:12px">Pemesan: {{ $p['pemesan']['nama'] }} ({{ $p['pemesan']['email'] }})</p>
        </aside>
    </div>
</div>
@endif
@endsection
