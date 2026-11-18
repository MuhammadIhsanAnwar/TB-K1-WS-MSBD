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
        sumber.textContent = teks;
    };

    const dariTempat = () => {
        const opsi = pilih.selectedOptions[0];
        if (!opsi?.value) return tampilkan('', '');
        tampilkan(opsi.dataset.foto, opsi.dataset.tanpaFoto
            ? 'Tempat ini belum punya foto; beranda memakai peta Medan 1930'
            : 'Foto dari data tempat (Database 3), belum disimpan');
    };

    pilih?.addEventListener('change', () => {
        if (!unggah?.files?.length) dariTempat();
    });

    unggah?.addEventListener('change', () => {
        if (urlUnggah) URL.revokeObjectURL(urlUnggah);
        urlUnggah = null;
        const berkas = unggah.files?.[0];
