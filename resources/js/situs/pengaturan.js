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
    kecil: (s) => /[a-z]/.test(s),
    besar: (s) => /[A-Z]/.test(s),
    angka: (s) => /\d/.test(s),
    simbol: (s) => /[^A-Za-z\d]/.test(s),
};

function aturStatusUlang(teks) {
    // teks yang sama tidak ditulis ulang, supaya pembaca layar tidak mengumumkannya di setiap ketukan
    if (statusUlang && statusUlang.textContent !== teks) statusUlang.textContent = teks;
}

function periksaSandi() {
    const nilai = sandi.value;
    let semua = true;
    for (const [kunci, cek] of Object.entries(SYARAT)) {
        const terpenuhi = nilai !== '' && cek(nilai);
        akar.querySelector(`[data-syarat="${kunci}"]`)?.toggleAttribute('data-terpenuhi', terpenuhi);
        semua &&= terpenuhi;
    }
    sandi.setCustomValidity(nilai === '' || semua ? '' : 'Password belum memenuhi semua syarat di bawah kolom ini.');

    if (!ulang) return;
    if (nilai === '' && ulang.value === '') {
        ulang.setCustomValidity('');
        aturStatusUlang(teksStatusUlang);
        return;
    }
    const sama = ulang.value === nilai;
    ulang.setCustomValidity(sama ? '' : 'Ulangi password yang sama.');
    if (ulang.value === '') aturStatusUlang('Ketik ulang password baru di kolom ini.');
    else aturStatusUlang(sama ? 'Kedua password sudah sama.' : 'Belum sama dengan password baru.');
}

if (sandi) {
    sandi.addEventListener('input', periksaSandi);
    ulang?.addEventListener('input', periksaSandi);
    periksaSandi();
}

akar?.querySelector('[data-lihat-sandi]')?.addEventListener('click', (e) => {
