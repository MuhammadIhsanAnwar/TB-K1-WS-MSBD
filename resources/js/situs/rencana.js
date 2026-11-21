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
        kabar.hidden = !teks;
        kabar.textContent = teks;
    };

    const aturKabar = () => {
        if (pilih.value !== 'lokasi') {
            tulis('');
            return;
        }
        tulis(navigator.geolocation
            ? 'Browser akan meminta izin membaca lokasimu saat rute disusun.'
            : 'Browser ini tidak bisa membaca lokasi. Pilih tempat awal dari daftar.');
    };
    pilih.addEventListener('change', aturKabar);

    form.addEventListener('submit', (e) => {
        // tempat awal dari daftar: koordinat lama tidak ikut dikirim
        lat.disabled = lng.disabled = pilih.value !== 'lokasi';
        if (pilih.value !== 'lokasi') return;

        e.preventDefault();
