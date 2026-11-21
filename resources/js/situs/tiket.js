// Tiket: penghitung jumlah dengan total yang ikut berubah, hitung mundur batas bayar, QR e-tiket, simpan PDF.
import QRCode from 'qrcode';

const rp = (n) => `Rp${Number(n).toLocaleString('id')}`;

// ---- pilih tiket ----
const pilih = document.querySelector('[data-tiket="pilih"]');
if (pilih) {
    const baris = [...pilih.querySelectorAll('[data-harga]')];
    const total = pilih.querySelector('[data-total]');
    const rincian = pilih.querySelector('[data-rincian]');
    const lanjut = pilih.querySelector('[data-lanjut]');
    const hitung = () => {
        let t = 0;
        const teks = [];
        baris.forEach((b) => {
            const n = Number(b.querySelector('input').value) || 0;
            t += n * Number(b.dataset.harga);
            if (n) teks.push(`${n} ${b.dataset.jenis}`);
        });
        total.textContent = rp(t);
        rincian.textContent = teks.length ? teks.join(', ') : 'Belum ada tiket dipilih';
        lanjut.disabled = t === 0;
    };
    baris.forEach((b) => {
        const input = b.querySelector('input');
        const kurang = b.querySelector('[data-kurang]');
        const tambah = b.querySelector('[data-tambah]');
        const min = Number(input.min) || 0;
        const maks = Number(input.max) || 10;
        // tombol mati di batas, supaya jelas jumlahnya tidak bisa turun/naik lagi
        const aturBatas = () => {
            const n = Number(input.value) || 0;
            kurang.disabled = n <= min;
            tambah.disabled = n >= maks;
        };
        const ubah = (arah) => {
            const lama = Number(input.value) || 0;
            const baru = Math.min(maks, Math.max(min, lama + arah));
            if (baru === lama) return false;
            input.value = baru;
            input.dataset.arah = arah > 0 ? 'naik' : 'turun';
            input.classList.remove('berganti');
            void input.offsetWidth; // ulang animasi angka
            input.classList.add('berganti');
            aturBatas();
            hitung();
            return true;
        };
        // tahan tombol: setelah 400 ms jumlah berubah terus, makin lama makin cepat
        const tahan = (tombol, arah) => {
            let jeda = null;
            let selang = 180;
            const henti = () => { clearTimeout(jeda); selang = 180; };
            const ulang = () => {
                if (!ubah(arah)) return henti();
                selang = Math.max(70, selang * 0.85);
                jeda = setTimeout(ulang, selang);
            };
            tombol.addEventListener('pointerdown', (e) => {
                if (e.button !== 0) return;
                ubah(arah);
                jeda = setTimeout(ulang, 400);
            });
            ['pointerup', 'pointerleave', 'pointercancel'].forEach((ev) => tombol.addEventListener(ev, henti));
            // papan ketik: Enter/Spasi memicu click tanpa pointerdown
            tombol.addEventListener('click', (e) => { if (e.detail === 0) ubah(arah); });
        };
        tahan(kurang, -1);
        tahan(tambah, 1);
        input.addEventListener('input', () => { aturBatas(); hitung(); });
        aturBatas();
    });
    hitung();
}

// ---- hitung mundur batas bayar ----
const mundur = document.querySelector('[data-batas]');
if (mundur) {
    const batas = new Date(mundur.dataset.batas).getTime();
    const tik = () => {
        const sisa = Math.max(0, Math.floor((batas - Date.now()) / 1000));
        mundur.textContent = `${String(Math.floor(sisa / 60)).padStart(2, '0')}:${String(sisa % 60).padStart(2, '0')}`;
        if (sisa === 0) {
            clearInterval(jam);
            location.reload();
        }
    };
    const jam = setInterval(tik, 1000);
    tik();
}

