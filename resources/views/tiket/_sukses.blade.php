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
