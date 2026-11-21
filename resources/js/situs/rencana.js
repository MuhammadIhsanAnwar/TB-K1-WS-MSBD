// Perencanaan rute: pilihan "Lokasi saya sekarang" membaca posisi dari browser tepat sebelum form
// dikirim. Koordinat dibulatkan ke 4 desimal (sekitar 11 m) dan tidak disimpan di server.
const form = document.querySelector('[data-rencana-form]');

if (form) {
    const pilih = form.querySelector('[data-pilih-mulai]');
    const lat = form.querySelector('[data-lat]');
    const lng = form.querySelector('[data-lng]');
    const kabar = form.querySelector('[data-kabar-lokasi]');
    const tombol = form.querySelector('button[type="submit"]');

    const tulis = (teks) => {