// ---- QR e-tiket dan QRIS ----
document.querySelectorAll('[data-qr]').forEach(async (el) => {
    try {
        el.innerHTML = await QRCode.toString(el.dataset.qr, { type: 'svg', errorCorrectionLevel: 'M', margin: 1, color: { dark: '#0f3b2e', light: '#ffffff' } });
        el.querySelector('svg')?.setAttribute('role', 'img');
        el.querySelector('svg')?.setAttribute('aria-label', el.dataset.qrLabel || 'Kode QR');
    } catch {
        el.textContent = 'Kode QR tidak bisa dibuat. Tunjukkan kode pemesanan di bawahnya.';
    }
});

// ---- pembayaran Midtrans Snap ----
// Tanpa snap.js, form dikirim biasa dan server mengalihkan ke halaman Midtrans.
const bayar = document.querySelector('[data-bayar-midtrans]');

if (bayar) {
    const formBayar = bayar.querySelector('[data-form-bayar]');
    const tombolBayar = formBayar.querySelector('button');
    const kabar = bayar.querySelector('[data-kabar-bayar]');
    const panelQris = bayar.querySelector('[data-qris-sandbox]');
    const templatSukses = document.querySelector('[data-templat-sukses]');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const jeda = (ms) => new Promise((selesai) => setTimeout(selesai, ms));

    let pantau = null;
    let detik = 0;
    let sedangCek = false;
    let konfirmasiJalan = false;
    let lapis = null;

    const tulisKabar = (teks) => {
        kabar.hidden = !teks;
        kabar.textContent = teks;
    };

    const minta = async (url, method = 'GET') => {
        const r = await fetch(url, {
            method,
            headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
            cache: 'no-store',
        });
        const data = await r.json().catch(() => ({}));
        if (!r.ok) throw new Error(data.pesan || 'Permintaan ke server gagal. Coba lagi.');
        if (!r.ok) {
            throw new Error(data.pesan || 'Permintaan ke server gagal. Coba lagi.');
        }

        return data;
    };

    const cekStatusLokal = async () => {
        if (sedangCek || document.hidden) return;

        sedangCek = true;

        try {
            const r = await fetch(bayar.dataset.statusUrl, {
                method: 'GET',
                headers: {
                    Accept: 'application/json',
                },
                cache: 'no-store',
            });

            if (!r.ok) return;

            const data = await r.json();

            if (data.status && data.status !== 'menunggu') {
                clearInterval(pantau);
                pantau = null;
                location.reload();
            }
        } catch {
            // Pengecekan dilanjutkan pada interval berikutnya.
        } finally {
            sedangCek = false;
        }
    };

    const mulaiPantau = () => {
        if (pantau) return;

        cekStatusLokal();

        pantau = setInterval(() => {
            cekStatusLokal();
        }, 1000);
    };

    formBayar.addEventListener('submit', async (e) => {
        if (!window.snap) return;

        e.preventDefault();
        tombolBayar.disabled = true;
        tulisKabar('Membuka jendela pembayaran...');

        try {
            const data = await kirim(formBayar.action);

            if (!data.token) {
                location.href = data.url || location.href;
                return;
            }

            tulisKabar('');

            window.snap.pay(data.token, {
                onSuccess: () => {
                    tulisKabar('Pembayaran diterima. Menerbitkan e-tiket...');
                    mulaiPantau();
                },

                onPending: () => {
                    tulisKabar('Pembayaran sedang menunggu konfirmasi. Halaman akan diperbarui otomatis.');
                    mulaiPantau();
                },

                onError: () => {
                    tulisKabar('Pembayaran gagal diproses. Coba lagi atau pilih cara bayar lain.');
                },

                onClose: () => {
                    tulisKabar('Jendela pembayaran ditutup. Jika kamu sudah membayar, tunggu konfirmasi.');
                },
            });
        } catch (err) {
            tulisKabar(err.message);
        } finally {
            tombolBayar.disabled = false;
        }
    });
}
