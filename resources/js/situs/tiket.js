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

        return data;
    };

    // Layar sukses sudah ada di halaman (template), jadi tampil seketika tanpa memuat ulang halaman.
    const tampilkanSukses = () => {
        if (lapis || !templatSukses) return lapis;
        lapis = templatSukses.content.firstElementChild.cloneNode(true);
        document.body.append(lapis);
        document.documentElement.style.overflow = 'hidden';
        lapis.querySelector('#judul-sukses')?.focus();

        return lapis;
    };

    // Pesanan sudah lunas di database: tombol e-tiket aktif.
    const tandaiSiap = () => {
        const l = tampilkanSukses();
        if (!l) {
            location.reload();
            return;
        }
        const judul = l.querySelector('[data-judul-siap]');
        if (judul) judul.textContent = judul.dataset.judulSiap;
        l.querySelector('[data-proses-etiket]')?.remove();
        l.querySelector('[data-tombol-etiket]')?.removeAttribute('aria-disabled');
    };

    const hentikanPantau = () => {
        clearInterval(pantau);
        pantau = null;
    };

    // true kalau pesanan tidak lagi menunggu pembayaran
    const terapkanStatus = (status) => {
        if (!status || status === 'menunggu') return false;
        hentikanPantau();
        if (status === 'lunas') tandaiSiap();
        else location.reload();

        return true;
    };

    // Setelah Midtrans melaporkan berhasil: server langsung menanyakan status ke API Midtrans,
    // tanpa menunggu notifikasi, lalu menerbitkan e-tiket.
    const konfirmasi = async () => {
        if (konfirmasiJalan) return;
        konfirmasiJalan = true;
        for (let i = 0; i < 20; i++) {
            try {
                if (terapkanStatus((await minta(bayar.dataset.cekUrl, 'POST')).status)) return;
            } catch {
                // dicoba lagi sedetik kemudian
            }
            await jeda(1000);
        }
        location.reload();
    };

    // Mode sandbox: tampilkan URL gambar QRIS untuk ditempel di simulator Midtrans.
    const muatQris = async () => {
        if (!panelQris || !panelQris.hidden) return;
        try {
            const data = await minta(panelQris.dataset.qrisUrl);
            if (!data.url) return;
            panelQris.querySelector('[data-qris-gambar]').src = data.url;
            panelQris.querySelector('[data-qris-teks]').value = data.url;
            panelQris.querySelector('[data-salin]').dataset.salin = data.url;
            panelQris.hidden = false;
        } catch {
            // dicoba lagi pada putaran berikutnya
        }
    };

    // Pembayaran di luar jendela Midtrans (VA, QRIS): status di database dicek tiap detik, dan tiap
    // 5 detik ditanyakan langsung ke Midtrans supaya tetap jalan walau notifikasi belum diatur.
    const tik = async () => {
        if (sedangCek || document.hidden) return;
        sedangCek = true;
        detik += 1;
        try {
            const url = detik % 5 === 0 ? bayar.dataset.cekUrl : bayar.dataset.statusUrl;
            const data = await minta(url, detik % 5 === 0 ? 'POST' : 'GET');
            if (!terapkanStatus(data.status) && detik % 10 === 3) muatQris();
        } catch {
            // dicoba lagi pada putaran berikutnya
        } finally {
            sedangCek = false;
        }
    };

    const mulaiPantau = () => {
        if (pantau) return;
        pantau = setInterval(tik, 1000);
        tik();
    };

    formBayar.addEventListener('submit', async (e) => {
        if (!window.snap) return;

        e.preventDefault();
        tombolBayar.disabled = true;
        tulisKabar('Membuka jendela pembayaran...');

        try {
            const data = await minta(formBayar.action, 'POST');

            if (!data.token) {
                location.href = data.url || location.href;
                return;
            }

            tulisKabar('');

            window.snap.pay(data.token, {
                onSuccess: () => {
                    tampilkanSukses();
                    konfirmasi();
                },
                onPending: () => {
                    tulisKabar('Selesaikan pembayaran sesuai petunjuk Midtrans. Halaman ini diperbarui otomatis setelah pembayaran diterima.');
                    muatQris();
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
