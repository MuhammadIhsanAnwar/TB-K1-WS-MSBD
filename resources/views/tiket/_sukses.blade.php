{{--
    Layar "e-tiket terbit". $siap = true: halaman pesanan lunas dari server.
    $siap = false: dipasang sebagai <template> di halaman pembayaran dan ditampilkan seketika oleh
    tiket.js saat Midtrans melaporkan pembayaran berhasil; tombol e-tiket aktif setelah server
    memastikan pesanan lunas (biasanya kurang dari dua detik).
--}}
<div class="sukses @unless ($siap) sukses--lapis @endunless" data-tiket="sukses" @unless ($siap) role="dialog" aria-modal="true" aria-labelledby="judul-sukses" @endunless>
