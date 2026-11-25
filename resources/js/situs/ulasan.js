const tombol = document.querySelector('[data-ulasan-muat]');
const daftar = document.querySelector('[data-ulasan-daftar]');
const status = document.querySelector('[data-ulasan-status]');

if (tombol && daftar && status) {
    let offset = Number.parseInt(tombol.dataset.offset ?? '0', 10);

    tombol.addEventListener('click', async () => {
        tombol.disabled = true;
        tombol.textContent = 'Memuat ulasan…';
        status.textContent = '';

        try {
            const url = new URL(tombol.dataset.url, window.location.origin);
            url.searchParams.set('offset', String(offset));
            const response = await fetch(url, {
                headers: { Accept: 'application/json' },
            });
            if (!response.ok) throw new Error(String(response.status));

            const hasil = await response.json();
            daftar.insertAdjacentHTML('beforeend', hasil.html);
            offset = hasil.nextOffset;
            tombol.dataset.offset = String(offset);
            tombol.hidden = !hasil.hasMore;
            if (hasil.hasMore) tombol.textContent = 'Muat ulasan lainnya';
            status.textContent = 'Ulasan terbaru berhasil dimuat.';
        } catch {
            tombol.textContent = 'Coba muat lagi';
            status.textContent = 'Ulasan lainnya belum bisa dimuat. Coba lagi.';
        } finally {
            tombol.disabled = false;
        }
    });
}
