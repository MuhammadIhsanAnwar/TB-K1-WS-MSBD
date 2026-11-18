// Pengaturan akun: pratinjau foto sebelum diunggah, tampilkan atau sembunyikan password,
// dan checklist syarat password. Polanya sama dengan regex di AkunController::simpanPengaturan,
// jadi yang lolos di sini juga lolos di server.
const akar = document.querySelector('[data-pengaturan]');

// ---- Foto profil ----
const inputFoto = akar?.querySelector('[data-input-foto]');
const pratinjau = akar?.querySelector('[data-pratinjau-foto]');
const bantuFoto = akar?.querySelector('[data-bantu-foto]');
const teksBantuFoto = bantuFoto?.textContent ?? '';
const BATAS_FOTO = 2 * 1024 * 1024;
let urlPratinjau = null;

function aturBantuFoto(teks, galat = false) {
    if (!bantuFoto) return;
    bantuFoto.textContent = teks;
    bantuFoto.classList.toggle('isian__galat', galat);
    bantuFoto.classList.toggle('isian__bantuan', !galat);
}

inputFoto?.addEventListener('change', () => {
    const berkas = inputFoto.files?.[0];
    if (urlPratinjau) URL.revokeObjectURL(urlPratinjau);
    urlPratinjau = null;
    if (pratinjau) pratinjau.hidden = true;
    inputFoto.setCustomValidity('');
    aturBantuFoto(teksBantuFoto);
    if (!berkas) return;

    let galat = '';
    if (!berkas.type.startsWith('image/')) galat = 'Berkas ini bukan gambar. Pilih JPG, PNG, GIF, atau WEBP.';
    else if (berkas.size > BATAS_FOTO) galat = `Ukuran foto ${(berkas.size / 1048576).toFixed(1)} MB, melebihi batas 2 MB.`;
    if (galat) {
        inputFoto.setCustomValidity(galat);
        aturBantuFoto(galat, true);
        return;
    }

    urlPratinjau = URL.createObjectURL(berkas);
    if (pratinjau) {
        pratinjau.src = urlPratinjau;
        pratinjau.alt = 'Pratinjau foto profil baru';
        pratinjau.hidden = false;
    }
    aturBantuFoto(`${berkas.name} siap dipakai. Tekan "Simpan perubahan" untuk menyimpannya.`);
});

// ---- Password ----
const sandi = akar?.querySelector('[data-input-sandi]');
const ulang = akar?.querySelector('[data-input-ulang]');
const statusUlang = akar?.querySelector('[data-status-ulang]');
const teksStatusUlang = statusUlang?.textContent ?? '';
const SYARAT = {
    panjang: (s) => s.length >= 8,
