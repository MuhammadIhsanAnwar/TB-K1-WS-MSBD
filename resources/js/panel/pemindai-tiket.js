import { BrowserMultiFormatReader } from '@zxing/browser';

const pemindai = document.querySelector('[data-pemindai-tiket]');

if (pemindai) {
    const form = pemindai.closest('form');
    const input = form?.querySelector('#kode');
    const mulai = pemindai.querySelector('[data-pemindai-mulai]');
    const berhenti = pemindai.querySelector('[data-pemindai-berhenti]');
    const panel = pemindai.querySelector('[data-pemindai-panel]');
    const video = pemindai.querySelector('[data-pemindai-video]');
    const status = pemindai.querySelector('[data-pemindai-status]');
    const reader = new BrowserMultiFormatReader();
    let kontrol = null;
    let terbaca = false;

    const hentikan = (pesan = '') => {
        kontrol?.stop();
        kontrol = null;
        panel.hidden = true;
        mulai.disabled = false;
        if (pesan) status.textContent = pesan;
    };

    mulai.addEventListener('click', async () => {
        terbaca = false;
        mulai.disabled = true;
        panel.hidden = false;
        status.textContent = 'Mengaktifkan kamera...';

        try {
            kontrol = await reader.decodeFromVideoDevice(undefined, video, (hasil) => {
                if (!hasil || terbaca) return;

                const kode = hasil.getText().trim();
                if (!kode) return;

                terbaca = true;
                input.value = kode;
                status.textContent = 'QR terdeteksi. Memeriksa tiket...';
                kontrol?.stop();
                form.requestSubmit();
            });

            if (terbaca) {
                kontrol.stop();
            } else {
                status.textContent = 'Arahkan kamera ke QR e-tiket.';
            }
        } catch (error) {
            console.error('Kamera pemindai tiket tidak dapat dibuka:', error);
            hentikan('Kamera tidak dapat dibuka. Izinkan akses kamera dan gunakan HTTPS, atau masukkan ID QR secara manual.');
        }
    });

    berhenti.addEventListener('click', () => hentikan('Pemindaian dihentikan.'));
    window.addEventListener('pagehide', () => kontrol?.stop(), { once: true });
}
