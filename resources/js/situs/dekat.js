// Beranda: sarankan tiga tempat wisata terdekat dari lokasi pengunjung.
import { bukaPada, jarakMeter, waktuWib } from './jadwal.js';
import { mintaLokasi, pantauLokasi } from './lokasi.js';

const blok = document.querySelector('[data-dekat]');
const tempat = JSON.parse(blok.dataset.dekat);
const status = blok.querySelector('[data-dekat-status]');
const daftar = blok.querySelector('[data-dekat-daftar]');
const tombol = blok.querySelector('[data-dekat-minta]');
const tautanPeta = blok.querySelector('[data-dekat-peta]');

const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
const fmtJarak = (m) => (m < 1000 ? `${m.toLocaleString('id')} m` : `${(m / 1000).toLocaleString('id', { maximumFractionDigits: 1 })} km`);

function tawarkanTombol(teks) {
    blok.hidden = false;
    blok.removeAttribute('aria-busy');
    status.textContent = teks;
    tombol.hidden = false;
}

function tampilkan(pos) {
    const lokasi = { lat: pos.coords.latitude, lng: pos.coords.longitude };
    const kini = waktuWib();
    const terdekat = tempat
        .map((t) => ({ ...t, jarak: jarakMeter(lokasi, t) }))
        .sort((a, b) => a.jarak - b.jarak)
        .slice(0, 3);
    status.textContent = terdekat[0].jarak > 30000
        ? 'Kamu sedang di luar Medan. Ini tempat yang paling dekat dari posisimu.'
        : 'Tiga tempat terdekat dari lokasimu sekarang.';
    daftar.innerHTML = terdekat.map((t) => {
        const jam = t.buka
            ? `<span class="mv-lencana-jam ${bukaPada(t.buka, kini) ? 'mv-lencana-jam--buka">Buka' : 'mv-lencana-jam--tutup">Tutup'}</span>`
            : '';
        return `<li class="mv-dekat__butir"><a href="${esc(t.url)}">${esc(t.nama)}</a><span class="mv-dekat__meta">${esc(t.kategori)}, ${fmtJarak(t.jarak)}</span>${jam}</li>`;
    }).join('');
    daftar.hidden = false;
    tautanPeta.hidden = false;
    tombol.hidden = true;
    blok.removeAttribute('aria-busy');
}

tombol.addEventListener('click', mintaLokasi);

pantauLokasi(({ keadaan, posisi }) => {
    if (keadaan === 'meminta') {
        blok.hidden = false;
        tombol.hidden = true;
        blok.setAttribute('aria-busy', 'true');
        status.textContent = 'Meminta akses lokasi untuk mencari tempat terdekat. Lokasi digunakan di peramban ini dan tidak dikirim ke server.';
    } else if (keadaan === 'berhasil') {
        tampilkan(posisi);
    } else if (keadaan === 'ditunda') {
        tawarkanTombol('Izinkan lokasi untuk melihat tempat wisata terdekat.');
    } else if (keadaan === 'gagal') {
        tawarkanTombol('Lokasimu belum terbaca.');
    } else {
        blok.hidden = true;
        blok.removeAttribute('aria-busy');
    }
});
