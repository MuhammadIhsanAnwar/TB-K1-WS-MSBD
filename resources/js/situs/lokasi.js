const KUNCI_TUNDA = 'mv-lokasi-ditunda';
const MASA_TUNDA = 7 * 24 * 60 * 60 * 1000;

const pengamat = new Set();
let statusTerakhir = { keadaan: 'diam' };
let permintaanAktif = false;
let sudahDimulai = false;

function siarkan(status) {
    statusTerakhir = status;
    pengamat.forEach((dengar) => dengar(status));
}

function tunda() {
    try {
        return Date.now() - Number(localStorage.getItem(KUNCI_TUNDA) || 0) < MASA_TUNDA;
    } catch (error) {
        console.warn('Status penundaan izin lokasi tidak dapat dibaca.', error);
        return false;
    }
}

function simpanPenundaan() {
    try {
        localStorage.setItem(KUNCI_TUNDA, String(Date.now()));
    } catch (error) {
        console.warn('Status penundaan izin lokasi tidak dapat disimpan.', error);
    }
}

function hapusPenundaan() {
    try {
        localStorage.removeItem(KUNCI_TUNDA);
    } catch (error) {
        console.warn('Status penundaan izin lokasi tidak dapat dihapus.', error);
    }
}

async function statusIzin() {
    if (!navigator.permissions?.query) return null;

    try {
        return (await navigator.permissions.query({ name: 'geolocation' })).state;
    } catch (error) {
        console.warn('Status izin lokasi tidak dapat dibaca dari peramban.', error);
        return null;
    }
}

export function pantauLokasi(dengar) {
    pengamat.add(dengar);
    if (statusTerakhir.keadaan !== 'diam') dengar(statusTerakhir);

    return () => pengamat.delete(dengar);
}

async function tanganiKegagalan(error) {
    if (error.code !== 1) {
        siarkan({ keadaan: 'gagal' });
        return;
    }

    const izin = await statusIzin();
    if (izin === 'denied' || izin === null) {
        siarkan({ keadaan: 'ditolak' });
        return;
    }

    simpanPenundaan();
    siarkan({ keadaan: 'ditunda' });
}

export function mintaLokasi() {
    if (!navigator.geolocation) {
        siarkan({ keadaan: 'tidak-didukung' });
        return;
    }
    if (permintaanAktif) return;

    permintaanAktif = true;
    siarkan({ keadaan: 'meminta' });

    try {
        navigator.geolocation.getCurrentPosition(
            (posisi) => {
                permintaanAktif = false;
                hapusPenundaan();
                siarkan({ keadaan: 'berhasil', posisi });
            },
            (error) => {
                permintaanAktif = false;
                void tanganiKegagalan(error);
            },
            { enableHighAccuracy: false, timeout: 15000, maximumAge: 300000 }
        );
    } catch (error) {
        permintaanAktif = false;
        console.error('Permintaan lokasi gagal dimulai.', error);
        siarkan({ keadaan: 'gagal' });
    }
}

export async function mulaiLokasi() {
    if (sudahDimulai || !document.body?.classList.contains('situs')) return;
    sudahDimulai = true;

    if (!navigator.geolocation) {
        siarkan({ keadaan: 'tidak-didukung' });
        return;
    }

    const izin = await statusIzin();
    if (izin === 'denied') {
        siarkan({ keadaan: 'ditolak' });
        return;
    }
    if (izin !== 'granted' && tunda()) {
        siarkan({ keadaan: 'ditunda' });
        return;
    }

    mintaLokasi();
}
