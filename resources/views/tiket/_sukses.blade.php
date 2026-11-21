{{--
    Layar "e-tiket terbit". $siap = true: halaman pesanan lunas dari server.
    $siap = false: dipasang sebagai <template> di halaman pembayaran dan ditampilkan seketika oleh
    tiket.js saat Midtrans melaporkan pembayaran berhasil; tombol e-tiket aktif setelah server
    memastikan pesanan lunas (biasanya kurang dari dua detik).
--}}
<div class="sukses @unless ($siap) sukses--lapis @endunless" data-tiket="sukses" @unless ($siap) role="dialog" aria-modal="true" aria-labelledby="judul-sukses" @endunless>
    <div class="sukses__isi">
        <svg class="stempel" viewBox="0 0 200 200" role="img" aria-label="Stempel tiket terbit">
            <defs><path id="lingkar-terbit" d="M 100,100 m -72,0 a 72,72 0 1,1 144,0 a 72,72 0 1,1 -144,0"/></defs>
            <circle cx="100" cy="100" r="94" fill="none" stroke="#e3a51c" stroke-width="5"/>
            <circle cx="100" cy="100" r="84" fill="none" stroke="#e3a51c" stroke-width="1.5"/>
            <text fill="#e3a51c"><textPath href="#lingkar-terbit" startOffset="25%" text-anchor="middle">TIKET RESMI MEDANVISIT</textPath></text>
            <path d="M76 102l16 16 32-36" fill="none" stroke="#f6f1e4" stroke-width="9" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        <h1 id="judul-sukses" tabindex="-1" @unless ($siap) data-judul-siap="E-tiketmu sudah terbit" @endunless>{{ $siap ? 'E-tiketmu sudah terbit' : 'Pembayaran berhasil' }}</h1>
        <p>{{ collect($p['item'])->map(fn ($i) => $i['jumlah'].' '.mb_strtolower($i['jenis']))->implode(', ') }} untuk {{ $t['nama'] }} pada {{ $tgl->translatedFormat('l, j F Y') }}. Kode pesanan <strong>{{ $p['kode'] }}</strong> siap ditunjukkan di loket masuk.</p>
        @unless ($siap)
            <p class="sukses__proses" data-proses-etiket role="status" aria-live="polite">Menerbitkan e-tiket…</p>
