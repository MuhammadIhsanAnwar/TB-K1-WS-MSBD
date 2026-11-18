// Pengaturan akun: pratinjau foto sebelum diunggah, tampilkan atau sembunyikan password,
// dan checklist syarat password. Polanya sama dengan regex di AkunController::simpanPengaturan,
// jadi yang lolos di sini juga lolos di server.
const akar = document.querySelector('[data-pengaturan]');

// ---- Foto profil ----
const inputFoto = akar?.querySelector('[data-input-foto]');
