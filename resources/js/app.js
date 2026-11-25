// MedanVisit: titik masuk skrip. Modul berat (peta, graf, QR) hanya dimuat di halaman yang memakainya.
import './situs/dasar.js';
import { mulaiLokasi } from './situs/lokasi.js';

const ada = (sel) => document.querySelector(sel);

if (ada('[data-dekat]') || ada('[data-lokasi-saya]')) mulaiLokasi();

if (ada('[data-peta-koordinat]')) {
    import('./admin/peta-koordinat.js')
        .catch((error) => {
            console.error(
                'Gagal memuat modul peta admin:',
                error
            );
        });
}
if (ada('[data-panggung]')) import('./situs/panggung.js');
if (ada('[data-peta]')) import('./situs/peta.js');
if (ada('[data-dekat]')) import('./situs/dekat.js');
if (ada('[data-hubungi-admin]')) import('./situs/hubungi-admin.js');
if (ada('[data-pengaturan]')) import('./situs/pengaturan.js');
if (ada('[data-sampul-atur]')) import('./admin/sampul-beranda.js');
if (ada('[data-rencana-form]')) import('./situs/rencana.js');
if (ada('[data-checkin]')) import('./situs/checkin.js');
if (ada('[data-pemindai-tiket]')) {
    import('./panel/pemindai-tiket.js').catch((error) => {
        console.error('Gagal memuat pemindai QR tiket:', error);
    });
}
if (ada('[data-lorong]')) import('./situs/lorong.js');
if (ada('[data-tiket]')) import('./situs/tiket.js');
if (ada('[data-sparql]')) import('./situs/sparql.js');
if (ada('[data-panel-app]')) import('./panel/panel.js');
