// Panel Sampul beranda: pratinjau setiap slot ikut berubah saat tempat dipilih atau foto dipilih,
// sebelum disimpan. Tanpa skrip, form tetap bekerja dan pratinjau diperbarui setelah simpan.
document.querySelectorAll('[data-sampul-atur] [data-slot]').forEach((slot) => {
    const pilih = slot.querySelector('[data-pilih-tempat]');
    const unggah = slot.querySelector('[data-unggah]');
    const gambar = slot.querySelector('[data-pratinjau]');
    const kosong = slot.querySelector('[data-kosong]');
    const sumber = slot.querySelector('[data-sumber]');
    let urlUnggah = null;

    const tampilkan = (src, teks) => {
        gambar.hidden = !src;
        kosong.hidden = Boolean(src);
        if (src) gambar.src = src;
