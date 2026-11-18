// Panel Sampul beranda: pratinjau setiap slot ikut berubah saat tempat dipilih atau foto dipilih,
// sebelum disimpan. Tanpa skrip, form tetap bekerja dan pratinjau diperbarui setelah simpan.
document.querySelectorAll('[data-sampul-atur] [data-slot]').forEach((slot) => {
    const pilih = slot.querySelector('[data-pilih-tempat]');
