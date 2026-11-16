const form = document.querySelector('[data-hubungi-admin]');

if (form) {
    const kategori = form.querySelector('[data-hubungi-kategori]');
    const bagianTempat = form.querySelector('[data-pesan-tempat]');
    const tempat = form.querySelector('[data-hubungi-tempat]');
    const tujuan = form.querySelector('[data-pesan-tujuan]');

    const perbaruiTujuan = () => {
        const butuhTempat = kategori.value === 'tempat' && Boolean(bagianTempat && tempat);
        if (bagianTempat && tempat) {
            bagianTempat.hidden = !butuhTempat;
            tempat.disabled = !butuhTempat;
            tempat.required = butuhTempat;
        }
        tujuan.textContent = butuhTempat
            ? 'Pesan akan diteruskan hanya kepada admin tempat yang dipilih.'
            : kategori.value
                ? ''
                : 'Pilih kategori untuk melihat tujuan pesan.';
    };

    kategori.addEventListener('change', perbaruiTujuan);
    perbaruiTujuan();
}
