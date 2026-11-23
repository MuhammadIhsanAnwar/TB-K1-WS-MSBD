// Panel Sampul beranda: pratinjau diperbarui sebelum disimpan dan slot dapat ditambah dinamis.
const form = document.querySelector('[data-sampul-atur]');
const kumpulanSlot = form?.querySelector('[data-kumpulan-slot]');
const templateSlot = form?.querySelector('[data-template-slot]');
const tombolTambah = form?.querySelector('[data-tambah-slot]');
const jumlahSlot = form?.querySelector('[data-jumlah-slot]');
const batasSlot = Number(form?.dataset.maksSlot || 100);

const siapkanSlot = (slot) => {
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
        if (!berkas) return dariTempat();
        urlUnggah = URL.createObjectURL(berkas);
        tampilkan(urlUnggah, `${berkas.name}, belum disimpan`);
    });
};

const perbaruiJumlah = () => {
    const jumlah = kumpulanSlot.querySelectorAll('[data-slot]').length;
    jumlahSlot.textContent = `${jumlah} dari ${batasSlot} slot`;
    tombolTambah.disabled = jumlah >= batasSlot;
};

if (form && kumpulanSlot && templateSlot && tombolTambah && jumlahSlot) {
    kumpulanSlot.querySelectorAll('[data-slot]').forEach(siapkanSlot);
    perbaruiJumlah();

    tombolTambah.addEventListener('click', () => {
        const index = kumpulanSlot.querySelectorAll('[data-slot]').length;
        if (index >= batasSlot) return;

        const markup = templateSlot.innerHTML
            .replace(/__INDEX__/g, String(index))
            .replace(/__NOMOR__/g, String(index + 1));
        kumpulanSlot.insertAdjacentHTML('beforeend', markup);
        siapkanSlot(kumpulanSlot.lastElementChild);
        perbaruiJumlah();
    });
}
